import { http, HttpResponse } from 'msw'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Context } from '@/api/types'
import { buildContext } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { server } from '@/test/server'
import { usePreferencesStore } from './preferences'
import { useToastStore } from './toasts'

const context = (patch: Partial<Context> = {}): Context => ({ ...buildContext(), ...patch })

function parentWith(hass: unknown, vars: Record<string, string> = {}) {
  const root = document.createElement('html')
  const parent = {
    document: { querySelector: () => ({ hass }), documentElement: root },
    getComputedStyle: () => ({ getPropertyValue: (n: string) => vars[n] ?? '' }),
  }
  vi.spyOn(window, 'parent', 'get').mockReturnValue(parent as unknown as Window)
  return root
}

describe('preferences store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('takes the server language when nothing else is known', () => {
    const prefs = usePreferencesStore()
    prefs.init(context({ haLanguage: 'fr', haTimeZone: 'Europe/Paris' }))
    vi.stubGlobal('navigator', { language: 'en-US' })
    expect(prefs.resolvedLocale).toEqual({ locale: 'fr', source: 'server' })
    expect(prefs.timeZone).toBe('Europe/Paris')
    expect(prefs.resolvedTheme.source).toBe('system')
  })

  it('prefers the Home Assistant profile language and theme over the server', () => {
    parentWith({ language: 'en', themes: { darkMode: true } }, { '--primary-color': '#ff9800' })
    const prefs = usePreferencesStore()
    prefs.init(context({ haLanguage: 'fr' }))
    prefs.refreshEnvironment()
    expect(prefs.resolvedLocale).toEqual({ locale: 'en', source: 'profile' })
    expect(prefs.resolvedTheme).toEqual({ dark: true, source: 'home-assistant' })
    expect(prefs.appliedThemeVars).toEqual({ '--primary-color': '#ff9800' })
  })

  it('does not copy Home Assistant theme variables when the user forces the other mode', async () => {
    parentWith({ language: 'fr', themes: { darkMode: true } }, { '--primary-color': '#ff9800' })
    const prefs = usePreferencesStore()
    prefs.init(context())
    prefs.refreshEnvironment()
    await prefs.setTheme('light')
    expect(prefs.resolvedTheme).toEqual({ dark: false, source: 'explicit' })
    expect(prefs.appliedThemeVars).toEqual({})
  })

  it('an unsupported profile language means English', () => {
    parentWith({ language: 'de' })
    const prefs = usePreferencesStore()
    prefs.init(context({ haLanguage: 'fr' }))
    prefs.refreshEnvironment()
    expect(prefs.resolvedLocale).toEqual({ locale: 'en', source: 'profile' })
  })

  it('works when the parent cannot be read (standalone or cross-origin)', () => {
    vi.spyOn(window, 'parent', 'get').mockImplementation(() => {
      throw new DOMException('Blocked a frame', 'SecurityError')
    })
    const prefs = usePreferencesStore()
    prefs.init(context({ haLanguage: null }))
    prefs.refreshEnvironment()
    vi.stubGlobal('navigator', { language: 'fr-CH' })
    expect(prefs.haLanguage).toBeNull()
    expect(prefs.resolvedLocale).toEqual({ locale: 'fr', source: 'browser' })
  })

  it('persists an explicit choice with PUT /api/me/preferences', async () => {
    const prefs = usePreferencesStore()
    prefs.init(context())
    await prefs.setLocale('en')
    await prefs.setTheme('dark')
    expect(mockDb.preferences).toEqual({ locale: 'en', theme: 'dark' })
    expect(prefs.resolvedLocale).toEqual({ locale: 'en', source: 'explicit' })
    expect(localStorage.getItem('ha-climate.preferences')).toBeNull()
    await prefs.setLocale(null)
    expect(mockDb.preferences.locale).toBeNull()
  })

  it('falls back to localStorage on 409 and reads it back', async () => {
    mockDb.context.user = null
    const prefs = usePreferencesStore()
    prefs.init(context({ user: null }))
    await prefs.setLocale('fr')
    await prefs.setTheme('dark')
    expect(JSON.parse(localStorage.getItem('ha-climate.preferences') ?? '{}')).toEqual({
      locale: 'fr',
      theme: 'dark',
    })

    setActivePinia(createPinia())
    const fresh = usePreferencesStore()
    fresh.init(context({ user: null }))
    expect(fresh.explicitLocale).toBe('fr')
    expect(fresh.explicitTheme).toBe('dark')
  })

  it('ignores corrupt or blocked localStorage', async () => {
    localStorage.setItem('ha-climate.preferences', '{broken')
    const prefs = usePreferencesStore()
    prefs.init(context())
    expect(prefs.explicitLocale).toBeNull()

    localStorage.setItem('ha-climate.preferences', JSON.stringify({ locale: 'de', theme: 'neon' }))
    prefs.init(context())
    expect(prefs.explicitLocale).toBeNull()
    expect(prefs.explicitTheme).toBe('auto')

    mockDb.context.user = null
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    await expect(prefs.setLocale('en')).resolves.toBeUndefined()
    expect(prefs.explicitLocale).toBe('en')
  })

  it('toasts unexpected failures but stays quiet on network errors', async () => {
    const prefs = usePreferencesStore()
    const toasts = useToastStore()
    prefs.init(context())
    server.use(
      http.put('*/api/me/preferences', () => HttpResponse.json({ error: 'x' }, { status: 500 })),
    )
    await prefs.setTheme('dark')
    expect(toasts.items).toHaveLength(1)
    server.use(http.put('*/api/me/preferences', () => HttpResponse.error()))
    await prefs.setTheme('light')
    expect(toasts.items.filter((t) => t.key === 'errors.generic')).toHaveLength(1)
  })

  it('tracks the system colour scheme and the parent frame until disposed', async () => {
    const media: { listener: ((e: MediaQueryListEvent) => void) | null } = { listener: null }
    const removeEventListener = vi.fn()
    vi.stubGlobal('matchMedia', () => ({
      matches: true,
      addEventListener: (_: string, l: (e: MediaQueryListEvent) => void) => (media.listener = l),
      removeEventListener,
    }))
    const root = parentWith({ language: 'fr', themes: { darkMode: false } })
    const prefs = usePreferencesStore()
    prefs.init(context({ haLanguage: null }))
    const stop = prefs.startObserving()
    expect(prefs.haLanguage).toBe('fr')

    root.setAttribute('lang', 'en')
    parentWith({ language: 'en', themes: { darkMode: true } })
    await new Promise((r) => setTimeout(r, 0))
    expect(prefs.haLanguage).toBe('en')
    expect(prefs.haDark).toBe(true)

    media.listener?.({ matches: false } as MediaQueryListEvent)
    stop()
    expect(removeEventListener).toHaveBeenCalled()
  })

  it('copes with a browser without matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined)
    const prefs = usePreferencesStore()
    prefs.init(context())
    expect(() => prefs.startObserving()()).not.toThrow()
  })
})
