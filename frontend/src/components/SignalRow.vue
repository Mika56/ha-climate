<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PresenceSignal, PresenceSignalKind } from '@/api/types'
import type { PickerEntity } from '@/lib/entities'
import { mdiDelete } from '@/lib/icons'
import { newSignal } from '@/lib/rooms'
import EntityLabel from './EntityLabel.vue'
import HaIcon from './HaIcon.vue'
import SegmentedControl, { type SegmentOption } from './SegmentedControl.vue'
import UnitInput from './UnitInput.vue'

const props = defineProps<{
  modelValue: PresenceSignal
  /** The discovered entity; absent when it no longer exists in Home Assistant. */
  entity?: PickerEntity
  /** Names suggested for an "equals" signal (the areas of Home Assistant). */
  suggestions: string[]
  errors: { entityId?: string; kind?: string; threshold?: string; value?: string }
}>()
const emit = defineEmits<{ 'update:modelValue': [value: PresenceSignal]; remove: [] }>()

const { t } = useI18n()
const listId = useId()

const kinds = computed<SegmentOption<PresenceSignalKind>[]>(() => [
  { value: 'active', label: t('signals.kind.active') },
  { value: 'above', label: t('signals.kind.above') },
  { value: 'equals', label: t('signals.kind.equals') },
])

const unit = computed(() => props.entity?.unit || 'W')
const hint = computed(() => {
  const s = props.modelValue
  if (s.kind === 'above')
    return t('signals.hints.above', { threshold: s.threshold ?? '…', unit: unit.value })
  if (s.kind === 'equals') return t('signals.hints.equals', { value: s.value || '…' })
  return t('signals.hints.active')
})
/** Known values: the areas, then the current state of the entity. */
const options = computed(() => {
  const state = props.entity?.state
  const extra = state && !['unavailable', 'unknown'].includes(state) ? [state] : []
  return [...new Set([...props.suggestions, ...extra])]
})

function setKind(kind: PresenceSignalKind): void {
  emit(
    'update:modelValue',
    newSignal(props.entity ?? { ...props.modelValue, state: null }, kind, props.modelValue),
  )
}
function setThreshold(threshold: number | null): void {
  emit('update:modelValue', { ...props.modelValue, threshold })
}
function setValue(event: Event): void {
  emit('update:modelValue', {
    ...props.modelValue,
    value: (event.target as HTMLInputElement).value,
  })
}
</script>

<template>
  <li class="signal card" data-test="signal-row">
    <div class="head">
      <EntityLabel
        :entity-id="modelValue.entityId"
        :name="entity?.name"
        :state="entity?.state"
        :unit="entity?.unit"
        :device-class="entity?.deviceClass"
        :area-name="entity ? null : t('common.notFound')"
        show-state
      />
      <button
        type="button"
        class="icon-btn"
        :aria-label="t('rooms.removeEntity', { entity: modelValue.entityId })"
        data-test="signal-remove"
        @click="emit('remove')"
      >
        <HaIcon :path="mdiDelete" :size="20" />
      </button>
    </div>

    <SegmentedControl
      :model-value="modelValue.kind"
      :options="kinds"
      :label="t('signals.kindOf', { entity: modelValue.entityId })"
      class="kind"
      data-test="signal-kind"
      @update:model-value="setKind"
    />

    <div v-if="modelValue.kind === 'above'" class="param">
      <UnitInput
        :model-value="modelValue.threshold ?? null"
        :unit="unit"
        nullable
        :step="1"
        :label="t('signals.thresholdOf', { entity: modelValue.entityId })"
        :invalid="!!errors.threshold"
        data-test="signal-threshold"
        @update:model-value="setThreshold"
      />
    </div>
    <div v-else-if="modelValue.kind === 'equals'" class="param">
      <input
        class="input"
        type="text"
        :value="modelValue.value ?? ''"
        :list="listId"
        maxlength="255"
        :placeholder="t('signals.valuePlaceholder')"
        :aria-label="t('signals.valueOf', { entity: modelValue.entityId })"
        :aria-invalid="!!errors.value || undefined"
        data-test="signal-value"
        @input="setValue"
      />
      <datalist :id="listId">
        <option v-for="option in options" :key="option" :value="option" />
      </datalist>
    </div>

    <p class="hint">{{ hint }}</p>
    <p v-if="errors.entityId" class="err" role="alert">{{ errors.entityId }}</p>
    <p v-if="errors.kind" class="err" role="alert">{{ errors.kind }}</p>
    <p v-if="errors.threshold" class="err" role="alert" data-test="signal-error">
      {{ errors.threshold }}
    </p>
    <p v-if="errors.value" class="err" role="alert" data-test="signal-error">{{ errors.value }}</p>
  </li>
</template>

<style scoped>
.signal {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 8px 12px 12px;
  list-style: none;
  border: 1px solid var(--divider);
  box-shadow: none;
}
.head {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}
.kind {
  align-self: flex-start;
  max-width: 100%;
}
.param {
  max-width: 280px;
  padding-right: 4px;
}
.hint,
.err {
  margin: 0;
  padding-right: 4px;
}
</style>
