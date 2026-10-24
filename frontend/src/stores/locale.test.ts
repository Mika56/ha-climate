import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import type { SupportedLocale } from '@/i18n'
import { useLocaleStore } from './locale'

describe('locale store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('defaults to English', () => {
    expect(useLocaleStore().current).toBe('en')
  })

  it('accepts a supported locale', () => {
    const store = useLocaleStore()
    store.set('fr')
    expect(store.current).toBe('fr')
  })

  it('ignores an unsupported locale', () => {
    const store = useLocaleStore()
    store.set('de' as SupportedLocale)
    expect(store.current).toBe('en')
  })
})
