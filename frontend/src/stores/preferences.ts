import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, unwrap } from '@/api/client'
import { ApiError } from '@/api/errors'
import type { Context, Locale, ThemeChoice } from '@/api/types'
import { readHaParent, observeHaParent } from '@/lib/haParent'
import { normalizeLocale, resolveLocale, resolveTheme } from '@/lib/locale'
import { useToastStore } from './toasts'

const STORAGE_KEY = 'ha-climate.preferences'

interface StoredPreferences {
  locale: Locale | null
  theme: ThemeChoice
}

function readStored(): StoredPreferences | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<StoredPreferences>
    const theme = parsed.theme
    return {
      locale: normalizeLocale(parsed.locale),
      theme: theme === 'light' || theme === 'dark' ? theme : 'auto',
    }
  } catch {
    return null
  }
}

function writeStored(value: StoredPreferences): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
  } catch {
    // Storage blocked (private mode, cleared site data): the choice only lasts for this session.
  }
}

/** Language and theme: user choice + what Home Assistant (parent frame) tells us. */
export const usePreferencesStore = defineStore('preferences', () => {
  const explicitLocale = ref<Locale | null>(null)
  const explicitTheme = ref<ThemeChoice>('auto')

  const haLanguage = ref<string | null>(null)
  const haDark = ref<boolean | null>(null)
  const haThemeVars = ref<Record<string, string>>({})
  const serverLanguage = ref<string | null>(null)
  const timeZone = ref<string | null>(null)
  const prefersDark = ref(false)

  const resolvedLocale = computed(() =>
    resolveLocale({
      explicit: explicitLocale.value,
      profile: haLanguage.value,
      server: serverLanguage.value,
      browser: typeof navigator === 'undefined' ? null : navigator.language,
    }),
  )
  const resolvedTheme = computed(() =>
    resolveTheme({
      explicit: explicitTheme.value,
      haDark: haDark.value,
      prefersDark: prefersDark.value,
    }),
  )
  /** Theme variables are only copied when our effective mode matches Home Assistant's. */
  const appliedThemeVars = computed(() => {
    const matches = haDark.value === null || haDark.value === resolvedTheme.value.dark
    return matches ? haThemeVars.value : {}
  })

  function refreshEnvironment(): void {
    const snapshot = readHaParent()
    haLanguage.value = snapshot?.language ?? null
    haDark.value = snapshot?.darkMode ?? null
    haThemeVars.value = snapshot?.themeVars ?? {}
  }

  /** Starts following Home Assistant (parent frame) and the OS colour scheme; returns a disposer. */
  function startObserving(): () => void {
    refreshEnvironment()
    const stopParent = observeHaParent(refreshEnvironment)
    let stopMedia = (): void => {}
    if (typeof window.matchMedia === 'function') {
      const media = window.matchMedia('(prefers-color-scheme: dark)')
      prefersDark.value = media.matches
      const onChange = (e: MediaQueryListEvent): void => {
        prefersDark.value = e.matches
      }
      media.addEventListener('change', onChange)
      stopMedia = () => media.removeEventListener('change', onChange)
    }
    return () => {
      stopParent()
      stopMedia()
    }
  }

  function init(context: Context): void {
    serverLanguage.value = context.haLanguage
    timeZone.value = context.haTimeZone
    explicitLocale.value = context.preferences.locale
    explicitTheme.value = context.preferences.theme
    if (context.preferences.locale === null && context.preferences.theme === 'auto') {
      const stored = readStored()
      if (stored) {
        explicitLocale.value = stored.locale
        explicitTheme.value = stored.theme
      }
    }
  }

  async function persist(): Promise<void> {
    const body = { locale: explicitLocale.value, theme: explicitTheme.value }
    try {
      await unwrap(api.PUT('/api/me/preferences', { body }))
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        // No Home Assistant user on this request (standalone access): keep the choice in this browser.
        writeStored(body)
      } else if (!(error instanceof ApiError && error.status === 0)) {
        useToastStore().error('errors.generic')
      }
    }
  }

  async function setLocale(locale: Locale | null): Promise<void> {
    explicitLocale.value = locale
    await persist()
  }

  async function setTheme(theme: ThemeChoice): Promise<void> {
    explicitTheme.value = theme
    await persist()
  }

  return {
    explicitLocale,
    explicitTheme,
    haLanguage,
    haDark,
    serverLanguage,
    timeZone,
    resolvedLocale,
    resolvedTheme,
    appliedThemeVars,
    refreshEnvironment,
    startObserving,
    init,
    setLocale,
    setTheme,
  }
})
