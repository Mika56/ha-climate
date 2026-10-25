import type {
  Alert,
  AuditEntry,
  Context,
  Discovery,
  NotificationSettings,
  Preset,
  Room,
  Schedule,
  ScheduleSlot,
  Settings,
  UserPreferences,
} from '@/api/types'

/** Sample data of the mock API: the rooms, presets and schedules of the approved mockups. */

export const ID = {
  salon: '00000000-0000-4000-8000-000000000001',
  cuisine: '00000000-0000-4000-8000-000000000002',
  parents: '00000000-0000-4000-8000-000000000003',
  enfant: '00000000-0000-4000-8000-000000000004',
  bureau: '00000000-0000-4000-8000-000000000005',
  sdb: '00000000-0000-4000-8000-000000000006',
  semaine: '10000000-0000-4000-8000-000000000001',
  teletravail: '10000000-0000-4000-8000-000000000002',
  eteVie: '10000000-0000-4000-8000-000000000003',
  eteChambres: '10000000-0000-4000-8000-000000000004',
} as const

export const MINUTE = 60_000

export const presets: Preset[] = [
  { key: 'comfort', label: 'Confort', color: '#c25400', position: 0 },
  { key: 'eco', label: 'Éco', color: '#1b7a34', position: 1 },
  { key: 'night', label: 'Nuit', color: '#4a55a2', position: 2 },
  { key: 'away', label: 'Absent', color: '#6b6b6b', position: 3 },
]

const presetTemperatures = [
  { preset: 'comfort', heat: 21, cool: 24 },
  { preset: 'eco', heat: 19, cool: 27 },
  { preset: 'night', heat: 17, cool: 25 },
  { preset: 'away', heat: 16, cool: 29 },
]

interface RoomSeed {
  id: string
  name: string
  slug: string
  area: string
  heating: string
  cooling: string | null
  devices: Room['devices']
  openings: string[]
  presence: string | null
}

const seeds: RoomSeed[] = [
  {
    id: ID.salon,
    name: 'Salon',
    slug: 'salon',
    area: 'salon',
    heating: ID.semaine,
    cooling: ID.eteVie,
    openings: ['binary_sensor.salon_fenetre', 'binary_sensor.salon_baie_vitree'],
    presence: 'binary_sensor.salon_occupancy',
    devices: [
      {
        entityId: 'climate.salon_radiateur',
        role: 'heating',
        minOnSeconds: 600,
        minOffSeconds: 300,
        hysteresis: 0.3,
      },
      {
        entityId: 'climate.salon_clim',
        role: 'reversible',
        minOnSeconds: 900,
        minOffSeconds: 600,
        hysteresis: 0.5,
      },
    ],
  },
  {
    id: ID.cuisine,
    name: 'Cuisine',
    slug: 'cuisine',
    area: 'cuisine',
    heating: ID.semaine,
    cooling: ID.eteVie,
    openings: [],
    presence: 'binary_sensor.cuisine_occupancy',
    devices: [
      {
        entityId: 'climate.cuisine_radiateur',
        role: 'heating',
        minOnSeconds: 300,
        minOffSeconds: 300,
        hysteresis: 0.3,
      },
    ],
  },
  {
    id: ID.parents,
    name: 'Chambre parentale',
    slug: 'chambre_parentale',
    area: 'chambres',
    heating: ID.semaine,
    cooling: ID.eteChambres,
    openings: ['binary_sensor.chambre_parentale_fenetre'],
    presence: null,
    devices: [
      {
        entityId: 'climate.chambre_parentale',
        role: 'reversible',
        minOnSeconds: 600,
        minOffSeconds: 600,
        hysteresis: 0.5,
      },
    ],
  },
  {
    id: ID.enfant,
    name: 'Chambre enfant',
    slug: 'chambre_enfant',
    area: 'chambres',
    heating: ID.semaine,
    cooling: ID.eteChambres,
    openings: [],
    presence: null,
    devices: [
      {
        entityId: 'climate.chambre_enfant',
        role: 'heating',
        minOnSeconds: 300,
        minOffSeconds: 300,
        hysteresis: 0.3,
      },
    ],
  },
  {
    id: ID.bureau,
    name: 'Bureau',
    slug: 'bureau',
    area: 'bureau',
    heating: ID.teletravail,
    cooling: ID.eteVie,
    openings: [],
    presence: null,
    devices: [
      {
        entityId: 'climate.bureau_radiateur',
        role: 'heating',
        minOnSeconds: 300,
        minOffSeconds: 300,
        hysteresis: 0.3,
      },
    ],
  },
  {
    id: ID.sdb,
    name: 'Salle de bain',
    slug: 'sdb',
    area: 'sdb',
    heating: ID.semaine,
    cooling: null,
    openings: ['binary_sensor.sdb_fenetre'],
    presence: null,
    devices: [
      {
        entityId: 'climate.sdb_seche_serviette',
        role: 'heating',
        minOnSeconds: 300,
        minOffSeconds: 300,
        hysteresis: 0.3,
      },
    ],
  },
]

