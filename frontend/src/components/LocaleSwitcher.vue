<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n'
import { useLocaleStore } from '@/stores/locale'

const { t, locale } = useI18n()
const store = useLocaleStore()

function select(next: SupportedLocale): void {
  store.set(next)
  locale.value = store.current
}
</script>

<template>
  <div role="group" :aria-label="t('locale.label')">
    <button
      v-for="code in SUPPORTED_LOCALES"
      :key="code"
      type="button"
      :data-locale="code"
      :aria-pressed="locale === code"
      @click="select(code)"
    >
      {{ t(`locale.${code}`) }}
    </button>
  </div>
</template>
