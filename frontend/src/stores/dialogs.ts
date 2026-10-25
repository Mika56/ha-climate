import { defineStore } from 'pinia'
import { shallowRef } from 'vue'

export interface ConfirmOptions {
  title: string
  message: string
  confirmLabel: string
  cancelLabel: string
  danger?: boolean
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (confirmed: boolean) => void
}

/** Promise-based confirmation dialog, rendered once by <ConfirmHost>. */
export const useDialogStore = defineStore('dialogs', () => {
  const current = shallowRef<PendingConfirm | null>(null)

  function confirm(options: ConfirmOptions): Promise<boolean> {
    current.value?.resolve(false)
    return new Promise<boolean>((resolve) => {
      current.value = { ...options, resolve }
    })
  }

  function answer(confirmed: boolean): void {
    const pending = current.value
    current.value = null
    pending?.resolve(confirmed)
  }

  return { current, confirm, answer }
})
