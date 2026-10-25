<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { mdiPause } from '@/lib/icons'
import HaIcon from './HaIcon.vue'

defineProps<{ busy?: boolean }>()
defineEmits<{ resume: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="banner paused-banner tone-warn" role="status" data-test="pause-banner">
    <HaIcon :path="mdiPause" :size="22" />
    <p class="paused-text">{{ t('pause.banner') }}</p>
    <button
      type="button"
      class="btn filled"
      :disabled="busy"
      data-test="pause-resume"
      @click="$emit('resume')"
    >
      {{ t('pause.resume') }}
    </button>
  </div>
</template>

<style scoped>
.paused-banner {
  flex-wrap: wrap;
  border-radius: 12px;
  padding: 14px 16px;
  font-size: 15px;
  font-weight: 500;
}
.paused-text {
  flex: 1;
  min-width: 220px;
  margin: 0;
}
</style>
