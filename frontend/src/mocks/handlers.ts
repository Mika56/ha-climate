import { http, HttpResponse } from 'msw'
import type {
  ActorType,
  AuditEntry,
  Backup,
  BoostInput,
  NotificationSettings,
  Overview,
  Preset,
  Room,
  RoomInput,
  RoomStatus,
  Schedule,
  ScheduleInput,
  Season,
  Settings,
  UserPreferences,
  ValidationError,
} from '@/api/types'
import { fromRoomForm, toRoomForm } from '@/lib/rooms'
import { MINUTE, buildDiscovery } from './data'
import { allStatuses, computeStatus, mockDb as db, newId, statusBus } from './db'

type Violation = ValidationError['violations'][number]

/** Typed JSON response: the body must satisfy the schema type given at the call site. */
function json<T>(body: T, status = 200): Response {
  return HttpResponse.json(body as never, { status })
}
const fail = (status: number, error: string, message?: string): Response =>
  json({ error, message }, status)
const invalid = (violations: Violation[]): Response =>
  json<ValidationError>({ error: 'validation_failed', violations }, 422)
const noContent = (): Response => new HttpResponse(null, { status: 204 })

const outOfRange = (path: string, min: number, max: number): Violation => ({
  path,
  message: `La valeur doit être comprise entre ${min} et ${max}.`,
})

export function validateRoom(input: RoomInput): Violation[] {
  const v: Violation[] = []
  if (!input.name || input.name.trim() === '' || input.name.length > 64) {
    v.push({ path: 'name', message: 'Le nom est obligatoire (64 caractères maximum).' })
  }
  const bound = (path: string, value: number | undefined, min: number, max: number) => {
    if (value !== undefined && (value < min || value > max)) v.push(outOfRange(path, min, max))
  }
  bound('vacancyDelaySeconds', input.vacancyDelaySeconds, 0, 86400)
  bound('windowCutDelaySeconds', input.windowCutDelaySeconds, 0, 3600)
  bound('windowNotifyDelaySeconds', input.windowNotifyDelaySeconds, 0, 86400)
  bound('preheatMaxMinutes', input.preheatMaxMinutes, 0, 720)
  input.devices.forEach((d, i) => {
    bound(`devices[${i}].minOnSeconds`, d.minOnSeconds, 0, 7200)
    bound(`devices[${i}].minOffSeconds`, d.minOffSeconds, 0, 7200)
    bound(`devices[${i}].hysteresis`, d.hysteresis, 0, 2)
  })
  input.presetTemperatures.forEach((p, i) => {
    if (p.heat !== null) bound(`presetTemperatures[${i}].heat`, p.heat, 5, 35)
    if (p.cool !== null) bound(`presetTemperatures[${i}].cool`, p.cool, 5, 35)
  })
  return v
}

export function validateSchedule(input: ScheduleInput): Violation[] {
  const v: Violation[] = []
  if (!input.name || input.name.trim() === '') {
    v.push({ path: 'name', message: 'Le nom est obligatoire.' })
  }
  if (input.slots.length === 0)
    v.push({ path: 'slots', message: 'Au moins un créneau est requis.' })
  input.slots.forEach((s, i) => {
    if (!db.presets.some((p) => p.key === s.preset)) {
      v.push({ path: `slots[${i}].preset`, message: 'Preset inconnu.' })
    }
    if (s.start.type === 'fixed' && (s.start.minuteOfDay < 0 || s.start.minuteOfDay > 1439)) {
      v.push(outOfRange(`slots[${i}].start.minuteOfDay`, 0, 1439))
    }
  })
  return v
}

function emitRoom(id: string): RoomStatus | null {
  const room = db.rooms.find((r) => r.id === id)
  if (!room) return null
  const status = computeStatus(room)
  statusBus.emit(status)
  return status
}

function toRoom(input: RoomInput, id: string, position: number, previous?: Room): Room {
  return {
    ...input,
    id,
    position,
    learnedRates: previous?.learnedRates ?? { heat: null, cool: null },
  }
}

