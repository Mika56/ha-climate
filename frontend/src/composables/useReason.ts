import { useI18n } from 'vue-i18n'
import type { HvacMode, Reason } from '@/api/types'
import { usePresetStore } from '@/stores/presets'
import { useFormatter } from './useFormatter'

const TIME_PARAMS = new Set(['until', 'at', 'since'])

/** Translates a decision `Reason` (code + params) into a sentence. */
export function useReason() {
  const { t, te } = useI18n()
  const fmt = useFormatter()
  const presets = usePresetStore()

  function param(name: string, value: unknown, mode: HvacMode | undefined): string | number {
    const f = fmt.value
    if (TIME_PARAMS.has(name)) {
      return typeof value === 'string' && value !== '' ? f.smart(value) : t('reason.nextSlot')
    }
    if (name === 'returnAt') return typeof value === 'string' ? f.dateTime(value) : '—'
    if (name === 'preset') return presets.label(String(value))
    if (name === 'delta' && typeof value === 'number') {
      const negative = value < 0 || mode === 'cool'
      return f.delta(Math.abs(value), negative ? '−' : '+')
    }
    return typeof value === 'number' || typeof value === 'string' ? value : '—'
  }

  function reasonText(reason: Reason, mode?: HvacMode): string {
    const key = `reason.${reason.code.replace(/^reason\./, '')}`
    const params: Record<string, string | number> = {}
    for (const [name, value] of Object.entries(reason.params)) {
      params[name] = param(name, value, mode)
    }
    return te(key) ? t(key, params) : t('reason.unknown', { code: reason.code })
  }

  return { reasonText }
}
