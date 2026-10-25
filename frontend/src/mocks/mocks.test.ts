import { describe, expect, it, vi } from 'vitest'
import { api, unwrap } from '@/api/client'
import { ApiError } from '@/api/errors'
import type { RoomInput, ScheduleInput } from '@/api/types'
import { buildRooms } from './data'
import { ID } from './data'
import { allStatuses, createMockDb, mockDb, newId, statusBus } from './db'
import { FakeEventSource } from './fakeEventSource'
import { MockLiveEventSource } from './live'
import { validateRoom, validateSchedule } from './handlers'

const status = async <T>(call: Promise<T>): Promise<number> =>
  call.then(() => 200).catch((e: unknown) => (e instanceof ApiError ? e.status : -1))

const roomInput = (): RoomInput => {
  const { id: _id, position: _p, learnedRates: _l, ...input } = buildRooms()[0]
  void _id
  void _p
  void _l
  return input
}

describe('mock database', () => {
  it('hands out unique ids and resets', () => {
    expect(newId()).not.toBe(newId())
    expect(createMockDb(0).rooms).toHaveLength(6)
  })

  it('derives a status for every season', () => {
    const rooms = mockDb.rooms
    mockDb.settings.season = 'summer'
    const summer = allStatuses()
    expect(summer[0].decision).toMatchObject({ mode: 'cool', target: 24 })
    expect(summer.every((s) => !s.paused)).toBe(true)
    expect(rooms).toHaveLength(6)
  })

  it('flags every status as a simulation while paused, decisions unchanged', () => {
    const now = Date.now()
    const before = allStatuses(mockDb, now).map((s) => s.decision)
    mockDb.settings.paused = true
    const paused = allStatuses(mockDb, now)
    expect(paused.every((s) => s.paused)).toBe(true)
    expect(paused.map((s) => s.decision)).toEqual(before)
  })

  it('computes a status for a room created at runtime (default scenario)', () => {
    const fresh = { ...mockDb.rooms[0], id: 'brand-new' }
    mockDb.rooms.push(fresh)
    expect(allStatuses().at(-1)?.roomId).toBe('brand-new')
  })

  it('publishes statuses on the bus until unsubscribed', () => {
    const seen = vi.fn()
    const off = statusBus.subscribe(seen)
    statusBus.emit(allStatuses()[0])
    off()
    statusBus.emit(allStatuses()[0])
    expect(seen).toHaveBeenCalledOnce()
  })
})

describe('fake event sources', () => {
  it('lets tests drive open, messages and errors', () => {
    const source = new FakeEventSource('./api/live')
    const open = vi.fn()
    const message = vi.fn()
    source.onopen = open
    source.addEventListener('room', message)
    source.open()
    source.emit('room', '{"a":1}')
    source.emit('other', '{}')
    expect(open).toHaveBeenCalled()
    expect(message).toHaveBeenCalledOnce()
    expect(source.readyState).toBe(1)
    source.close()
    expect(source.readyState).toBe(2)
    expect(FakeEventSource.last).toBe(source)
  })

  it('replays a snapshot then bus updates, and stops after close', async () => {
    vi.useFakeTimers()
    const source = new MockLiveEventSource('./api/live')
    const received: string[] = []
    source.addEventListener('room', (ev) => received.push(ev.data))
    vi.advanceTimersByTime(1)
    expect(source.readyState).toBe(1)
    expect(received).toHaveLength(6)
    statusBus.emit(allStatuses()[0])
    expect(received).toHaveLength(7)
    source.close()
    statusBus.emit(allStatuses()[0])
    expect(received).toHaveLength(7)

    const closedEarly = new MockLiveEventSource('./api/live')
    closedEarly.close()
    vi.advanceTimersByTime(1)
    expect(closedEarly.readyState).toBe(2)
  })
})

