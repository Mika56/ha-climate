import { computed, onScopeDispose, shallowRef, type ComputedRef, type ShallowRef } from 'vue'
import { createFormatter, type Formatter } from '@/lib/format'
import { usePreferencesStore } from '@/stores/preferences'

/** Intl formatters for the resolved UI language, in the Home Assistant time zone. */
export function useFormatter(): ComputedRef<Formatter> {
  const prefs = usePreferencesStore()
  return computed(() => createFormatter(prefs.resolvedLocale.locale, prefs.timeZone))
}

/** A clock that ticks every `intervalMs` (for "open for 32 min" style labels). */
export function useNow(intervalMs = 30000): ShallowRef<Date> {
  const now = shallowRef(new Date())
  const timer = setInterval(() => {
    now.value = new Date()
  }, intervalMs)
  onScopeDispose(() => clearInterval(timer))
  return now
}
