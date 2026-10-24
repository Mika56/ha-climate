import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import App from '@/App.vue'
import { createAppI18n } from '@/i18n'
import { useLocaleStore } from '@/stores/locale'
import LocaleSwitcher from './LocaleSwitcher.vue'

function mountApp() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createAppI18n()
  return mount(App, { global: { plugins: [pinia, i18n] } })
}

describe('LocaleSwitcher', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('renders English by default', () => {
    const wrapper = mountApp()
    expect(wrapper.text()).toContain('Heating and air conditioning, driven by events.')
  })

  it('switches to French and back', async () => {
    const wrapper = mountApp()
    await wrapper.get('[data-locale="fr"]').trigger('click')
    expect(wrapper.text()).toContain('Chauffage et climatisation')
    expect(wrapper.get('[data-locale="fr"]').attributes('aria-pressed')).toBe('true')

    await wrapper.get('[data-locale="en"]').trigger('click')
    expect(wrapper.text()).toContain('Heating and air conditioning')
  })

  it('keeps the store in sync with the selection', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(LocaleSwitcher, { global: { plugins: [pinia, createAppI18n()] } })
    await wrapper.get('[data-locale="fr"]').trigger('click')
    expect(useLocaleStore().current).toBe('fr')
  })
})