function withUsage(schedule: Schedule): Schedule {
  const usedBy = db.rooms
    .filter((r) => r.heatingScheduleId === schedule.id || r.coolingScheduleId === schedule.id)
    .map((r) => ({ roomId: r.id, roomName: r.name }))
  return { ...schedule, usedBy }
}

function buildOverview(): Overview {
  const { settings } = db
  const persons = buildDiscovery().persons.map((p) => ({
    entityId: p.entityId,
    name: p.name,
    state: p.state ?? 'unknown',
    tracked: settings.trackedPersons.includes(p.entityId),
  }))
  return {
    season: settings.season,
    paused: settings.paused,
    presence: {
      persons,
      houseEmptySince: null,
      houseEmptyDelaySeconds: settings.houseEmptyDelaySeconds,
    },
    weather: settings.weatherEntity
      ? {
          entityId: settings.weatherEntity,
          condition: 'partlycloudy',
          temperature: 12.5,
          humidity: 78,
          temperatureLow: 8,
          temperatureHigh: 14,
        }
      : null,
    vacation: settings.vacation,
    alerts: db.alerts,
    recentDecisions: db.audit
      .filter((e) => ['decision', 'override', 'opening'].includes(e.category))
      .slice(0, 5),
  }
}

function filterAudit(params: URLSearchParams): AuditEntry[] {
  const roomId = params.get('roomId')
  const actorType = params.get('actorType') as ActorType | null
  const from = params.get('from')
  const to = params.get('to')
  return db.audit.filter(
    (e) =>
      (!roomId || e.roomId === roomId) &&
      (!actorType || e.actorType === actorType) &&
      (!from || new Date(e.occurredAt) >= new Date(from)) &&
      (!to || new Date(e.occurredAt) <= new Date(to)),
  )
}

function buildBackup(): Backup {
  return {
    format: 'ha-climate-backup',
    version: 2,
    exportedAt: new Date().toISOString(),
    settings: db.settings,
    presets: db.presets,
    schedules: db.schedules.map(({ id, name, mode, slots }) => ({ ref: id, name, mode, slots })),
    rooms: db.rooms.map((room) => ({
      ...fromRoomForm(
        toRoomForm(
          room,
          db.presets.map((p) => p.key),
        ),
      ),
      heatingScheduleRef: room.heatingScheduleId ?? null,
      coolingScheduleRef: room.coolingScheduleId ?? null,
    })),
    notifications: db.notifications,
  }
}

function restoreBackup(backup: Backup): { rooms: number; schedules: number; presets: number } {
  const ids = new Map<string, string>()
  db.presets = backup.presets
  // A version 1 backup may carry the season `off` (winter + paused) and no `paused`.
  const { season, paused, ...rest } = backup.settings
  db.settings = {
    ...rest,
    season: season === 'off' ? 'winter' : season,
    paused: season === 'off' || (paused ?? false),
    auditEntryCount: db.settings.auditEntryCount,
  }
  db.schedules = backup.schedules.map(({ ref, name, mode, slots }) => {
    const id = newId()
    ids.set(ref, id)
    return { id, name, mode, slots, usedBy: [] }
  })
  db.rooms = backup.rooms.map(({ heatingScheduleRef, coolingScheduleRef, ...rest }, position) =>
    toRoom(
      {
        ...rest,
        heatingScheduleId: (heatingScheduleRef && ids.get(heatingScheduleRef)) || null,
        coolingScheduleId: (coolingScheduleRef && ids.get(coolingScheduleRef)) || null,
      },
      newId(),
      position,
    ),
  )
  db.notifications = backup.notifications
  db.overrides = {}
  return { rooms: db.rooms.length, schedules: db.schedules.length, presets: db.presets.length }
}

const csvCell = (value: string): string => `"${value.replaceAll('"', '""')}"`

