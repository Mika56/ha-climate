import { describe, expect, it } from 'vitest'
import { normalizeLocale, resolveLocale, resolveTheme } from './locale'

const none = { explicit: null, profile: null, server: null, browser: null }

describe('normalizeLocale', () => {
  it.each([
    ['fr', 'fr'],
    ['fr-FR', 'fr'],
    ['EN_gb', 'en'],
    ['de', null],
    ['', null],
    [null, null],
    [undefined, null],
  ])('%s -> %s', (tag, expected) => {
    expect(normalizeLocale(tag)).toBe(expected)
  })
})

describe('resolveLocale', () => {
  it('prefers the explicit choice over everything else', () => {
    expect(resolveLocale({ explicit: 'en', profile: 'fr', server: 'fr', browser: 'fr' })).toEqual({
      locale: 'en',
      source: 'explicit',
    })
  })

  it('then the Home Assistant profile language', () => {
    expect(resolveLocale({ ...none, profile: 'fr', server: 'en', browser: 'en' })).toEqual({
      locale: 'fr',
      source: 'profile',
    })
  })

  it('then the server language, then the browser', () => {
    expect(resolveLocale({ ...none, server: 'fr-FR', browser: 'en' })).toEqual({
      locale: 'fr',
      source: 'server',
    })
    expect(resolveLocale({ ...none, browser: 'fr-CA' })).toEqual({
      locale: 'fr',
      source: 'browser',
    })
  })

  it('falls back to English, also for an unsupported language', () => {
    expect(resolveLocale(none)).toEqual({ locale: 'en', source: 'fallback' })
    expect(resolveLocale({ ...none, profile: 'de', server: 'fr' })).toEqual({
      locale: 'en',
      source: 'profile',
    })
  })
})

describe('resolveTheme', () => {
  it('uses the explicit choice first', () => {
    expect(resolveTheme({ explicit: 'dark', haDark: false, prefersDark: false })).toEqual({
      dark: true,
      source: 'explicit',
    })
    expect(resolveTheme({ explicit: 'light', haDark: true, prefersDark: true }).dark).toBe(false)
  })

  it('then Home Assistant, then the system preference', () => {
    expect(resolveTheme({ explicit: 'auto', haDark: true, prefersDark: false })).toEqual({
      dark: true,
      source: 'home-assistant',
    })
    expect(resolveTheme({ explicit: 'auto', haDark: false, prefersDark: true }).dark).toBe(false)
    expect(resolveTheme({ explicit: 'auto', haDark: null, prefersDark: true })).toEqual({
      dark: true,
      source: 'system',
    })
  })
})
