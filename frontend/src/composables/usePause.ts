import { ref } from 'vue'
import { api, unwrap } from '@/api/client'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useLiveStore } from '@/stores/live'
import { useToastStore } from '@/stores/toasts'

/**
 * Pauses or resumes HA Climate (`PUT /api/pause`). The room statuses are loaded again afterwards: the engine
 * publishes them over SSE, the snapshot covers a stream that is down.
 */
export function usePause() {
  const toasts = useToastStore()
  const live = useLiveStore()
  const report = useErrorReporter()
  const busy = ref(false)

  /** @returns whether the change was applied */
  async function setPaused(paused: boolean): Promise<boolean> {
    busy.value = true
    try {
      await unwrap(api.PUT('/api/pause', { body: { paused } }))
      toasts.success(paused ? 'pause.pausedToast' : 'pause.resumedToast')
      await live.loadSnapshot()
      return true
    } catch (error) {
      report(error)
      return false
    } finally {
      busy.value = false
    }
  }

  return { busy, setPaused }
}
