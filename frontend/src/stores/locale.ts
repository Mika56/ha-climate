import { defineStore } from 'pinia'
import { ref } from 'vue'
import { SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n'

export const useLocaleStore = defineStore('locale', () => {
  const current = ref<SupportedLocale>('en')

  function set(locale: SupportedLocale): void {
    if (SUPPORTED_LOCALES.includes(locale)) {
      current.value = locale
    }
  }

  return { current, set }
})
