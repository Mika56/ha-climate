<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DiscoveredEntity, SlotStart } from '@/api/types'
import { minutesToTime, timeToMinutes } from '@/lib/datetime'
import { startMinute, type EditorSlot } from '@/lib/schedule'
import { usePresetStore } from '@/stores/presets'
import EntitySelect from './EntitySelect.vue'
import FormField from './FormField.vue'
import UnitInput from './UnitInput.vue'

const props = defineProps<{
  item: EditorSlot
  timeEntities: DiscoveredEntity[]
  canDelete: boolean
  entityError?: string
}>()
const emit = defineEmits<{
  change: [patch: Partial<Pick<EditorSlot, 'preset' | 'start'>>]
  split: []
  delete: []
}>()

const { t } = useI18n()
const presets = usePresetStore()

const anchored = computed(() => (props.item.start.type === 'anchored' ? props.item.start : null))
const fixed = computed(() => (props.item.start.type === 'fixed' ? props.item.start : null))

function setType(type: SlotStart['type']): void {
  if (type === props.item.start.type) return
  const minute = startMinute(props.item.start)
  emit('change', {
    start:
      type === 'fixed'
        ? { type: 'fixed', minuteOfDay: minute }
        : {
            type: 'anchored',
            entityId: props.timeEntities[0]?.entityId ?? '',
            offsetMinutes: 0,
            fallbackMinuteOfDay: minute,
          },
  })
}

function setFixedTime(event: Event): void {
  const minutes = timeToMinutes((event.target as HTMLInputElement).value)
  if (minutes !== null) emit('change', { start: { type: 'fixed', minuteOfDay: minutes } })
}

function setFallback(event: Event): void {
  const a = anchored.value
  const minutes = timeToMinutes((event.target as HTMLInputElement).value)
  if (a && minutes !== null) emit('change', { start: { ...a, fallbackMinuteOfDay: minutes } })
}

function patchAnchored(patch: { entityId?: string; offsetMinutes?: number }): void {
  const a = anchored.value
  if (a) emit('change', { start: { ...a, ...patch } })
}
</script>

<template>
  <div class="slot-form">
    <div class="grid-fit wide">
      <FormField :label="t('schedules.preset')">
        <select
          class="input"
          :value="item.preset"
          data-test="slot-preset"
          @change="emit('change', { preset: ($event.target as HTMLSelectElement).value })"
        >
          <option v-for="p in presets.sorted" :key="p.key" :value="p.key">{{ p.label }}</option>
        </select>
      </FormField>

      <div class="field" role="radiogroup" :aria-label="t('schedules.startType')">
        <span>{{ t('schedules.startType') }}</span>
        <label class="radio">
          <input
            type="radio"
            name="start-type"
            :checked="!!fixed"
            data-test="start-fixed"
            @change="setType('fixed')"
          />
          {{ t('schedules.fixed') }}
        </label>
        <label class="radio">
          <input
            type="radio"
            name="start-type"
            :checked="!!anchored"
            data-test="start-anchored"
            @change="setType('anchored')"
          />
          {{ t('schedules.anchored') }}
        </label>
      </div>

      <FormField v-if="fixed" :label="t('schedules.time')">
        <input
          class="input"
          type="time"
          :value="minutesToTime(fixed.minuteOfDay)"
          data-test="slot-time"
          @change="setFixedTime"
        />
      </FormField>

      <div v-if="anchored" class="stack anchored">
        <FormField :label="t('schedules.entity')" :error="entityError">
          <EntitySelect
            :model-value="anchored.entityId || null"
            :entities="timeEntities"
            data-test="slot-entity"
            @update:model-value="patchAnchored({ entityId: $event ?? '' })"
          />
        </FormField>
        <FormField :label="t('schedules.offset')">
          <UnitInput
            :model-value="anchored.offsetMinutes"
            :unit="t('units.min')"
            :min="-720"
            :max="720"
            data-test="slot-offset"
            @update:model-value="patchAnchored({ offsetMinutes: $event })"
          />
        </FormField>
        <FormField :label="t('schedules.fallback')">
          <input
            class="input"
            type="time"
            :value="minutesToTime(anchored.fallbackMinuteOfDay)"
            data-test="slot-fallback"
            @change="setFallback"
          />
        </FormField>
      </div>
    </div>
    <div class="actions">
      <button type="button" class="btn text" data-test="split-slot" @click="emit('split')">
        {{ t('schedules.split') }}
      </button>
      <button
        type="button"
        class="btn text danger"
        :disabled="!canDelete"
        data-test="delete-slot"
        @click="emit('delete')"
      >
        {{ t('common.delete') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.slot-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.wide {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 20px;
}
.anchored {
  gap: 12px;
}
</style>
