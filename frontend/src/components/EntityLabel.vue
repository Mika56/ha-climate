<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { entityIcon, isActiveState, isUnavailable } from '@/lib/entities'
import HaIcon from './HaIcon.vue'

const props = defineProps<{
  entityId: string
  /** Friendly name; the entity id stands in when the entity is unknown. */
  name?: string | null
  state?: string | null
  unit?: string | null
  deviceClass?: string | null
  areaName?: string | null
  /** Show the current state as a chip on the right. */
  showState?: boolean
}>()

const { t, te } = useI18n()
const NBSP = ' '

const stateText = computed(() => {
  const state = props.state ?? null
  if (state === null) return null
  const key = `picker.state.${state}`
  const text = te(key) ? t(key) : state
  return props.unit && !isUnavailable(state) ? `${text}${NBSP}${props.unit}` : text
})
const tone = computed(() => {
  if (isUnavailable(props.state ?? null)) return 'tone-warn'
  return isActiveState(props.state ?? null) ? 'tone-ok' : 'tone-neutral'
})
</script>

<template>
  <span class="entity-label">
    <span class="ico" aria-hidden="true">
      <HaIcon :path="entityIcon({ entityId, deviceClass })" :size="22" />
    </span>
    <span class="text">
      <span class="name">{{ name || entityId }}</span>
      <span class="sub">
        <code>{{ entityId }}</code>
        <template v-if="areaName"> · {{ areaName }}</template>
      </span>
    </span>
    <span v-if="showState && stateText" class="chip state" :class="tone" data-test="entity-state">
      {{ stateText }}
    </span>
  </span>
</template>

<style scoped>
.entity-label {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  flex: 1;
  text-align: left;
}
.ico {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  flex: none;
  border-radius: 50%;
  background: var(--neutral-tint);
  color: var(--text2);
}
.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.name {
  font-size: 15px;
  font-weight: 500;
  color: var(--text);
  overflow-wrap: anywhere;
}
.sub {
  font-size: 12px;
  color: var(--text2);
  overflow-wrap: anywhere;
}
.sub code {
  font: inherit;
}
.state {
  flex: none;
  max-width: 40%;
  overflow-wrap: anywhere;
}
</style>
