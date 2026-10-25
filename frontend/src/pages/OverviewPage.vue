<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import type { Alert, Overview, Season } from '@/api/types'
import AlertList from '@/components/AlertList.vue'
import HaIcon from '@/components/HaIcon.vue'
import PausedBanner from '@/components/PausedBanner.vue'
import PauseSwitch from '@/components/PauseSwitch.vue'
import RoomCard from '@/components/RoomCard.vue'
import SegmentedControl, { type SegmentOption } from '@/components/SegmentedControl.vue'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useFormatter, useNow } from '@/composables/useFormatter'
import { usePause } from '@/composables/usePause'
import { mdiAirplane, mdiFire, mdiHome, mdiSnowflake, mdiWeatherPartlyCloudy } from '@/lib/icons'
import { formatMinutes } from '@/lib/format'
import { useLiveStore } from '@/stores/live'

const { t, te } = useI18n()
const fmt = useFormatter()
const now = useNow()
const live = useLiveStore()
const report = useErrorReporter()
const pause = usePause()

const overview = ref<Overview | null>(null)

const seasonOptions = computed<SegmentOption<Season>[]>(() => [
  { value: 'winter', label: t('season.winter'), icon: mdiFire },
  { value: 'summer', label: t('season.summer'), icon: mdiSnowflake },
])

async function load(): Promise<void> {
  try {
    const [data] = await Promise.all([unwrap(api.GET('/api/overview')), live.loadSnapshot()])
    overview.value = data
  } catch (error) {
    report(error)
  }
}
onMounted(load)

async function setSeason(season: Season): Promise<void> {
  const current = overview.value
  if (!current) return
  const previous = current.season
  current.season = season
  try {
    await unwrap(api.PUT('/api/season', { body: { season } }))
    // The engine pushes the new statuses over SSE; the snapshot covers a stream that is down.
    await live.loadSnapshot()
  } catch (error) {
    current.season = previous
    report(error)
  }
}

async function setPaused(paused: boolean): Promise<void> {
  const current = overview.value
  if (!current) return
  const previous = current.paused
  current.paused = paused
  if (!(await pause.setPaused(paused))) current.paused = previous
}

/** Once the live statuses are known, their alerts replace the room-level ones of the overview. */
const alerts = computed<Alert[]>(() => {
  const base = overview.value?.alerts ?? []
  if (!live.loaded) return base
  const seen = new Set<string>()
  const merged: Alert[] = []
  for (const alert of [
    ...live.rooms.flatMap((r) => r.alerts),
    ...base.filter((a) => a.roomId === null),
  ]) {
    if (!seen.has(alert.key)) {
      seen.add(alert.key)
      merged.push(alert)
    }
  }
  return merged
})

const homeCount = computed(
  () => overview.value?.presence.persons.filter((p) => p.tracked && p.state === 'home').length ?? 0,
)
const runningCount = computed(
  () => live.rooms.filter((r) => r.devices.some((d) => d.running === true)).length,
)
const roomsSummary = computed(() => {
  return t(
    overview.value?.season === 'summer' ? 'overview.coolingSummary' : 'overview.heatingSummary',
    {
      running: runningCount.value,
      total: live.rooms.length,
    },
  )
})

function personState(state: string): string {
  const key = `overview.person.${state}`
  return te(key) ? t(key) : state
}
function condition(value: string): string {
  const key = `weather.${value}`
  return te(key) ? t(key) : value
}
</script>

