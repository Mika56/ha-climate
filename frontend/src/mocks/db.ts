import type {
  Alert,
  AuditEntry,
  Context,
  NotificationSettings,
  Preset,
  Room,
  RoomStatus,
  Schedule,
  Settings,
  UserPreferences,
} from '@/api/types'
import {
  ID,
  MINUTE,
  buildAlerts,
  buildAudit,
  buildContext,
  buildNotifications,
  buildRooms,
  buildSchedules,
  buildSettings,
  presets,
} from './data'

type Override = NonNullable<RoomStatus['override']>

export interface MockDb {
  context: Context
  preferences: UserPreferences
  rooms: Room[]
  presets: Preset[]
  schedules: Schedule[]
  settings: Settings
  notifications: NotificationSettings
  audit: AuditEntry[]
  alerts: Alert[]
  overrides: Record<string, Override>
  nextId: number
}

export function createMockDb(now: number = Date.now()): MockDb {
  const context = buildContext()
  return {
    context,
    preferences: context.preferences,
    rooms: buildRooms(),
    presets: presets.map((p) => ({ ...p })),
    schedules: buildSchedules(),
    settings: buildSettings(),
    notifications: buildNotifications(),
    audit: buildAudit(now),
    alerts: buildAlerts(now),
    overrides: {
      [ID.enfant]: {
        type: 'boost',
        delta: 1.5,
        target: null,
        endsAt: new Date(now + 60 * MINUTE).toISOString(),
        untilNextSlot: false,
      },
      [ID.bureau]: {
        type: 'manual',
        delta: null,
        target: 22,
        endsAt: new Date(now + 90 * MINUTE).toISOString(),
        untilNextSlot: false,
      },
    },
    nextId: 100,
  }
}

/** The mutable state behind the MSW handlers (replaced in place by {@link resetMockDb}). */
export const mockDb: MockDb = createMockDb()

export function resetMockDb(now: number = Date.now()): void {
  Object.assign(mockDb, createMockDb(now))
}

export function newId(db: MockDb = mockDb): string {
  db.nextId += 1
  return `30000000-0000-4000-8000-${String(db.nextId).padStart(12, '0')}`
}

type Listener = (status: RoomStatus) => void
const listeners = new Set<Listener>()

/** Pub/sub used by the fake EventSource to replay live updates. */
export const statusBus = {
  subscribe(listener: Listener): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },
  emit(status: RoomStatus): void {
    for (const listener of listeners) listener(status)
  },
}

interface Scenario {
  current: { heat: number | null; cool: number | null }
  preset: string
  running: { heat: boolean; cool: boolean }
  kind: 'schedule' | 'vacant' | 'unavailable' | 'window'
}

const scenarios: Record<string, Scenario> = {
  [ID.salon]: {
    current: { heat: 20.4, cool: 25.1 },
    preset: 'comfort',
    running: { heat: true, cool: true },
    kind: 'schedule',
  },
  [ID.cuisine]: {
    current: { heat: 19.8, cool: 26.4 },
    preset: 'eco',
    running: { heat: false, cool: false },
    kind: 'vacant',
  },
  [ID.parents]: {
    current: { heat: null, cool: null },
    preset: 'night',
    running: { heat: false, cool: false },
    kind: 'unavailable',
  },
  [ID.enfant]: {
    current: { heat: 19.6, cool: 24.8 },
    preset: 'comfort',
    running: { heat: true, cool: true },
    kind: 'schedule',
  },
  [ID.bureau]: {
    current: { heat: 21.2, cool: 24.2 },
    preset: 'comfort',
    running: { heat: true, cool: true },
    kind: 'schedule',
  },
  [ID.sdb]: {
    current: { heat: 18.1, cool: 25.9 },
    preset: 'comfort',
    running: { heat: false, cool: false },
    kind: 'window',
  },
}

const DEFAULT_SCENARIO: Scenario = scenarios[ID.salon]

/** Derives the status of a room from the mock state, like the engine would. */
export function computeStatus(
  room: Room,
  db: MockDb = mockDb,
  now: number = Date.now(),
): RoomStatus {
  const sc = scenarios[room.id] ?? DEFAULT_SCENARIO
  const season = db.settings.season
  const mode = season === 'winter' ? 'heat' : season === 'summer' ? 'cool' : 'off'
  const override = mode === 'off' ? null : (db.overrides[room.id] ?? null)
  const iso = (offsetMinutes: number): string =>
    new Date(now + offsetMinutes * MINUTE).toISOString()
  const alerts = db.alerts.filter((a) => a.roomId === room.id)

  let target: number | null = null
  let reason: RoomStatus['decision']['reason'] = { code: 'reason.season_off', params: {} }
  let decisionMode: RoomStatus['decision']['mode'] = 'off'
  let running = false

  if (mode !== 'off') {
    const temps = room.presetTemperatures.find((p) => p.preset === sc.preset)
    target = (mode === 'heat' ? temps?.heat : temps?.cool) ?? null
    decisionMode = mode
    running = sc.running[mode]
    reason = { code: 'reason.schedule', params: { preset: sc.preset, until: iso(90) } }
    if (sc.kind === 'vacant') {
      reason = { code: 'reason.room_vacant', params: { preset: sc.preset, since: iso(-25) } }
    } else if (sc.kind === 'window') {
      decisionMode = 'off'
      target = null
      reason = { code: 'reason.opening_open', params: { since: iso(-32) } }
    }
    if (override?.type === 'boost' && target !== null) {
      target += mode === 'cool' ? -(override.delta ?? 0) : (override.delta ?? 0)
      running = true
      reason = { code: 'reason.boost', params: { delta: override.delta, until: override.endsAt } }
    } else if (override?.type === 'manual') {
      target = override.target
      running = true
      reason = { code: 'reason.manual_override', params: { until: override.endsAt } }
    }
  }

  return {
    roomId: room.id,
    name: room.name,
    currentTemperature: mode === 'cool' ? sc.current.cool : sc.current.heat,
    decision: { mode: decisionMode, target, reason },
    override,
    devices: room.devices.map((d) => ({
      entityId: d.entityId,
      role: d.role,
      state: decisionMode,
      targetTemperature: target,
      currentTemperature: mode === 'cool' ? sc.current.cool : sc.current.heat,
      running,
    })),
    facts: {
      openingOpenSince: sc.kind === 'window' ? iso(-32) : null,
      roomVacantSince: sc.kind === 'vacant' ? iso(-25) : null,
      houseEmptySince: null,
      heatingRatePerHour: 1.2,
    },
    alerts,
    updatedAt: iso(0),
  }
}

export function allStatuses(db: MockDb = mockDb, now: number = Date.now()): RoomStatus[] {
  return db.rooms.map((room) => computeStatus(room, db, now))
}
