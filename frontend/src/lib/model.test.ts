import { describe, expect, it } from 'vitest'
import { buildRooms, buildSchedules, buildSettings } from '@/mocks/data'
import { emptyDevice, emptyRoomForm, fromRoomForm, toRoomForm } from './rooms'
import {
  addSlot,
  copyDay,
  daySegments,
  hasDuplicateStarts,
  newDraft,
  sortedSlots,
  splitSlot,
  startMinute,
  toDraft,
  toInput,
} from './schedule'
import { fromSettingsForm, toSettingsForm } from './settings'

const keys = ['comfort', 'eco', 'night', 'away', 'extra']

describe('room form', () => {
  it('converts seconds to minutes and aligns preset rows', () => {
    const room = buildRooms()[0]
    const form = toRoomForm(room, keys)
    expect(form.vacancyDelayMinutes).toBe(20)
    expect(form.windowCutDelayMinutes).toBe(2)
    expect(form.devices[0]).toMatchObject({ minOnMinutes: 10, minOffMinutes: 5, hysteresis: 0.3 })
    expect(form.presetTemperatures.map((p) => p.preset)).toEqual(keys)
    expect(form.presetTemperatures[4]).toEqual({ preset: 'extra', heat: null, cool: null })
  })

  it('round-trips and drops empty preset rows', () => {
    const room = buildRooms()[0]
    const input = fromRoomForm(toRoomForm(room, keys))
    expect(input.vacancyDelaySeconds).toBe(1200)
    expect(input.devices[0].minOnSeconds).toBe(600)
    expect(input.presetTemperatures).toHaveLength(4)
  })

  it('keeps fractional minutes and trims the name', () => {
    const form = emptyRoomForm(keys)
    form.name = '  Garage '
    form.vacancyDelayMinutes = 1.5
    form.devices.push({ ...emptyDevice('climate.x'), minOnMinutes: 0.5 })
    const input = fromRoomForm(form)
    expect(input.name).toBe('Garage')
    expect(input.vacancyDelaySeconds).toBe(90)
    expect(input.devices[0]).toMatchObject({ minOnSeconds: 30, role: 'heating' })
  })

  it('has sensible defaults for a new room', () => {
    const form = emptyRoomForm(['comfort'])
    expect(form).toMatchObject({
      vacancyDelayMinutes: 20,
      windowCutDelayMinutes: 2,
      windowNotifyDelayMinutes: 30,
      preheatEnabled: true,
      devices: [],
    })
  })

  it('maps missing optional fields to null', () => {
    const room = { ...buildRooms()[0] }
    delete room.areaId
    delete room.presenceSensor
    delete room.heatingScheduleId
    delete room.coolingScheduleId
    const form = toRoomForm(room, keys)
    expect([
      form.areaId,
      form.presenceSensor,
      form.heatingScheduleId,
      form.coolingScheduleId,
    ]).toEqual([null, null, null, null])
  })
})

describe('schedule model', () => {
  const draft = () => toDraft(buildSchedules()[0])

  it('draws slots until the next start of the same day', () => {
    const segments = daySegments(draft().slots, 1)
    expect(segments.map((s) => [s.start, s.end])).toEqual([
      [0, 390],
      [390, 510],
      [510, 1050],
      [1050, 1350],
      [1350, 1440],
    ])
  })

  it('uses the fallback minute of anchored slots', () => {
    const anchored = draft().slots.find((s) => s.start.type === 'anchored')
    expect(anchored && startMinute(anchored.start)).toBe(1350)
  })

  it('serialises sorted and without local ids', () => {
    const d = draft()
    d.slots.reverse()
    d.name = ' Semaine '
    const input = toInput(d)
    expect(input.name).toBe('Semaine')
    expect(input.slots[0]).toEqual({
      weekday: 1,
      start: { type: 'fixed', minuteOfDay: 0 },
      preset: 'night',
    })
    expect(sortedSlots(d.slots)[0].weekday).toBe(1)
    expect('uid' in input.slots[0]).toBe(false)
  })

  it('splits a slot in the middle, rounded to 5 minutes', () => {
    const d = draft()
    const first = d.slots[0]
    const created = splitSlot(d.slots, first)
    expect(created?.start).toEqual({ type: 'fixed', minuteOfDay: 195 })
    expect(splitSlot(d.slots, { ...first, uid: -1 })).toBeNull()
  })

  it('refuses to split a slot that is too short', () => {
    const d = newDraft('heat', 'eco')
    const slot = d.slots[0]
    slot.start = { type: 'fixed', minuteOfDay: 1438 }
    expect(splitSlot(d.slots, slot)).toBeNull()
  })

  it('adds a slot by splitting the longest segment, or at midnight on an empty day', () => {
    const d = draft()
    const created = addSlot(d.slots, 1, 'eco')
    expect(created?.start).toEqual({ type: 'fixed', minuteOfDay: 780 })
    d.slots = d.slots.filter((s) => s.weekday !== 2)
    expect(addSlot(d.slots, 2, 'eco')?.start).toEqual({ type: 'fixed', minuteOfDay: 0 })
  })

  it('copies a day over other days with fresh ids', () => {
    const d = draft()
    const copied = copyDay(d.slots, 1, [6, 7])
    expect(daySegments(copied, 6)).toHaveLength(5)
    expect(daySegments(copied, 7).map((s) => s.start)).toEqual([0, 390, 510, 1050, 1350])
    expect(new Set(copied.map((s) => s.uid)).size).toBe(copied.length)
    expect(daySegments(copied, 2)).toHaveLength(5)
  })

  it('detects slots starting together', () => {
    const d = draft()
    expect(hasDuplicateStarts(d.slots)).toBe(false)
    d.slots[1].start = { type: 'fixed', minuteOfDay: 0 }
    expect(hasDuplicateStarts(d.slots)).toBe(true)
  })

  it('creates a one-slot-per-day draft', () => {
    const d = newDraft('cool', 'eco')
    expect(d.slots).toHaveLength(7)
    expect(d.mode).toBe('cool')
  })
})

describe('settings form', () => {
  it('converts delays and dates, and round-trips', () => {
    const settings = buildSettings()
    settings.vacation = {
      enabled: true,
      startAt: '2026-10-31T08:00:00Z',
      returnAt: '2026-11-08T17:00:00Z',
      offDuringVacation: false,
    }
    const form = toSettingsForm(settings, 'Europe/Paris')
    expect(form.houseEmptyDelayMinutes).toBe(15)
    expect(form.vacation.returnAt).toBe('2026-11-08T18:00')
    const back = fromSettingsForm(form, 'Europe/Paris')
    expect(back.houseEmptyDelaySeconds).toBe(900)
    expect(back.vacation.returnAt).toBe('2026-11-08T18:00:00+01:00')
    expect('auditEntryCount' in back).toBe(false)
  })

  it('keeps empty vacation dates empty', () => {
    const form = toSettingsForm(buildSettings(), 'Europe/Paris')
    expect(form.vacation.startAt).toBe('')
    expect(fromSettingsForm(form, 'Europe/Paris').vacation.startAt).toBeNull()
  })
})
