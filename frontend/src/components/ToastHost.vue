<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { mdiClose } from '@/lib/icons'
import { useToastStore } from '@/stores/toasts'
import HaIcon from './HaIcon.vue'

const { t } = useI18n()
const toasts = useToastStore()
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="toast in toasts.items" :key="toast.id" class="toast" :class="toast.kind">
      <span>{{ toast.key ? t(toast.key, toast.params ?? {}) : toast.text }}</span>
      <button
        type="button"
        class="icon-btn"
        :aria-label="t('common.dismiss')"
        @click="toasts.dismiss(toast.id)"
      >
        <HaIcon :path="mdiClose" :size="18" />
      </button>
    </div>
  </div>
</template>
