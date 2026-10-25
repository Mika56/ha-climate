import { createI18n } from 'vue-i18n'
import type { Locale } from '@/api/types'
import { FALLBACK_LOCALE } from '@/lib/locale'
import en from './locales/en.json'
import fr from './locales/fr.json'

export const messages = { en, fr }

export function createAppI18n(locale: Locale = FALLBACK_LOCALE) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: FALLBACK_LOCALE,
    messages,
    // French treats 0 and 1 as singular (two forms); with three forms: none | one | many.
    pluralRules: {
      fr: (choice: number, choicesLength: number) =>
        choicesLength === 2 ? (choice < 2 ? 0 : 1) : Math.min(choice, 2),
    },
  })
}
