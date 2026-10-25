<script setup lang="ts">
defineProps<{ label: string; error?: string }>()
</script>

<template>
  <label class="field">
    <span>{{ label }}</span>
    <slot />
    <span v-if="error" class="err" role="alert">{{ error }}</span>
  </label>
</template>
