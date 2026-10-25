import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, apiHooks, unwrap } from '@/api/client'
import type { Context } from '@/api/types'
import { usePreferencesStore } from './preferences'
import { usePresetStore } from './presets'
import { useToastStore } from './toasts'

/** Boot context, cross-cutting banners (engine unavailable) and API hook wiring. */
export const useAppStore = defineStore('app', () => {
  const context = ref<Context | null>(null)
  const booted = ref(false)
  const bootFailed = ref(false)
  const engineUnavailable = ref(false)

  function wireApiHooks(): void {
    const toasts = useToastStore()
    apiHooks.onNetworkError = () => toasts.error('errors.network')
    apiHooks.onEngineUnavailable = () => {
      engineUnavailable.value = true
    }
    apiHooks.onEngineAvailable = () => {
      engineUnavailable.value = false
    }
  }

  async function bootstrap(): Promise<void> {
    wireApiHooks()
    bootFailed.value = false
    try {
      const ctx = await unwrap(api.GET('/api/context'))
      context.value = ctx
      usePreferencesStore().init(ctx)
      await usePresetStore().load()
      engineUnavailable.value = !ctx.engine.connected
      booted.value = true
    } catch {
      bootFailed.value = true
    }
  }

  return { context, booted, bootFailed, engineUnavailable, bootstrap }
})
