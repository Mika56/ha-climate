<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import type { BoostInput, RoomStatus, Season } from '@/api/types'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useFormatter } from '@/composables/useFormatter'
import { useReason } from '@/composables/useReason'
import { mdiFire, mdiFlash, mdiPencil, mdiSnowflake } from '@/lib/icons'
import { roomActivity } from '@/lib/rooms'
import { useLiveStore } from '@/stores/live'
import { usePresetStore } from '@/stores/presets'
import BoostPanel from './BoostPanel.vue'
import HaIcon from './HaIcon.vue'

const props = defineProps<{ status: RoomStatus; season: Season; now: Date }>()

const { t, te } = useI18n()
const fmt = useFormatter()
const { reasonText } = useReason()
const presets = usePresetStore()
const live = useLiveStore()
const report = useErrorReporter()

const boostOpen = ref(false)
const busy = ref(false)

interface Badge {
  tone: string
  label: string
}

const mode = computed(() => (props.season === 'winter' ? 'heat' : 'cool'))
const activityState = computed(() => roomActivity(props.status))
const running = computed(() => activityState.value !== 'idle')
const icon = computed(() => (mode.value === 'heat' ? mdiFire : mdiSnowflake))
const iconTone = computed(() => {
  if (!running.value) return 'tone-neutral'
  return mode.value === 'heat' ? 'tone-heat' : 'tone-cool'
})
const activity = computed(() => {
  if (!running.value) return t('overview.activity.idle')
  if (activityState.value === 'on') return t('overview.activity.on')
  return mode.value === 'heat' ? t('overview.activity.heating') : t('overview.activity.cooling')
})

/** What the devices are actually set to in Home Assistant, whatever HA Climate decided. */
const applied = computed(() => {
  const parts = props.status.devices.map((d) => {
    const key = `hvac.${d.state ?? 'unknown'}`
    const label = te(key) ? t(key) : (d.state ?? t('hvac.unknown'))
    return d.targetTemperature !== null && d.state !== 'off'
      ? `${label} ${fmt.value.temperature(d.targetTemperature)}`
      : label
  })
  return [...new Set(parts)].join(' · ')
})

const reasonParams = computed(() => props.status.decision.reason.params)
const presetLabel = computed(() => {
  const override = props.status.override
  if (override?.type === 'manual') return t('overview.manual')
  const key = reasonParams.value.preset
  return typeof key === 'string' ? presets.label(key) : ''
})

const targetTone = computed(() => {
  if (props.status.decision.target === null) return 'muted'
  return mode.value === 'cool' ? 'tone-cool' : 'tone-heat'
})

const canBoost = computed(() => props.status.decision.mode !== 'off' && !props.status.override)
const pausedHint = computed(() => (props.status.paused ? t('pause.boostDisabled') : undefined))

const badges = computed<Badge[]>(() => {
  const out: Badge[] = []
  const { decision, facts, override, alerts } = props.status
  if (props.status.paused) out.push({ tone: 'tone-info', label: t('pause.simulation') })
  const code = decision.reason.code.replace(/^reason\./, '')
  if (facts.openingOpenSince && code === 'opening_open') {
    out.push({
      tone: 'tone-warn',
      label: t('badge.windowOpen', {
        duration: fmt.value.since(facts.openingOpenSince, props.now),
      }),
    })
  }
  if (alerts.some((a) => a.type === 'entity_unavailable')) {
    out.push({ tone: 'tone-error', label: t('badge.sensorUnavailable') })
  }
  if (alerts.some((a) => a.type === 'device_unresponsive')) {
    out.push({ tone: 'tone-error', label: t('badge.deviceUnresponsive') })
  }
  if (override?.type === 'manual') {
    out.push({
      tone: 'tone-info',
      label: t('badge.manual', {
        until: override.endsAt ? fmt.value.smart(override.endsAt, props.now) : t('reason.nextSlot'),
      }),
    })
  }
  if (override?.type === 'boost') {
    out.push({
      tone: 'tone-warn',
      label: t('badge.boost', {
        delta: fmt.value.delta(override.delta ?? 0, mode.value === 'cool' ? '−' : '+'),
        until: override.endsAt ? fmt.value.smart(override.endsAt, props.now) : t('reason.nextSlot'),
      }),
    })
  }
  if (facts.roomVacantSince && code === 'room_vacant') {
    out.push({
      tone: 'tone-neutral',
      label: t('badge.vacant', { duration: fmt.value.since(facts.roomVacantSince, props.now) }),
    })
  }
  if (code === 'house_empty') out.push({ tone: 'tone-neutral', label: t('badge.houseEmpty') })
  if (code === 'vacation') out.push({ tone: 'tone-info', label: t('badge.vacation') })
  if (code === 'preheat') out.push({ tone: 'tone-info', label: t('badge.preheat') })
  if (code.startsWith('anti_cycling'))
    out.push({ tone: 'tone-neutral', label: t('badge.antiCycling') })
  return out
})