export function buildRooms(): Room[] {
  return seeds.map((s, position) => ({
    id: s.id,
    position,
    name: s.name,
    areaId: s.area,
    temperatureSensor: `sensor.${s.slug}_temperature`,
    presenceSensor: s.presence,
    openings: s.openings,
    vacancyDelaySeconds: 1200,
    windowCutDelaySeconds: 120,
    windowNotifyDelaySeconds: 1800,
    preheatEnabled: true,
    preheatMaxMinutes: 120,
    heatingScheduleId: s.heating,
    coolingScheduleId: s.cooling,
    devices: s.devices.map((d) => ({ ...d })),
    presetTemperatures: presetTemperatures.map((p) => ({ ...p })),
    learnedRates: {
      heat: { degreesPerHour: 1.2, samples: 34 },
      cool: s.id === ID.salon ? { degreesPerHour: 0.9, samples: 12 } : null,
    },
  }))
}

type Day = [string, string, boolean?][]

const toSlots = (days: number[], pattern: Day, anchored?: { entityId: string; offset: number }) =>
  days.flatMap((weekday) =>
    pattern.map(([time, preset, anchor]): ScheduleSlot => {
      const [h, m] = time.split(':').map(Number)
      const minuteOfDay = h * 60 + m
      return {
        weekday,
        preset,
        start:
          anchor && anchored
            ? {
                type: 'anchored',
                entityId: anchored.entityId,
                offsetMinutes: anchored.offset,
                fallbackMinuteOfDay: minuteOfDay,
              }
            : { type: 'fixed', minuteOfDay },
      }
    }),
  )

export function buildSchedules(): Schedule[] {
  const bedtime = { entityId: 'input_datetime.heure_coucher', offset: -15 }
  const weekday: Day = [
    ['00:00', 'night'],
    ['06:30', 'comfort'],
    ['08:30', 'eco'],
    ['17:30', 'comfort'],
    ['22:30', 'night', true],
  ]
  const weekend: Day = [
    ['00:00', 'night'],
    ['08:30', 'comfort'],
    ['14:00', 'away'],
    ['17:00', 'comfort'],
    ['23:30', 'night'],
  ]
  const summer: Day = [
    ['00:00', 'night'],
    ['07:00', 'comfort'],
    ['09:00', 'eco'],
    ['18:00', 'comfort'],
    ['23:00', 'night'],
  ]
  return [
    {
      id: ID.semaine,
      name: 'Semaine standard',
      mode: 'heat',
      slots: [...toSlots([1, 2, 3, 4, 5], weekday, bedtime), ...toSlots([6, 7], weekend)],
      usedBy: [
        { roomId: ID.salon, roomName: 'Salon' },
        { roomId: ID.cuisine, roomName: 'Cuisine' },
        { roomId: ID.enfant, roomName: 'Chambre enfant' },
      ],
    },
    {
      id: ID.teletravail,
      name: 'Télétravail',
      mode: 'heat',
      slots: toSlots([1, 2, 3, 4, 5, 6, 7], weekend),
      usedBy: [{ roomId: ID.bureau, roomName: 'Bureau' }],
    },
    {
      id: ID.eteVie,
      name: 'Été · pièces de vie',
      mode: 'cool',
      slots: toSlots([1, 2, 3, 4, 5, 6, 7], summer),
      usedBy: [
        { roomId: ID.salon, roomName: 'Salon' },
        { roomId: ID.cuisine, roomName: 'Cuisine' },
      ],
    },
    {
      id: ID.eteChambres,
      name: 'Été · chambres',
      mode: 'cool',
      slots: toSlots([1, 2, 3, 4, 5, 6, 7], summer),
      usedBy: [],
    },
  ]
}

export function buildSettings(): Settings {
  return {
    season: 'winter',
    trackedPersons: ['person.alex', 'person.sam'],
    houseEmptyDelaySeconds: 900,
    manualOverrideMinutes: 120,
    vacation: {
      enabled: false,
      startAt: null,
      returnAt: null,
      offDuringVacation: false,
    },
    weatherEntity: 'weather.maison',
    uiLocale: null,
    notificationLocale: null,
    auditRetentionDays: 90,
    auditEntryCount: 12418,
  }
}

