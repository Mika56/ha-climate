import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import type { HvacMode, Reason } from '@/api/types'
import { createAppI18n } from '@/i18n'
import { useAppStore } from '@/stores/app'
import { norm } from '@/test/utils'
import { settle } from '@/test/utils'
import { useReason } from './useReason'

async function translator(locale: 'fr' | 'en') {
  const pinia = createPinia()
  setActivePinia(pinia)
  await useAppStore().bootstrap()
  let reasonText!: (reason: Reason, mode?: HvacMode) => string
  mount(
    defineComponent({
      setup() {
        reasonText = useReason().reasonText
        return () => h('div')
      },
    }),
    { global: { plugins: [pinia, createAppI18n(locale)] } },
  )
  await settle(1)
  return reasonText
}

const at = (hours: number, minutes: number) =>
  new Date(Date.UTC(2099, 0, 1, hours, minutes)).toISOString()

describe('useReason', () => {
  it('translates a schedule reason with a preset label and a time', async () => {
    const text = await translator('fr')
    const out = norm(
      text({ code: 'reason.schedule', params: { preset: 'comfort', until: at(21, 30) } }),
    )
    expect(out).toMatch(/^Planning : Confort jusqu’à .*\d\d:\d\d\.$/)
  })

  it('accepts codes with or without the "reason." prefix', async () => {
    const text = await translator('en')
    expect(text({ code: 'season_off', params: {} })).toBe('Season is “Off”: no device is driven.')
    expect(text({ code: 'reason.season_off', params: {} })).toBe(
      'Season is “Off”: no device is driven.',
    )
  })

  it('signs a boost by the HVAC mode and formats numbers with the locale', async () => {
    const fr = await translator('fr')
    const heat = norm(
      fr({ code: 'reason.boost', params: { delta: 1.5, until: at(10, 0) } }, 'heat'),
    )
    expect(heat).toContain('Boost +1,5 °C')
    const cool = norm(
      fr({ code: 'reason.boost', params: { delta: 1.5, until: at(10, 0) } }, 'cool'),
    )
    expect(cool).toContain('Boost −1,5 °C')
    expect(norm(fr({ code: 'reason.boost', params: { delta: -2, until: at(10, 0) } }))).toContain(
      '−2,0 °C',
    )
  })

  it('shows "next slot" when the end is open and a dash for missing values', async () => {
    const fr = await translator('fr')
    expect(norm(fr({ code: 'reason.manual_override', params: { until: null } }))).toContain(
      'reprise du planning à le prochain créneau',
    )
    expect(fr({ code: 'reason.vacation', params: { returnAt: null } })).toContain('—')
    expect(
      fr({ code: 'reason.schedule', params: { preset: 'x', until: at(1, 1), extra: true } }),
    ).toContain('x')
    expect(fr({ code: 'reason.vacation', params: { returnAt: at(18, 0) } })).toContain(
      'retour prévu le',
    )
  })

  it('falls back for unknown codes', async () => {
    const en = await translator('en')
    expect(en({ code: 'reason.martian', params: {} })).toBe('Decision: reason.martian')
  })

  it('translates every code of the contract in both languages', async () => {
    const codes = [
      'schedule',
      'preheat',
      'season_off',
      'no_device',
      'no_schedule',
      'no_rule',
      'vacation',
      'house_empty',
      'opening_open',
      'boost',
      'manual_override',
      'room_vacant',
      'anti_cycling_min_off',
      'anti_cycling_min_on',
    ]
    for (const locale of ['fr', 'en'] as const) {
      const text = await translator(locale)
      for (const code of codes) {
        const out = text({
          code: `reason.${code}`,
          params: {
            preset: 'eco',
            until: at(9, 0),
            at: at(9, 0),
            since: at(8, 0),
            returnAt: at(9, 0),
            delta: 1,
          },
        })
        expect(out, code).not.toContain('Decision:')
        expect(out, code).not.toContain('Décision')
        expect(out, code).not.toMatch(/\{\w+\}/)
      }
    }
  })
})