async function startBoost(boost: BoostInput): Promise<void> {
  busy.value = true
  try {
    live.upsert(
      await unwrap(
        api.POST('/api/rooms/{roomId}/boost', {
          params: { path: { roomId: props.status.roomId } },
          body: boost,
        }),
      ),
    )
    boostOpen.value = false
  } catch (error) {
    report(error)
  } finally {
    busy.value = false
  }
}

async function cancelOverride(): Promise<void> {
  try {
    live.upsert(
      await unwrap(
        api.DELETE('/api/rooms/{roomId}/override', {
          params: { path: { roomId: props.status.roomId } },
        }),
      ),
    )
  } catch (error) {
    report(error)
  }
}
</script>

<template>
  <article class="card room-card" :aria-label="status.name">
    <div class="room-head">
      <span class="room-icon" :class="iconTone" aria-hidden="true"><HaIcon :path="icon" /></span>
      <div class="room-title">
        <h3>{{ status.name }}</h3>
        <p class="muted" data-test="activity">{{ activity }}</p>
      </div>
      <RouterLink
        class="icon-btn"
        :to="`/rooms/${status.roomId}`"
        :aria-label="t('overview.configure', { name: status.name })"
      >
        <HaIcon :path="mdiPencil" />
      </RouterLink>
    </div>

    <div class="room-temps">
      <div class="stack">
        <span class="muted small">{{ t('overview.current') }}</span>
        <span class="current" data-test="current">{{
          fmt.temperature(status.currentTemperature)
        }}</span>
      </div>
      <div class="stack">
        <span class="muted small">
          {{ status.paused ? t('overview.wouldApply') : t('overview.target')
          }}<template v-if="presetLabel"> · {{ presetLabel }}</template>
        </span>
        <span class="target" :class="targetTone" data-test="target">
          {{
            status.decision.target === null
              ? t('overview.off')
              : fmt.temperature(status.decision.target)
          }}
        </span>
      </div>
    </div>

    <p v-if="status.devices.length" class="muted small applied" data-test="applied">
      {{ t('overview.applied') }} <span class="applied-value">{{ applied }}</span>
    </p>

    <div class="badges">
      <span v-for="badge in badges" :key="badge.label" class="chip" :class="badge.tone">
        {{ badge.label }}
      </span>
    </div>
    <p class="muted small reason" data-test="reason">
      {{ reasonText(status.decision.reason, status.decision.mode) }}
    </p>

    <div class="actions">
      <button
        v-if="status.override"
        type="button"
        class="btn text"
        data-test="cancel-override"
        :disabled="status.paused"
        :title="pausedHint"
        @click="cancelOverride"
      >
        {{
          status.override.type === 'boost' ? t('overview.cancelBoost') : t('overview.cancelManual')
        }}
      </button>
      <button
        v-if="canBoost && !boostOpen"
        type="button"
        class="btn"
        data-test="boost-open"
        :disabled="status.paused"
        :title="pausedHint"
        @click="boostOpen = true"
      >
        <HaIcon :path="mdiFlash" :size="18" />{{ t('overview.boost') }}
      </button>
    </div>
    <BoostPanel
      v-if="boostOpen && !status.paused"
      :mode="mode"
      :busy="busy"
      @confirm="startBoost"
      @close="boostOpen = false"
    />
  </article>
</template>

<style scoped>
.room-card {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.room-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.room-icon {
  display: inline-flex;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  align-items: center;
  justify-content: center;
  flex: none;
}
.room-title {
  flex: 1;
  min-width: 0;
}
.room-title h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 500;
}
.room-title p,
.reason {
  margin: 0;
}
.small {
  font-size: 13px;
}
.room-temps {
  display: flex;
  gap: 24px;
  align-items: flex-end;
}
.room-temps .small {
  font-size: 12px;
}
.current {
  font-size: 30px;
  line-height: 36px;
}
.target {
  font-size: 20px;
  font-weight: 500;
  line-height: 28px;
  background: transparent;
}
.applied {
  margin: 0;
}
.applied-value {
  color: var(--text);
}
.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-height: 26px;
}
.reason {
  flex: 1;
}
</style>
