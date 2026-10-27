import type {
  DeviceRole,
  PresenceSignal,
  PresenceSignalKind,
  PresetTemperature,
  Room,
  RoomInput,
  RoomStatus,
} from '@/api/types'

/** Room as edited in the UI: delays in minutes (the API uses seconds). */
export interface RoomForm {
  name: string
  areaId: string | null
  temperatureSensor: string | null
  presenceSignals: PresenceSignal[]
  openings: string[]
  vacancyDelayMinutes: number
  windowCutDelayMinutes: number
  windowNotifyDelayMinutes: number
  preheatEnabled: boolean
  preheatMaxMinutes: number
  heatingScheduleId: string | null
  coolingScheduleId: string | null
  devices: DeviceForm[]
  presetTemperatures: PresetTemperature[]
}

export interface DeviceForm {
  entityId: string
  role: DeviceRole
  minOnMinutes: number
  minOffMinutes: number
  hysteresis: number
}

export const ROOM_DEFAULTS = {
  vacancyDelaySeconds: 1200,
  windowCutDelaySeconds: 120,
  windowNotifyDelaySeconds: 1800,
  preheatMaxMinutes: 120,
}

const toMinutes = (seconds: number): number => Math.round((seconds / 60) * 100) / 100
const toSeconds = (minutes: number): number => Math.round(minutes * 60)

/** One row per known preset, in preset order; missing rows are empty. */
function alignTemperatures(
  existing: PresetTemperature[],
  presetKeys: string[],
): PresetTemperature[] {
  return presetKeys.map(
    (key) => existing.find((p) => p.preset === key) ?? { preset: key, heat: null, cool: null },
  )
}

export function emptyRoomForm(presetKeys: string[]): RoomForm {
  return {
    name: '',
    areaId: null,
    temperatureSensor: null,
    presenceSignals: [],
    openings: [],
    vacancyDelayMinutes: toMinutes(ROOM_DEFAULTS.vacancyDelaySeconds),
    windowCutDelayMinutes: toMinutes(ROOM_DEFAULTS.windowCutDelaySeconds),
    windowNotifyDelayMinutes: toMinutes(ROOM_DEFAULTS.windowNotifyDelaySeconds),
    preheatEnabled: true,
    preheatMaxMinutes: ROOM_DEFAULTS.preheatMaxMinutes,
    heatingScheduleId: null,
    coolingScheduleId: null,
    devices: [],
    presetTemperatures: alignTemperatures([], presetKeys),
  }
}

export function toRoomForm(room: Room, presetKeys: string[]): RoomForm {
  return {
    name: room.name,
    areaId: room.areaId ?? null,
    temperatureSensor: room.temperatureSensor,
    presenceSignals: room.presenceSignals.map(normalizeSignal),
    openings: [...room.openings],
    vacancyDelayMinutes: toMinutes(room.vacancyDelaySeconds),
    windowCutDelayMinutes: toMinutes(room.windowCutDelaySeconds),
    windowNotifyDelayMinutes: toMinutes(room.windowNotifyDelaySeconds),
    preheatEnabled: room.preheatEnabled,
    preheatMaxMinutes: room.preheatMaxMinutes,
    heatingScheduleId: room.heatingScheduleId ?? null,
    coolingScheduleId: room.coolingScheduleId ?? null,
    devices: room.devices.map((d) => ({
      entityId: d.entityId,
      role: d.role,
      minOnMinutes: toMinutes(d.minOnSeconds),
      minOffMinutes: toMinutes(d.minOffSeconds),
      hysteresis: d.hysteresis,
    })),
    presetTemperatures: alignTemperatures(room.presetTemperatures, presetKeys),
  }
}

export function fromRoomForm(form: RoomForm): RoomInput {
  return {
    name: form.name.trim(),
    areaId: form.areaId,
    temperatureSensor: form.temperatureSensor,
    presenceSignals: form.presenceSignals.map(normalizeSignal),
    openings: form.openings,
    vacancyDelaySeconds: toSeconds(form.vacancyDelayMinutes),
    windowCutDelaySeconds: toSeconds(form.windowCutDelayMinutes),
    windowNotifyDelaySeconds: toSeconds(form.windowNotifyDelayMinutes),
    preheatEnabled: form.preheatEnabled,
    preheatMaxMinutes: form.preheatMaxMinutes,
    heatingScheduleId: form.heatingScheduleId,
    coolingScheduleId: form.coolingScheduleId,
    devices: form.devices.map((d) => ({
      entityId: d.entityId,
      role: d.role,
      minOnSeconds: toSeconds(d.minOnMinutes),
      minOffSeconds: toSeconds(d.minOffMinutes),
      hysteresis: d.hysteresis,
    })),
    presetTemperatures: form.presetTemperatures.filter((p) => p.heat !== null || p.cool !== null),
  }
}

export function emptyDevice(entityId: string): DeviceForm {
  return { entityId, role: 'heating', minOnMinutes: 5, minOffMinutes: 5, hysteresis: 0.3 }
}

const ACTIVE_HVAC_MODES = ['heat', 'cool', 'heat_cool', 'auto', 'dry', 'fan_only']

/**
 * What the devices of a room are doing: `running` when one reports it through hvac_action, `on` when
 * one reports no hvac_action but sits in an active HVAC mode (it may be idling at its setpoint).
 */
export function roomActivity(status: RoomStatus): 'running' | 'on' | 'idle' {
  if (status.devices.some((d) => d.running === true)) return 'running'
  const on = status.devices.some(
    (d) => (d.running ?? null) === null && d.state !== null && ACTIVE_HVAC_MODES.includes(d.state),
  )
  return on ? 'on' : 'idle'
}

/** Default power threshold proposed when a signal becomes "above". */
export const DEFAULT_THRESHOLD = 20

/** Keeps only the parameter that goes with the kind (the API wants the other one null). */
export function normalizeSignal(signal: PresenceSignal): PresenceSignal {
  return {
    entityId: signal.entityId,
    kind: signal.kind,
    threshold: signal.kind === 'above' ? (signal.threshold ?? null) : null,
    value: signal.kind === 'equals' ? (signal.value ?? null) : null,
  }
}

/** A signal of `kind` for an entity; the parameter takes a sensible default (20 W, current state). */
export function newSignal(
  entity: { entityId: string; state: string | null },
  kind: PresenceSignalKind,
  previous?: PresenceSignal,
): PresenceSignal {
  const known = entity.state !== null && !['unavailable', 'unknown', ''].includes(entity.state)
  return {
    entityId: entity.entityId,
    kind,
    threshold: kind === 'above' ? (previous?.threshold ?? DEFAULT_THRESHOLD) : null,
    value: kind === 'equals' ? (previous?.value ?? (known ? entity.state : '')) : null,
  }
}