describe('mock API contract behaviour', () => {
  it('validates rooms and schedules', () => {
    expect(validateRoom(roomInput())).toEqual([])
    const bad = roomInput()
    bad.name = ' '
    bad.vacancyDelaySeconds = 999999
    bad.windowCutDelaySeconds = 9999
    bad.windowNotifyDelaySeconds = 999999
    bad.preheatMaxMinutes = 9999
    bad.devices[0].hysteresis = 5
    bad.devices[0].minOffSeconds = 9000
    bad.presetTemperatures[0].cool = 99
    expect(validateRoom(bad).map((v) => v.path)).toEqual([
      'name',
      'vacancyDelaySeconds',
      'windowCutDelaySeconds',
      'windowNotifyDelaySeconds',
      'preheatMaxMinutes',
      'devices[0].minOffSeconds',
      'devices[0].hysteresis',
      'presetTemperatures[0].cool',
    ])
    const schedule: ScheduleInput = {
      name: '',
      mode: 'heat',
      slots: [{ weekday: 1, preset: 'zzz', start: { type: 'fixed', minuteOfDay: 5000 } }],
    }
    expect(validateSchedule(schedule).map((v) => v.path)).toEqual([
      'name',
      'slots[0].preset',
      'slots[0].start.minuteOfDay',
    ])
    expect(validateSchedule({ ...schedule, slots: [] }).map((v) => v.path)).toContain('slots')
  })

  it('answers 404 for unknown resources', async () => {
    const unknown = '99999999-0000-4000-8000-000000000000'
    const path = { params: { path: { roomId: unknown } } }
    expect(await status(unwrap(api.GET('/api/rooms/{roomId}', path)))).toBe(404)
    expect(
      await status(unwrap(api.PUT('/api/rooms/{roomId}', { ...path, body: roomInput() }))),
    ).toBe(404)
    expect(await status(unwrap(api.DELETE('/api/rooms/{roomId}', path)))).toBe(404)
    expect(
      await status(
        unwrap(
          api.POST('/api/rooms/{roomId}/boost', {
            ...path,
            body: { delta: 1, untilNextSlot: true },
          }),
        ),
      ),
    ).toBe(404)
    expect(await status(unwrap(api.DELETE('/api/rooms/{roomId}/override', path)))).toBe(404)
    const schedulePath = { params: { path: { scheduleId: unknown } } }
    expect(await status(unwrap(api.GET('/api/schedules/{scheduleId}', schedulePath)))).toBe(404)
    expect(await status(unwrap(api.DELETE('/api/schedules/{scheduleId}', schedulePath)))).toBe(404)
    expect(
      await status(
        unwrap(
          api.PUT('/api/schedules/{scheduleId}', {
            ...schedulePath,
            body: { name: 'x', mode: 'heat', slots: [] },
          }),
        ),
      ),
    ).toBe(404)
    expect(
      await status(
        unwrap(
          api.POST('/api/schedules/{scheduleId}/duplicate', {
            ...schedulePath,
            body: { name: 'x' },
          }),
        ),
      ),
    ).toBe(404)
    const presetPath = { params: { path: { presetKey: 'zzz' } } }
    expect(
      await status(
        unwrap(
          api.PUT('/api/presets/{presetKey}', {
            ...presetPath,
            body: { key: 'zzz', label: 'Z', color: '#000000', position: 9 },
          }),
        ),
      ),
    ).toBe(404)
    expect(await status(unwrap(api.DELETE('/api/presets/{presetKey}', presetPath)))).toBe(404)
  })

  it('validates boosts, season and pagination', async () => {
    const path = { params: { path: { roomId: ID.salon } } }
    expect(
      await status(
        unwrap(
          api.POST('/api/rooms/{roomId}/boost', {
            ...path,
            body: { delta: 1.2, durationMinutes: 30 },
          }),
        ),
      ),
    ).toBe(422)
    expect(
      await status(unwrap(api.POST('/api/rooms/{roomId}/boost', { ...path, body: { delta: 1 } }))),
    ).toBe(422)
    mockDb.settings.paused = true
    expect(
      await status(
        unwrap(
          api.POST('/api/rooms/{roomId}/boost', {
            ...path,
            body: { delta: 1, durationMinutes: 30 },
          }),
        ),
      ),
    ).toBe(409)
    expect(await status(unwrap(api.DELETE('/api/rooms/{roomId}/override', path)))).toBe(409)
    expect(
      await status(unwrap(api.PUT('/api/season', { body: { season: 'spring' as never } }))),
    ).toBe(422)
    expect(await status(unwrap(api.PUT('/api/season', { body: { season: 'off' as never } })))).toBe(
      422,
    )
    expect(await status(unwrap(api.GET('/api/audit', { params: { query: { limit: 500 } } })))).toBe(
      422,
    )
  })

  it('validates presets and settings', async () => {
    const preset = { key: 'comfort', label: 'Again', color: '#000000', position: 9 }
    expect(await status(unwrap(api.POST('/api/presets', { body: preset })))).toBe(409)
    expect(
      await status(unwrap(api.POST('/api/presets', { body: { ...preset, key: 'Bad Key' } }))),
    ).toBe(422)
    expect(
      await status(
        unwrap(
          api.PUT('/api/presets/{presetKey}', {
            params: { path: { presetKey: 'eco' } },
            body: { ...preset, key: 'eco', label: ' ' },
          }),
        ),
      ),
    ).toBe(422)
    const settings = { ...mockDb.settings }
    expect(
      await status(
        unwrap(api.PUT('/api/settings', { body: { ...settings, manualOverrideMinutes: 0 } })),
      ),
    ).toBe(422)
    expect(
      await status(
        unwrap(api.PUT('/api/settings', { body: { ...settings, auditRetentionDays: 0 } })),
      ),
    ).toBe(422)
  })

  it('validates schedules, duplicates and notifications', async () => {
    expect(
      await status(
        unwrap(api.POST('/api/schedules', { body: { name: '', mode: 'heat', slots: [] } })),
      ),
    ).toBe(422)
    expect(
      await status(
        unwrap(
          api.PUT('/api/schedules/{scheduleId}', {
            params: { path: { scheduleId: ID.semaine } },
            body: { name: '', mode: 'heat', slots: [] },
          }),
        ),
      ),
    ).toBe(422)
    expect(
      await status(
        unwrap(
          api.POST('/api/schedules/{scheduleId}/duplicate', {
            params: { path: { scheduleId: ID.semaine } },
            body: { name: ' ' },
          }),
        ),
      ),
    ).toBe(422)
    const notifications = {
      targets: [],
      types: [
        {
          type: 'window_open' as const,
          enabled: true,
          delaySeconds: 99999,
          persistentNotification: false,
        },
      ],
    }
    expect(await status(unwrap(api.PUT('/api/notifications', { body: notifications })))).toBe(422)
  })

  it('creates rooms, rejects invalid ones and keeps learned rates on update', async () => {
    expect(
      await status(unwrap(api.POST('/api/rooms', { body: { ...roomInput(), name: '' } }))),
    ).toBe(422)
    expect(
      await status(
        unwrap(
          api.PUT('/api/rooms/{roomId}', {
            params: { path: { roomId: ID.salon } },
            body: { ...roomInput(), name: '' },
          }),
        ),
      ),
    ).toBe(422)
    const updated = await unwrap(
      api.PUT('/api/rooms/{roomId}', { params: { path: { roomId: ID.salon } }, body: roomInput() }),
    )
    expect(updated.learnedRates.heat?.samples).toBe(34)
    const created = await unwrap(api.POST('/api/rooms', { body: roomInput() }))
    expect(created.learnedRates).toEqual({ heat: null, cool: null })
  })

  it('exports CSV and lists alerts', async () => {
    const csv = await (await fetch(`${document.baseURI}api/audit/export?actorType=user`)).text()
    expect(csv.split('\n')[0]).toBe('time,room,actor,message')
    expect(csv.split('\n').length).toBeGreaterThan(2)
    expect(await unwrap(api.GET('/api/alerts'))).toHaveLength(2)
    expect(await unwrap(api.GET('/api/health'))).toEqual({ status: 'ok' })
    expect(await unwrap(api.GET('/api/settings'))).toMatchObject({ season: 'winter' })
  })

  it('pauses and resumes, and validates the request', async () => {
    expect(await unwrap(api.PUT('/api/pause', { body: { paused: true } }))).toEqual({
      paused: true,
    })
    expect(mockDb.settings.paused).toBe(true)
    expect((await unwrap(api.GET('/api/overview'))).paused).toBe(true)
    expect((await unwrap(api.GET('/api/status'))).every((s) => s.paused)).toBe(true)
    expect(await unwrap(api.PUT('/api/pause', { body: { paused: false } }))).toEqual({
      paused: false,
    })
    expect(await status(unwrap(api.PUT('/api/pause', { body: { paused: 'yes' as never } })))).toBe(
      422,
    )
  })

  it('reads a version 1 backup: the season off becomes winter and paused', async () => {
    const backup = await unwrap(api.GET('/api/backup'))
    expect(backup.version).toBe(2)
    const settings = { ...backup.settings }
    delete settings.paused
    await unwrap(
      api.POST('/api/backup/restore', {
        body: { ...backup, version: 1, settings: { ...settings, season: 'off' } },
      }),
    )
    expect(mockDb.settings).toMatchObject({ season: 'winter', paused: true })

    await unwrap(
      api.POST('/api/backup/restore', {
        body: { ...backup, version: 1, settings: { ...settings, season: 'summer' } },
      }),
    )
    expect(mockDb.settings).toMatchObject({ season: 'summer', paused: false })
  })

  it('restores a backup with schedule references resolved', async () => {
    const backup = await unwrap(api.GET('/api/backup'))
    expect(backup.rooms[0].heatingScheduleRef).toBe(ID.semaine)
    const counts = await unwrap(api.POST('/api/backup/restore', { body: backup }))
    expect(counts).toEqual({ rooms: 6, schedules: 4, presets: 4 })
    const [first] = await unwrap(api.GET('/api/rooms'))
    const schedules = await unwrap(api.GET('/api/schedules'))
    expect(schedules.some((s) => s.id === first.heatingScheduleId)).toBe(true)
  })
})
