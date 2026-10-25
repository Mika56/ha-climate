import type { Locale, ThemeChoice } from '@/api/types'

export const SUPPORTED_LOCALES: readonly Locale[] = ['en', 'fr']
export const FALLBACK_LOCALE: Locale = 'en'

/** BCP 47 tag handed to Intl for each UI language (24 h clock for English too). */
export const INTL_LOCALES: Record<Locale, string> = { en: 'en-GB', fr: 'fr-FR' }

export type LocaleSource = 'explicit' | 'profile' | 'server' | 'browser' | 'fallback'

export interface LocaleInputs {
  /** The user's own choice (null = automatic). */
  explicit: Locale | null
  /** Language of the Home Assistant user profile (from the parent frame). */
  profile: string | null
  /** Server language of Home Assistant (`/api/context.haLanguage`). */
  server: string | null
  /** `navigator.language`. */
  browser: string | null
}

/** `fr-FR` -> `fr`; unsupported or empty -> null. */
export function normalizeLocale(tag: string | null | undefined): Locale | null {
  const base = (tag ?? '').toLowerCase().split(/[-_]/)[0]
  return SUPPORTED_LOCALES.find((l) => l === base) ?? null
}

/**
 * explicit choice -> HA profile -> HA server language -> browser -> en.
 * The first source that provides a language decides; if it is unsupported (e.g. `de`) we use `en`.
 */
export function resolveLocale(input: LocaleInputs): { locale: Locale; source: LocaleSource } {
  if (input.explicit) return { locale: input.explicit, source: 'explicit' }
  const chain: [LocaleSource, string | null][] = [
    ['profile', input.profile],
    ['server', input.server],
    ['browser', input.browser],
  ]
  for (const [source, tag] of chain) {
    if (tag) return { locale: normalizeLocale(tag) ?? FALLBACK_LOCALE, source }
  }
  return { locale: FALLBACK_LOCALE, source: 'fallback' }
}

export type ThemeSource = 'explicit' | 'home-assistant' | 'system'

/** explicit choice -> `hass.themes.darkMode` -> `prefers-color-scheme`. */
export function resolveTheme(input: {
  explicit: ThemeChoice
  haDark: boolean | null
  prefersDark: boolean
}): { dark: boolean; source: ThemeSource } {
  if (input.explicit !== 'auto') return { dark: input.explicit === 'dark', source: 'explicit' }
  if (input.haDark !== null) return { dark: input.haDark, source: 'home-assistant' }
  return { dark: input.prefersDark, source: 'system' }
}
