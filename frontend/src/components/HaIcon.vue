<script setup lang="ts">
withDefaults(defineProps<{ path: string; size?: number }>(), { size: 20 })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path :d="path" fill="currentColor" />
  </svg>
</template>
