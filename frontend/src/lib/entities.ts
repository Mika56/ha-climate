import type { DiscoveredEntity } from '@/api/types'
import {
  mdiAccount,
  mdiCircleOutline,
  mdiDoorOpen,
  mdiEye,
  mdiFan,
  mdiFlash,
  mdiGarage,
  mdiHomeAccount,
  mdiLightbulb,
  mdiMapMarkerRadius,
  mdiMotionSensor,
  mdiPowerPlug,
  mdiSpeaker,
  mdiTelevisionPlay,
  mdiThermometer,
  mdiThermostat,
  mdiToggleSwitch,
  mdiWindowOpenVariant,
} from '@/lib/icons'

/** What the picker needs to know about an entity (a discovered entity, possibly with a default kind). */
export type PickerEntity = DiscoveredEntity & { defaultKind?: string }

export type EntityCategory =
  | 'presence'
  | 'light'
  | 'switch'
  | 'fan'
  | 'media'
  | 'power'
  | 'roomSensor'
  | 'temperature'
  | 'opening'
  | 'climate'
  | 'other'

/** Display order of the groups of the picker. */
export const CATEGORY_ORDER: EntityCategory[] = [
  'presence',
  'light',
  'switch',
  'fan',
  'media',
  'power',
  'roomSensor',
  'temperature',
  'opening',
  'climate',
  'other',
]

export const domainOf = (entityId: string): string => entityId.split('.')[0]

const OPENING_CLASSES = ['window', 'door', 'opening', 'garage_door']

export function entityCategory(entity: DiscoveredEntity): EntityCategory {
  const deviceClass = entity.deviceClass ?? ''
  switch (domainOf(entity.entityId)) {
    case 'binary_sensor':
      return OPENING_CLASSES.includes(deviceClass) ? 'opening' : 'presence'
    case 'input_boolean':
      return 'presence'
    case 'light':
      return 'light'
    case 'switch':
      return 'switch'
    case 'fan':
      return 'fan'
    case 'media_player':
      return 'media'
    case 'sensor':
      if (deviceClass === 'power') return 'power'
      return deviceClass === 'temperature' ? 'temperature' : 'roomSensor'
    case 'climate':
      return 'climate'
    default:
      return 'other'
  }
}

const BY_CLASS: Record<string, string> = {
  motion: mdiMotionSensor,
  occupancy: mdiHomeAccount,
  presence: mdiAccount,
  window: mdiWindowOpenVariant,
  opening: mdiWindowOpenVariant,
  door: mdiDoorOpen,
  garage_door: mdiGarage,
  power: mdiFlash,
  temperature: mdiThermometer,
  speaker: mdiSpeaker,
}

const BY_DOMAIN: Record<string, string> = {
  light: mdiLightbulb,
  switch: mdiPowerPlug,
  fan: mdiFan,
  media_player: mdiTelevisionPlay,
  input_boolean: mdiToggleSwitch,
  binary_sensor: mdiEye,
  sensor: mdiMapMarkerRadius,
  climate: mdiThermostat,
  person: mdiAccount,
}

/** Icon path of an entity: by device class first, then by domain. */
export function entityIcon(entity: Pick<DiscoveredEntity, 'entityId' | 'deviceClass'>): string {
  const domain = domainOf(entity.entityId)
  const byClass = entity.deviceClass ? BY_CLASS[entity.deviceClass] : undefined
  if (byClass && domain !== 'switch') return byClass
  return BY_DOMAIN[domain] ?? mdiCircleOutline
}

/** Lower case without accents, for forgiving searches ("salle de bain" finds "Salle de Bàin"). */
export function fold(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
}

/** True when every word of `query` appears in `haystack` (both folded). */
export function matchesQuery(haystack: string, query: string): boolean {
  const words = fold(query).split(/\s+/).filter(Boolean)
  const target = fold(haystack)
  return words.every((w) => target.includes(w))
}

export const isUnavailable = (state: string | null): boolean =>
  state === null || state === 'unavailable' || state === 'unknown'

const ACTIVE_STATES = ['on', 'playing', 'paused', 'home']
export const isActiveState = (state: string | null): boolean =>
  state !== null && ACTIVE_STATES.includes(state)
