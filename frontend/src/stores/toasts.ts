import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface Toast {
  id: number
  kind: 'info' | 'success' | 'error'
  /** i18n key (translated by the host) ... */
  key?: string
  params?: Record<string, unknown>
  /** ... or an already-translated text (server messages). */
  text?: string
}

export const TOAST_DURATION_MS = 6000

export const useToastStore = defineStore('toasts', () => {
  const items = ref<Toast[]>([])
  let nextId = 1

  function dismiss(id: number): void {
    items.value = items.value.filter((t) => t.id !== id)
  }

  function push(toast: Omit<Toast, 'id'>): number {
    const id = nextId++
    items.value.push({ ...toast, id })
    setTimeout(() => dismiss(id), TOAST_DURATION_MS)
    return id
  }

  const success = (key: string, params?: Record<string, unknown>) =>
    push({ kind: 'success', key, params })
  const error = (key: string, params?: Record<string, unknown>) =>
    push({ kind: 'error', key, params })
  const errorText = (text: string) => push({ kind: 'error', text })

  return { items, push, dismiss, success, error, errorText }
})