<template>
  <template v-if="overview">
    <PausedBanner v-if="overview.paused" :busy="pause.busy.value" @resume="setPaused(false)" />

    <div class="grid-fit summary">
      <section class="card panel" aria-labelledby="pause-title">
        <h2 id="pause-title">{{ t('app.title') }}</h2>
        <PauseSwitch
          :paused="overview.paused"
          :busy="pause.busy.value"
          @update:paused="setPaused"
        />
      </section>

      <section class="card panel" aria-labelledby="season-title">
        <h2 id="season-title">{{ t('overview.season') }}</h2>
        <SegmentedControl
          :model-value="overview.season"
          :options="seasonOptions"
          :label="t('overview.season')"
          @update:model-value="setSeason"
        />
        <p class="hint" data-test="season-help">
          {{ t(`overview.seasonHelp.${overview.season}`) }}
        </p>
      </section>

      <section class="card panel" aria-labelledby="presence-title">
        <div class="card-head">
          <span class="chip round tone-ok" aria-hidden="true"
            ><HaIcon :path="mdiHome" :size="18"
          /></span>
          <h2 id="presence-title">{{ t('overview.presence') }}</h2>
        </div>
        <p class="big">{{ t('overview.home', homeCount) }}</p>
        <div class="chips">
          <span
            v-for="person in overview.presence.persons.filter((p) => p.tracked)"
            :key="person.entityId"
            class="chip"
            :class="person.state === 'home' ? 'tone-ok' : 'tone-neutral'"
          >
            {{ person.name }} · {{ personState(person.state) }}
          </span>
        </div>
        <p class="hint">
          <template v-if="overview.presence.houseEmptySince">
            {{
              t('overview.emptySince', { time: fmt.smart(overview.presence.houseEmptySince, now) })
            }}
          </template>
          <template v-else>
            {{
              t('overview.emptyHelp', {
                delay: formatMinutes(overview.presence.houseEmptyDelaySeconds / 60),
              })
            }}
          </template>
        </p>
      </section>

      <section class="card panel" aria-labelledby="weather-title">
        <div class="card-head">
          <span class="chip round tone-info" aria-hidden="true">
            <HaIcon :path="mdiWeatherPartlyCloudy" :size="18" />
          </span>
          <h2 id="weather-title">{{ t('overview.weather') }}</h2>
        </div>
        <template v-if="overview.weather">
          <p class="big">
            {{ fmt.temperature(overview.weather.temperature) }}
            <span class="muted cond">{{ condition(overview.weather.condition) }}</span>
          </p>
          <p class="hint">
            <template v-if="overview.weather.temperatureLow != null">
              {{ t('overview.min') }} {{ fmt.number(overview.weather.temperatureLow) }}&nbsp;° ·
            </template>
            <template v-if="overview.weather.temperatureHigh != null">
              {{ t('overview.max') }} {{ fmt.number(overview.weather.temperatureHigh) }}&nbsp;° ·
            </template>
            <template v-if="overview.weather.humidity != null">
              {{ t('overview.humidity') }} {{ fmt.number(overview.weather.humidity) }}&nbsp;% ·
            </template>
            {{ overview.weather.entityId }}
          </p>
        </template>
        <template v-else>
          <p class="muted">{{ t('overview.noWeather') }}</p>
          <div>
            <RouterLink class="btn" to="/settings">{{ t('overview.chooseWeather') }}</RouterLink>
          </div>
        </template>
      </section>

      <section class="card panel" aria-labelledby="vacation-title">
        <div class="card-head">
          <span class="chip round tone-neutral" aria-hidden="true">
            <HaIcon :path="mdiAirplane" :size="18" />
          </span>
          <h2 id="vacation-title">{{ t('overview.vacation') }}</h2>
        </div>
        <p class="big" data-test="vacation-state">
          {{ overview.vacation.enabled ? t('overview.vacationOn') : t('overview.vacationOff') }}
        </p>
        <p v-if="overview.vacation.enabled && overview.vacation.returnAt" class="hint">
          {{ t('overview.vacationReturn', { date: fmt.dateTime(overview.vacation.returnAt) }) }}
        </p>
        <div>
          <RouterLink class="btn" to="/settings">
            {{
              overview.vacation.enabled ? t('overview.vacationEdit') : t('overview.vacationPlan')
            }}
          </RouterLink>
        </div>
      </section>
    </div>

    <AlertList :alerts="alerts" :now="now" />

    <section aria-labelledby="rooms-title" class="stack rooms">
      <div class="rooms-head">
        <h2 id="rooms-title">{{ t('overview.rooms') }}</h2>
        <span class="muted" data-test="rooms-summary">{{ roomsSummary }}</span>
      </div>
      <p v-if="live.loaded && live.rooms.length === 0" class="muted">
        {{ t('overview.noRooms') }} <RouterLink to="/rooms/new">{{ t('rooms.add') }}</RouterLink>
      </p>
      <div class="room-grid">
        <RoomCard
          v-for="status in live.rooms"
          :key="status.roomId"
          :status="status"
          :season="overview.season"
          :now="now"
        />
      </div>
    </section>

    <section class="card" aria-labelledby="log-title" style="padding: 8px 0">
      <div class="log-head">
        <h2 id="log-title" class="h-section">{{ t('overview.recent') }}</h2>
        <RouterLink to="/history">{{ t('overview.allHistory') }}</RouterLink>
      </div>
      <ul class="log">
        <li v-for="entry in overview.recentDecisions ?? []" :key="entry.id">
          <span class="muted time">{{ fmt.time(entry.occurredAt) }}</span>
          <span>
            <strong v-if="entry.roomName">{{ entry.roomName }}</strong>
            <template v-if="entry.roomName"> · </template>{{ entry.message }}
          </span>
        </li>
      </ul>
    </section>
  </template>
  <p v-else class="muted" role="status">{{ t('common.loading') }}</p>
</template>

<style scoped>
.summary {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
}
.card-head {
  display: flex;
  align-items: center;
  gap: 10px;
}
.big {
  margin: 0;
  font-size: 22px;
  font-weight: 500;
}
.cond {
  font-size: 15px;
  font-weight: 400;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.rooms {
  gap: 12px;
}
.rooms-head {
  display: flex;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}
.rooms-head h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 500;
  flex: 1;
}
.room-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
  gap: 16px;
}
.log-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
}
.log-head h2 {
  flex: 1;
}
.log {
  list-style: none;
  margin: 0;
  padding: 0;
}
.log li {
  display: flex;
  gap: 16px;
  padding: 10px 16px;
  border-top: 1px solid var(--divider);
  font-size: 14px;
}
.time {
  width: 44px;
  flex: none;
}
</style>
