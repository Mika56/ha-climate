<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DiscoveredEntity } from '@/api/types'

const props = defineProps<{
  modelValue: string | null
  entities: DiscoveredEntity[]
  label?: string
  /** Text of the "no entity" option; omit to make a choice mandatory. */
  emptyLabel?: string
  invalid?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()
const { t } = useI18n()

const NBSP = '\u00a0'

function describe(entity: DiscoveredEntity): string {
  if (entity.state === null) return entity.entityId
  return `${entity.entityId} (${entity.state}${entity.unit ? `${NBSP}${entity.unit}` : ''})`
}

const options = computed(() => {
  const list = props.entities.map((e) => ({ value: e.entityId, text: describe(e) }))
  const current = props.modelValue
  if (current && !list.some((o) => o.value === current)) {
    list.unshift({ value: current, text: `${current} (${t('common.notFound')})` })
  }
  return list
})

function onChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  emit('update:modelValue', value === '' ? null : value)
}
</script>

<template>
  <select
    class="input"
    :value="modelValue ?? ''"
    :aria-label="label"
    :aria-invalid="invalid || undefined"
    @change="onChange"
  >
    <option v-if="emptyLabel !== undefined || modelValue === null" value="">
      {{ emptyLabel ?? t('common.choose') }}
    </option>
    <option v-for="option in options" :key="option.value" :value="option.value">
      {{ option.text }}
    </option>
  </select>
</template>
