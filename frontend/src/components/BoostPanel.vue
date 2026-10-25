<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BoostInput, HvacMode } from '@/api/types'
import { useFormatter } from '@/composables/useFormatter'
import { mdiMinus, mdiPlus } from '@/lib/icons'
import HaIcon from './HaIcon.vue'

const props = defineProps<{ mode: HvacMode; busy?: boolean }>()
const emit = defineEmits<{ confirm: [boost: BoostInput]; close: [] }>()

const { t } = useI18n()
const fmt = useFormatter()

const MIN_DELTA = 0.5
const MAX_DELTA = 5
const STEP = 0.5

const durations = [
  { id: '30', minutes: 30, label: '30 min' },
  { id: '60', minutes: 60, label: '1 h' },
  { id: '120', minutes: 120, label: '2 h' },
  { id: 'next', minutes: null, label: '' },
]

const delta = ref(1.5)
const duration = ref('60')

const sign = computed(() => (props.mode === 'cool' ? '−' : '+'))
const deltaLabel = computed(() => fmt.value.delta(delta.value, sign.value))

function confirm(): void {
  const chosen = durations.find((d) => d.id === duration.value)
  emit(
    'confirm',
    chosen?.minutes
      ? { delta: delta.value, durationMinutes: chosen.minutes }
      : { delta: delta.value, untilNextSlot: true },
  )
}
</script>

<template>
  <div class="boost-panel">
    <p class="boost-title">
      {{ mode === 'cool' ? t('boost.titleCool') : t('boost.titleHeat') }}
    </p>
    <div class="boost-stepper">
      <button
        type="button"
        class="icon-btn outlined"
        :aria-label="t('boost.decrease')"
        :disabled="delta <= MIN_DELTA"
        data-test="boost-dec"
        @click="delta = Math.max(MIN_DELTA, delta - STEP)"
      >
        <HaIcon :path="mdiMinus" :size="18" />
      </button>
      <span class="boost-delta" aria-live="polite" data-test="boost-delta">{{ deltaLabel }}</span>
      <button
        type="button"
        class="icon-btn outlined"
        :aria-label="t('boost.increase')"
        :disabled="delta >= MAX_DELTA"
        data-test="boost-inc"
        @click="delta = Math.min(MAX_DELTA, delta + STEP)"
      >
        <HaIcon :path="mdiPlus" :size="18" />
      </button>
    </div>
    <p class="muted boost-label">{{ t('boost.duration') }}</p>
    <div class="boost-durations" role="group" :aria-label="t('boost.durationGroup')">
      <button
        v-for="d in durations"
        :key="d.id"
        type="button"
        class="pill"
        :class="{ on: duration === d.id }"
        :aria-pressed="duration === d.id"
        :data-test="`boost-duration-${d.id}`"
        @click="duration = d.id"
      >
        {{ d.minutes ? d.label : t('boost.nextSlot') }}
      </button>
    </div>
    <div class="actions">
      <button type="button" class="btn text" @click="emit('close')">
        {{ t('common.cancel') }}
      </button>
      <button
        type="button"
        class="btn filled"
        :disabled="busy"
        data-test="boost-confirm"
        @click="confirm"
      >
        {{ t('boost.start') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.boost-panel {
  border-top: 1px solid var(--divider);
  padding-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.boost-title {
  margin: 0;
  font-size: 14px;
  font-weight: 500;
}
.boost-stepper {
  display: flex;
  align-items: center;
  gap: 12px;
}
.boost-delta {
  font-size: 24px;
  font-weight: 500;
  min-width: 96px;
  text-align: center;
}
.boost-label {
  margin: 0;
  font-size: 13px;
}
.boost-durations {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
