<script setup lang="ts" generic="T extends string">
import HaIcon from './HaIcon.vue'

export interface SegmentOption<V extends string> {
  value: V
  label: string
  icon?: string
}

defineProps<{ modelValue: T; options: SegmentOption<T>[]; label: string }>()
defineEmits<{ 'update:modelValue': [value: T] }>()
</script>

<template>
  <div class="seg" role="group" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      class="seg-btn"
      :class="{ on: option.value === modelValue }"
      :aria-pressed="option.value === modelValue"
      @click="$emit('update:modelValue', option.value)"
    >
      <HaIcon v-if="option.icon" :path="option.icon" :size="18" />
      {{ option.label }}
    </button>
  </div>
</template>
