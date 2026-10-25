<script setup lang="ts" generic="N extends number | null">
const props = withDefaults(
  defineProps<{
    modelValue: N
    unit: string
    label?: string
    step?: number
    min?: number
    max?: number
    invalid?: boolean
    /** Allow an empty value (emits null). */
    nullable?: boolean
  }>(),
  { step: 1, min: undefined, max: undefined, label: undefined, invalid: false, nullable: false },
)
const emit = defineEmits<{ 'update:modelValue': [value: N] }>()

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value
  if (raw === '') {
    if (props.nullable) emit('update:modelValue', null as N)
    return
  }
  const value = Number(raw)
  if (!Number.isNaN(value)) emit('update:modelValue', value as N)
}
</script>

<template>
  <div class="unit">
    <input
      class="input"
      type="number"
      inputmode="decimal"
      :value="modelValue ?? ''"
      :step="step"
      :min="min"
      :max="max"
      :aria-label="label"
      :aria-invalid="invalid || undefined"
      @input="onInput"
    />
    <span>{{ unit }}</span>
  </div>
</template>
