import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { useDialogStore } from '@/stores/dialogs'

/** Asks for confirmation before leaving (route change or tab close) while `dirty` is true. */
export function useDirtyGuard(dirty: Ref<boolean>): void {
  const { t } = useI18n()
  const dialogs = useDialogStore()

  const ask = async (): Promise<boolean> => {
    if (!dirty.value) return true
    return dialogs.confirm({
      title: t('guard.title'),
      message: t('guard.message'),
      confirmLabel: t('guard.leave'),
      cancelLabel: t('guard.stay'),
      danger: true,
    })
  }

  onBeforeRouteLeave(ask)
  onBeforeRouteUpdate(ask)

  const beforeUnload = (event: BeforeUnloadEvent): void => {
    if (dirty.value) event.preventDefault()
  }
  onMounted(() => window.addEventListener('beforeunload', beforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
}
