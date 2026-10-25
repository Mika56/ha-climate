import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import App from '@/App.vue'
import { apiHooks } from '@/api/client'
import { createAppI18n } from '@/i18n'
import { createAppRouter } from '@/router'

export interface MountedApp {
  wrapper: VueWrapper
  router: ReturnType<typeof createAppRouter>
  pinia: ReturnType<typeof createPinia>
}

/** Mounts the whole application on a memory router, booted against the MSW handlers. */
export async function mountApp(path = '/'): Promise<MountedApp> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const wrapper = mount(App, {
    global: { plugins: [pinia, createAppI18n(), router] },
    attachTo: document.body,
  })
  await settle()
  return { wrapper, router, pinia }
}

/** Lets pending requests, route loading and re-renders finish. */
export async function settle(times = 6): Promise<void> {
  for (let i = 0; i < times; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

export function resetApiHooks(): void {
  apiHooks.onNetworkError = () => {}
  apiHooks.onEngineUnavailable = () => {}
  apiHooks.onEngineAvailable = () => {}
}

export function setInput(wrapper: VueWrapper, selector: string, value: string): Promise<void> {
  const el = wrapper.find<HTMLInputElement | HTMLSelectElement>(selector)
  el.element.value = value
  return el.trigger(el.element.tagName === 'SELECT' ? 'change' : 'input')
}

/** Text with no-break spaces turned into plain spaces (French typography). */
export const norm = (text: string | null | undefined): string =>
  (text ?? '').replace(/[\u00a0\u202f]/g, ' ')

export function setChange(wrapper: VueWrapper, selector: string, value: string): Promise<void> {
  const el = wrapper.find<HTMLInputElement>(selector)
  el.element.value = value
  return el.trigger('change')
}

/** Polls until `check` stops throwing (lazy route chunks load in real time). */
export async function until(check: () => void, timeout = 3000): Promise<void> {
  const start = Date.now()
  for (;;) {
    try {
      check()
      return
    } catch (error) {
      if (Date.now() - start > timeout) throw error
      await new Promise((resolve) => setTimeout(resolve, 20))
      await flushPromises()
    }
  }
}
