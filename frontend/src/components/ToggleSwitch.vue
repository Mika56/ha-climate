<script setup lang="ts">
defineProps<{ modelValue: boolean; label: string }>()
defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <input
    class="switch"
    type="checkbox"
    role="switch"
    :checked="modelValue"
    :aria-label="label"
    @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
  />
</template>