export const handlers = [
  http.get('*/api/health', () => json({ status: 'ok' })),
  http.get('*/api/context', () => json({ ...db.context, preferences: db.preferences })),

  http.put('*/api/me/preferences', async ({ request }) => {
    if (db.context.user === null) {
      return fail(409, 'no_user', 'No Home Assistant user on the request.')
    }
    db.preferences = (await request.json()) as UserPreferences
    return json<UserPreferences>(db.preferences)
  }),

  http.get('*/api/overview', () => json<Overview>(buildOverview())),
  http.get('*/api/status', () => json<RoomStatus[]>(allStatuses())),
  http.get('*/api/alerts', () => json(db.alerts)),

  http.put('*/api/season', async ({ request }) => {
    const { season } = (await request.json()) as { season: Season }
    if (!['winter', 'summer'].includes(season)) {
      return invalid([{ path: 'season', message: 'Saison inconnue.' }])
    }
    db.settings.season = season
    allStatuses().forEach((s) => statusBus.emit(s))
    return json({ season })
  }),
  http.put('*/api/pause', async ({ request }) => {
    const { paused } = (await request.json()) as { paused: boolean }
    if (typeof paused !== 'boolean') {
      return invalid([{ path: 'paused', message: 'Cette valeur doit être un booléen.' }])
    }
    db.settings.paused = paused
    allStatuses().forEach((s) => statusBus.emit(s))
    return json({ paused })
  }),

  http.get('*/api/rooms', () => json<Room[]>(db.rooms)),
  http.post('*/api/rooms', async ({ request }) => {
    const input = (await request.json()) as RoomInput
    const violations = validateRoom(input)
    if (violations.length > 0) return invalid(violations)
    const room = toRoom(input, newId(), db.rooms.length)
    db.rooms.push(room)
    return json<Room>(room, 201)
  }),
  http.put('*/api/rooms/order', async ({ request }) => {
    const { roomIds } = (await request.json()) as { roomIds: string[] }
    db.rooms = roomIds
      .map((id) => db.rooms.find((r) => r.id === id))
      .filter((r): r is Room => r !== undefined)
      .map((r, position) => ({ ...r, position }))
    return noContent()
  }),
  http.get('*/api/rooms/:roomId', ({ params }) => {
    const room = db.rooms.find((r) => r.id === params.roomId)
    return room ? json<Room>(room) : fail(404, 'not_found')
  }),
  http.put('*/api/rooms/:roomId', async ({ params, request }) => {
    const index = db.rooms.findIndex((r) => r.id === params.roomId)
    if (index < 0) return fail(404, 'not_found')
    const input = (await request.json()) as RoomInput
    const violations = validateRoom(input)
    if (violations.length > 0) return invalid(violations)
    db.rooms[index] = toRoom(input, db.rooms[index].id, db.rooms[index].position, db.rooms[index])
    return json<Room>(db.rooms[index])
  }),
  http.delete('*/api/rooms/:roomId', ({ params }) => {
    const index = db.rooms.findIndex((r) => r.id === params.roomId)
    if (index < 0) return fail(404, 'not_found')
    db.rooms.splice(index, 1)
    return noContent()
  }),

  http.post('*/api/rooms/:roomId/boost', async ({ params, request }) => {
    const id = String(params.roomId)
    if (!db.rooms.some((r) => r.id === id)) return fail(404, 'not_found')
    const body = (await request.json()) as BoostInput
    const violations: Violation[] = []
    if (!(body.delta >= 0.5 && body.delta <= 5 && body.delta % 0.5 === 0)) {
      violations.push(outOfRange('delta', 0.5, 5))
    }
    if (!body.durationMinutes && !body.untilNextSlot) {
      violations.push({
        path: 'durationMinutes',
        message: 'Indiquez une durée ou « jusqu’au prochain créneau ».',
      })
    }
    if (violations.length > 0) return invalid(violations)
    if (db.settings.paused) return fail(409, 'paused', 'HA Climate is paused.')
    db.overrides[id] = {
      type: 'boost',
      delta: body.delta,
      target: null,
      endsAt: body.durationMinutes
        ? new Date(Date.now() + body.durationMinutes * MINUTE).toISOString()
        : null,
      untilNextSlot: body.untilNextSlot ?? false,
    }
    return json<RoomStatus>(emitRoom(id) as RoomStatus)
  }),
  http.delete('*/api/rooms/:roomId/override', ({ params }) => {
    const id = String(params.roomId)
    if (!db.rooms.some((r) => r.id === id)) return fail(404, 'not_found')
    if (db.settings.paused) return fail(409, 'paused', 'HA Climate is paused.')
    delete db.overrides[id]
    return json<RoomStatus>(emitRoom(id) as RoomStatus)
  }),

  http.get('*/api/presets', () => json<Preset[]>(db.presets)),
  http.post('*/api/presets', async ({ request }) => {
    const preset = (await request.json()) as Preset
    if (!/^[a-z0-9_]{1,32}$/.test(preset.key)) {
      return invalid([{ path: 'key', message: 'Identifiant invalide (a-z, 0-9, _).' }])
    }
    if (db.presets.some((p) => p.key === preset.key)) return fail(409, 'preset_exists')
    db.presets.push(preset)
    return json<Preset>(preset, 201)
  }),
  http.put('*/api/presets/:presetKey', async ({ params, request }) => {
    const index = db.presets.findIndex((p) => p.key === params.presetKey)
    if (index < 0) return fail(404, 'not_found')
    const preset = (await request.json()) as Preset
    if (!preset.label?.trim())
      return invalid([{ path: 'label', message: 'Le libellé est obligatoire.' }])
    db.presets[index] = { ...preset, key: db.presets[index].key }
    return json<Preset>(db.presets[index])
  }),
  http.delete('*/api/presets/:presetKey', ({ params }) => {
    const index = db.presets.findIndex((p) => p.key === params.presetKey)
    if (index < 0) return fail(404, 'not_found')
    if (db.schedules.some((s) => s.slots.some((slot) => slot.preset === params.presetKey))) {
      return fail(409, 'preset_in_use')
    }
    db.presets.splice(index, 1)
    return noContent()
  }),

  http.get('*/api/schedules', () => json<Schedule[]>(db.schedules.map(withUsage))),
  http.post('*/api/schedules', async ({ request }) => {
    const input = (await request.json()) as ScheduleInput
    const violations = validateSchedule(input)
    if (violations.length > 0) return invalid(violations)
    const schedule: Schedule = { ...input, id: newId(), usedBy: [] }
    db.schedules.push(schedule)
    return json<Schedule>(schedule, 201)
  }),
  http.get('*/api/schedules/:scheduleId', ({ params }) => {
    const schedule = db.schedules.find((s) => s.id === params.scheduleId)
    return schedule ? json<Schedule>(withUsage(schedule)) : fail(404, 'not_found')
  }),
  http.put('*/api/schedules/:scheduleId', async ({ params, request }) => {
    const index = db.schedules.findIndex((s) => s.id === params.scheduleId)
    if (index < 0) return fail(404, 'not_found')
    const input = (await request.json()) as ScheduleInput
    const violations = validateSchedule(input)
    if (violations.length > 0) return invalid(violations)
    db.schedules[index] = { ...input, id: db.schedules[index].id, usedBy: [] }
    return json<Schedule>(withUsage(db.schedules[index]))
  }),
  http.delete('*/api/schedules/:scheduleId', ({ params }) => {
    const index = db.schedules.findIndex((s) => s.id === params.scheduleId)
    if (index < 0) return fail(404, 'not_found')
    db.rooms.forEach((r) => {
      if (r.heatingScheduleId === params.scheduleId) r.heatingScheduleId = null
      if (r.coolingScheduleId === params.scheduleId) r.coolingScheduleId = null
    })
    db.schedules.splice(index, 1)
    return noContent()
  }),
  http.post('*/api/schedules/:scheduleId/duplicate', async ({ params, request }) => {
    const source = db.schedules.find((s) => s.id === params.scheduleId)
    if (!source) return fail(404, 'not_found')
    const { name } = (await request.json()) as { name: string }
    if (!name?.trim()) return invalid([{ path: 'name', message: 'Le nom est obligatoire.' }])
    const copy: Schedule = { ...structuredClone(source), id: newId(), name, usedBy: [] }
    db.schedules.push(copy)
    return json<Schedule>(copy, 201)
  }),

  http.get('*/api/settings', () => json<Settings>(db.settings)),
  http.put('*/api/settings', async ({ request }) => {
    const input = (await request.json()) as Settings
    const violations: Violation[] = []
    if (input.manualOverrideMinutes < 1 || input.manualOverrideMinutes > 1440) {
      violations.push(outOfRange('manualOverrideMinutes', 1, 1440))
    }
    if (input.auditRetentionDays < 1 || input.auditRetentionDays > 3650) {
      violations.push(outOfRange('auditRetentionDays', 1, 3650))
    }
    if (input.vacation.enabled && !input.vacation.returnAt) {
      violations.push({ path: 'vacation.returnAt', message: 'La date de retour est obligatoire.' })
    }
    if (violations.length > 0) return invalid(violations)
    db.settings = { ...input, auditEntryCount: db.settings.auditEntryCount }
    return json<Settings>(db.settings)
  }),

  http.get('*/api/notifications', () => json<NotificationSettings>(db.notifications)),
  http.put('*/api/notifications', async ({ request }) => {
    const input = (await request.json()) as NotificationSettings
    const delay = input.types.findIndex((t) => t.delaySeconds !== null && t.delaySeconds > 86400)
    if (delay >= 0) return invalid([outOfRange(`types[${delay}].delaySeconds`, 0, 86400)])
    db.notifications = {
      targets: input.targets.map((t) => ({
        ...t,
        name: db.notifications.targets.find((o) => o.service === t.service)?.name ?? null,
        available: db.notifications.targets.find((o) => o.service === t.service)?.available ?? true,
      })),
      types: input.types,
    }
    return json<NotificationSettings>(db.notifications)
  }),
  http.post('*/api/notifications/test', () =>
    db.notifications.targets.some((t) => t.enabled)
      ? new HttpResponse(null, { status: 202 })
      : fail(409, 'no_enabled_target'),
  ),

  http.get('*/api/discovery', () => json(buildDiscovery())),

  http.get('*/api/audit', ({ request }) => {
    const params = new URL(request.url).searchParams
    const limit = Number(params.get('limit') ?? 50)
    const offset = Number(params.get('offset') ?? 0)
    if (!(limit >= 1 && limit <= 200) || !(offset >= 0)) {
      return invalid([{ path: 'limit', message: 'Paramètres de pagination invalides.' }])
    }
    const matching = filterAudit(params)
    return json({ items: matching.slice(offset, offset + limit), total: matching.length })
  }),
  http.get('*/api/audit/export', ({ request }) => {
    const rows = filterAudit(new URL(request.url).searchParams).map((e) =>
      [e.occurredAt, e.roomName ?? '', e.actorType, e.message].map(csvCell).join(','),
    )
    return new HttpResponse(['time,room,actor,message', ...rows].join('\n'), {
      headers: { 'Content-Type': 'text/csv' },
    })
  }),

  http.get('*/api/backup', () => json<Backup>(buildBackup())),
  http.post('*/api/backup/restore', async ({ request }) => {
    const backup = (await request.json()) as Backup
    if (backup.format !== 'ha-climate-backup' || ![1, 2].includes(backup.version)) {
      return invalid([{ path: 'format', message: 'Fichier de sauvegarde non reconnu.' }])
    }
    return json(restoreBackup(backup))
  }),
]