export function buildNotifications(): NotificationSettings {
  return {
    targets: [
      { service: 'notify.mobile_app_pixel_8', enabled: true, name: 'App mobile', available: true },
      { service: 'notify.mobile_app_ipad', enabled: false, name: 'App mobile', available: true },
      { service: 'notify.telegram_maison', enabled: true, name: 'Telegram', available: true },
      { service: 'notify.old_phone', enabled: true, name: null, available: false },
    ],
    types: [
      { type: 'window_open', enabled: true, delaySeconds: null, persistentNotification: false },
      {
        type: 'setpoint_not_applicable',
        enabled: true,
        delaySeconds: null,
        persistentNotification: false,
      },
      {
        type: 'device_unresponsive',
        enabled: true,
        delaySeconds: 300,
        persistentNotification: false,
      },
      {
        type: 'sensor_unavailable',
        enabled: true,
        delaySeconds: null,
        persistentNotification: true,
      },
    ],
  }
}

const entity = (
  entityId: string,
  name: string,
  areaId: string | null,
  state: string | null,
  unit: string | null = null,
) => ({
  entityId,
  name,
  areaId,
  state,
  unit,
  deviceClass: null,
})

export function buildDiscovery(): Discovery {
  return {
    areas: [
      { id: 'salon', name: 'Salon' },
      { id: 'cuisine', name: 'Cuisine' },
      { id: 'chambres', name: 'Chambres' },
      { id: 'bureau', name: 'Bureau' },
      { id: 'sdb', name: 'Salle de bain' },
    ],
    persons: [
      entity('person.alex', 'Alex', null, 'home'),
      entity('person.sam', 'Sam', null, 'home'),
      entity('person.charlie', 'Charlie', null, 'not_home'),
    ],
    climates: [
      {
        ...entity('climate.salon_radiateur', 'Radiateur salon', 'salon', 'heat'),
        hvacModes: ['heat', 'off'],
      },
      {
        ...entity('climate.salon_clim', 'Clim salon', 'salon', 'off'),
        hvacModes: ['heat', 'cool', 'off'],
      },
      {
        ...entity('climate.cuisine_radiateur', 'Radiateur cuisine', 'cuisine', 'heat'),
        hvacModes: ['heat', 'off'],
      },
      {
        ...entity('climate.chambre_parentale', 'Clim chambre parentale', 'chambres', 'off'),
        hvacModes: ['heat', 'cool', 'off'],
      },
      {
        ...entity('climate.chambre_enfant', 'Radiateur chambre enfant', 'chambres', 'heat'),
        hvacModes: ['heat', 'off'],
      },
      {
        ...entity('climate.bureau_radiateur', 'Radiateur bureau', 'bureau', 'heat'),
        hvacModes: ['heat', 'off'],
      },
      {
        ...entity('climate.sdb_seche_serviette', 'Sèche-serviette', 'sdb', 'heat'),
        hvacModes: ['heat', 'off'],
      },
    ],
    temperatureSensors: [
      entity('sensor.salon_temperature', 'Température salon', 'salon', '20.4', '°C'),
      entity('sensor.cuisine_temperature', 'Température cuisine', 'cuisine', '19.8', '°C'),
      entity(
        'sensor.chambre_parentale_temperature',
        'Température chambre parentale',
        'chambres',
        'unavailable',
        '°C',
      ),
      entity(
        'sensor.chambre_enfant_temperature',
        'Température chambre enfant',
        'chambres',
        '19.6',
        '°C',
      ),
      entity('sensor.bureau_temperature', 'Température bureau', 'bureau', '21.2', '°C'),
      entity('sensor.sdb_temperature', 'Température salle de bain', 'sdb', '18.1', '°C'),
      entity('sensor.garage_temperature', 'Température garage', null, '12.0', '°C'),
    ],
    presenceSensors: [
      entity('binary_sensor.salon_occupancy', 'Présence salon', 'salon', 'on'),
      entity('binary_sensor.cuisine_occupancy', 'Présence cuisine', 'cuisine', 'off'),
    ],
    openingSensors: [
      entity('binary_sensor.salon_fenetre', 'Fenêtre salon', 'salon', 'off'),
      entity('binary_sensor.salon_baie_vitree', 'Baie vitrée', 'salon', 'off'),
      entity(
        'binary_sensor.chambre_parentale_fenetre',
        'Fenêtre chambre parentale',
        'chambres',
        'off',
      ),
      entity('binary_sensor.sdb_fenetre', 'Fenêtre salle de bain', 'sdb', 'on'),
      entity('binary_sensor.porte_garage', 'Porte garage', null, 'off'),
    ],
    timeEntities: [
      entity('input_datetime.heure_coucher', 'Heure du coucher', null, '22:30:00'),
      entity('input_datetime.reveil', 'Réveil', null, '06:45:00'),
    ],
    weather: [
      entity('weather.maison', 'Maison', null, 'partlycloudy'),
      entity('weather.forecast_home', 'Prévisions', null, 'sunny'),
    ],
    notifyServices: [
      { service: 'notify.mobile_app_pixel_8', name: 'App mobile' },
      { service: 'notify.mobile_app_ipad', name: 'App mobile' },
      { service: 'notify.telegram_maison', name: 'Telegram' },
    ],
  }
}

