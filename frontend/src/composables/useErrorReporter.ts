import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/errors'
import { useToastStore } from '@/stores/toasts'

/**
 * Shows a toast for an unexpected API failure. Already-handled cases stay silent:
 * network errors (toasted by the API layer), 503 (engine banner) and 422 (mapped to form fields).
 */
export function useErrorReporter(): (error: unknown) => void {
  const { t, te } = useI18n()
  const toasts = useToastStore()
  return (error) => {
    if (error instanceof ApiError) {
      if (error.status === 0 || error.status === 503 || error.status === 422) return
      const key = `errors.codes.${error.code}`
      toasts.push({ kind: 'error', text: te(key) ? t(key) : t('errors.generic') })
      return
    }
    toasts.push({ kind: 'error', text: t('errors.generic') })
  }
}
