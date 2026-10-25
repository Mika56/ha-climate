import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { fieldErrors } from '@/api/errors'
import { useErrorReporter } from './useErrorReporter'

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

export interface EditableForm<T> {
  form: Ref<T | null>
  errors: Ref<Record<string, string>>
  saving: Ref<boolean>
  dirty: ComputedRef<boolean>
  reset: (value: T) => void
  /** Runs `action`; maps a 422 to `errors`. Returns true on success. */
  save: (action: (value: T) => Promise<void>) => Promise<boolean>
}

/** Draft + dirty tracking + 422-to-fields mapping shared by every editing page. */
export function useEditableForm<T>(): EditableForm<T> {
  const report = useErrorReporter()
  const form = ref<T | null>(null) as Ref<T | null>
  const baseline = ref('')
  const errors = ref<Record<string, string>>({})
  const saving = ref(false)

  const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value)

  function reset(value: T): void {
    form.value = clone(value)
    baseline.value = JSON.stringify(value)
    errors.value = {}
  }

  async function save(action: (value: T) => Promise<void>): Promise<boolean> {
    if (form.value === null) return false
    saving.value = true
    errors.value = {}
    try {
      await action(form.value)
      baseline.value = JSON.stringify(form.value)
      return true
    } catch (error) {
      errors.value = fieldErrors(error)
      report(error)
      return false
    } finally {
      saving.value = false
    }
  }

  return { form, errors, saving, dirty, reset, save }
}