export function buildContext(): Context {
  return {
    user: { id: 'user-1', name: 'alex', displayName: 'Alex' },
    supportedLocales: ['en', 'fr'],
    haLanguage: 'fr',
    haTimeZone: 'Europe/Paris',
    haVersion: '2026.10.1',
    preferences: { locale: null, theme: 'auto' } satisfies UserPreferences,
    engine: { running: true, connected: true },
  }
}

type AuditSeed = [number, string | null, AuditEntry['actorType'], string | null, string, string]

const auditSeeds: AuditSeed[] = [
  [20, ID.enfant, 'user', 'Alex', 'override', 'Boost +1,5 °C demandé par Alex, jusqu’à 18:45'],
  [25, ID.cuisine, 'automation', null, 'decision', 'Inoccupée depuis 20 min : passage en Éco'],
  [30, ID.sdb, 'automation', null, 'opening', 'Fenêtre ouverte depuis 2 min : chauffage coupé'],
  [
    47,
    ID.salon,
    'automation',
    null,
    'anti_cycling',
    'Mise en marche de la clim différée de 6 min (anti-cyclage : arrêt minimum 10 min)',
  ],
  [
    75,
    ID.bureau,
    'device',
    null,
    'override',
    'Consigne changée sur l’appareil (22,0 °C) : surcharge manuelle jusqu’à 18:30',
  ],
  [
    213,
    null,
    'user',
    'Sam',
    'config',
    'Sam a modifié « Pièce inoccupée → Éco après » de la Cuisine : 30 → 20 min',
  ],
  [
    263,
    ID.parents,
    'system',
    null,
    'alert',
    'Capteur indisponible : sensor.chambre_parentale_temperature',
  ],
  [345, null, 'automation', null, 'decision', 'Planning « Semaine standard » : passage en Confort'],
  [
    418,
    null,
    'automation',
    null,
    'presence',
    'Personne à la maison depuis 15 min : tout est coupé',
  ],
  [
    500,
    null,
    'system',
    null,
    'system',
    'Add-on redémarré : état restauré (3 minuteurs, 1 boost), aucune commande nécessaire',
  ],
]

export function buildAudit(now: number): AuditEntry[] {
  const rooms = buildRooms()
  const entries: AuditEntry[] = []
  for (let i = 0; i < 60; i++) {
    const seed = auditSeeds[i % auditSeeds.length]
    const [minutesAgo, roomId, actorType, actorName, category, message] = seed
    entries.push({
      id: `20000000-0000-4000-8000-${String(i + 1).padStart(12, '0')}`,
      occurredAt: new Date(
        now - (minutesAgo + Math.floor(i / auditSeeds.length) * 600) * MINUTE,
      ).toISOString(),
      actorType,
      actorName,
      roomId,
      roomName: rooms.find((r) => r.id === roomId)?.name ?? null,
      category,
      messageKey: `audit.${category}`,
      params: {},
      message,
    })
  }
  return entries.sort((a, b) => b.occurredAt.localeCompare(a.occurredAt))
}

export function buildAlerts(now: number): Alert[] {
  return [
    {
      key: 'entity.sensor.chambre_parentale_temperature',
      type: 'entity_unavailable',
      entityId: 'sensor.chambre_parentale_temperature',
      roomId: ID.parents,
      roomName: 'Chambre parentale',
      openedAt: new Date(now - 263 * MINUTE).toISOString(),
      notifiedAt: new Date(now - 262 * MINUTE).toISOString(),
    },
    {
      key: 'opening.binary_sensor.sdb_fenetre',
      type: 'opening_open',
      entityId: 'binary_sensor.sdb_fenetre',
      roomId: ID.sdb,
      roomName: 'Salle de bain',
      openedAt: new Date(now - 32 * MINUTE).toISOString(),
      notifiedAt: null,
    },
  ]
}
