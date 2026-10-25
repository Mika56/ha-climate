import { http, HttpResponse } from 'msw'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockDb } from '@/mocks/db'
import { FakeEventSource } from '@/mocks/fakeEventSource'
import { useDialogStore } from '@/stores/dialogs'
import { useToastStore } from '@/stores/toasts'
import { server } from '@/test/server'
import { mountApp, norm, resetApiHooks, settle, until } from '@/test/utils'

afterEach(() => {
  vi.restoreAllMocks()
  resetApiHooks()
})

function parentWith(hass: unknown, vars: Record<string, string> = {}) {
  const parent = {
    document: { querySelector: () => ({ hass }), documentElement: document.createElement('html') },
    getComputedStyle: () => ({ getPropertyValue: (n: string) => vars[n] ?? '' }),
  }
  vi.spyOn(window, 'parent', 'get').mockReturnValue(parent as unknown as Window)
}

describe('App shell', () => {
  it('renders the header, the tabs and the overview in French (HA server language)', async () => {
    const { wrapper } = await mountApp('/')
    expect(wrapper.find('h1').text()).toBe('HA Climate')
    expect(wrapper.findAll('.tab').map((t) => t.text())).toEqual([
      'Vue d’ensemble',
      'Pièces',
      'Plannings',
      'Réglages',
      'Notifications',
      'Historique',
    ])
    expect(document.documentElement.lang).toBe('fr')
    expect(wrapper.find('.tab.router-link-exact-active').text()).toBe('Vue d’ensemble')
  })

  it('navigates with the tabs and redirects unknown routes', async () => {
    const { wrapper, router } = await mountApp('/')
    await wrapper.findAll('.tab')[3].trigger('click')
    await until(() => expect(router.currentRoute.value.path).toBe('/settings'))
    await router.push('/nope/at/all')
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('shows "Auto · <resolved>" first in the language selector and applies a choice', async () => {
    const { wrapper } = await mountApp('/')
    const select = wrapper.find<HTMLSelectElement>('[data-test="language-select"]')
    expect(norm(select.findAll('option')[0].text())).toBe('Auto · Français')
    expect(select.element.value).toBe('auto')

    select.element.value = 'en'
    await select.trigger('change')
    await settle()
    expect(mockDb.preferences.locale).toBe('en')
    expect(wrapper.findAll('.tab')[0].text()).toBe('Overview')
    expect(document.documentElement.lang).toBe('en')
    expect(select.findAll('option')[0].text()).toBe('Auto · English')

    select.element.value = 'auto'
    await select.trigger('change')
    await settle()
    expect(wrapper.findAll('.tab')[0].text()).toBe('Vue d’ensemble')
  })

  it('follows the Home Assistant profile language and theme from the parent frame', async () => {
    parentWith({ language: 'en', themes: { darkMode: true } }, { '--primary-color': '#ff9800' })
    const { wrapper } = await mountApp('/')
    expect(wrapper.findAll('.tab')[0].text()).toBe('Overview')
    const root = wrapper.find('.hc')
    expect(root.classes()).toEqual(expect.arrayContaining(['dark', 'ha-themed']))
    expect(root.attributes('style')).toContain('--primary-color: #ff9800')
    expect(document.documentElement.style.colorScheme).toBe('dark')
  })

  it('honours an explicit light theme even when Home Assistant is dark', async () => {
    mockDb.preferences = { locale: null, theme: 'light' }
    parentWith({ language: 'fr', themes: { darkMode: true } }, { '--primary-color': '#ff9800' })
    const { wrapper } = await mountApp('/')
    expect(wrapper.find('.hc').classes()).not.toContain('dark')
    expect(wrapper.find('.hc').attributes('style') ?? '').not.toContain('--primary-color')
  })

  it('shows a banner while the engine is unavailable and hides it once the API answers', async () => {
    mockDb.context.engine = { running: true, connected: false }
    const { wrapper } = await mountApp('/rooms')
    expect(wrapper.find('[data-test="engine-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="engine-banner"]').text()).toContain('moteur ne répond pas')
    // Any successful call proves the add-on is back.
    await wrapper.findAll('.tab')[1].trigger('click')
    await until(() => expect(wrapper.find('.room-item').exists()).toBe(true))
    await wrapper.find('.room-item').trigger('click')
    await until(() => expect(wrapper.find('[data-test="engine-banner"]').exists()).toBe(false))
  })

  it('shows the engine banner on a 503', async () => {
    server.use(
      http.get('*/api/status', () =>
        HttpResponse.json({ error: 'engine_unavailable' }, { status: 503 }),
      ),
    )
    const { wrapper } = await mountApp('/')
    expect(wrapper.find('[data-test="engine-banner"]').exists()).toBe(true)
  })

  it('shows a toast on network errors', async () => {
    const { wrapper } = await mountApp('/')
    server.use(http.get('*/api/rooms', () => HttpResponse.error()))
    await wrapper.findAll('.tab')[1].trigger('click')
    await until(() => expect(wrapper.find('.toasts').text()).toContain('Erreur réseau'))
  })

  it('offers a retry when the boot context cannot be loaded', async () => {
    server.use(http.get('*/api/context', () => HttpResponse.error()))
    const { wrapper } = await mountApp('/')
    expect(wrapper.text()).toContain('The add-on could not be reached.')
    server.resetHandlers()
    await wrapper.find('[role="alert"] button').trigger('click')
    await settle()
    expect(wrapper.find('[data-test="language-select"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Saison')
  })

  it('hides the engine banner as soon as the live stream is connected', async () => {
    mockDb.context.engine = { running: true, connected: false }
    const { wrapper } = await mountApp('/notifications')
    expect(wrapper.find('[data-test="engine-banner"]').exists()).toBe(true)
    FakeEventSource.last?.open()
    await until(() => expect(wrapper.find('[data-test="engine-banner"]').exists()).toBe(false))
  })

  it('shows a discreet indicator when the live connection is lost', async () => {
    const { wrapper } = await mountApp('/')
    expect(wrapper.find('[data-test="live-lost"]').exists()).toBe(false)
    FakeEventSource.last?.open()
    FakeEventSource.last?.fail(false)
    await settle(1)
    expect(wrapper.find('[data-test="live-lost"]').text()).toContain('Connexion en direct perdue')
    FakeEventSource.last?.open()
    await settle(1)
    expect(wrapper.find('[data-test="live-lost"]').exists()).toBe(false)
  })

  it('dismisses toasts and closes dialogs with Escape', async () => {
    const { wrapper, pinia } = await mountApp('/')
    const toasts = useToastStore(pinia)
    toasts.success('rooms.saved')
    await settle(1)
    expect(wrapper.find('.toast').text()).toContain('Pièce enregistrée.')
    await wrapper.find('.toast button').trigger('click')
    expect(wrapper.find('.toast').exists()).toBe(false)

    const dialogs = useDialogStore(pinia)
    const answer = dialogs.confirm({
      title: 'Sure?',
      message: 'Really',
      confirmLabel: 'Yes',
      cancelLabel: 'No',
    })
    await settle(1)
    await wrapper.find('.backdrop').trigger('keydown', { key: 'Escape' })
    await expect(answer).resolves.toBe(false)
    const second = dialogs.confirm({
      title: 'a',
      message: 'b',
      confirmLabel: 'c',
      cancelLabel: 'd',
    })
    const third = dialogs.confirm({ title: 'a', message: 'b', confirmLabel: 'c', cancelLabel: 'd' })
    await expect(second).resolves.toBe(false)
    await settle(1)
    await wrapper.find('.backdrop').trigger('click')
    await expect(third).resolves.toBe(false)
  })
})
