import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { createAppI18n, messages } from './index'

type Tree = { [key: string]: string | Tree }

function flatten(tree: Tree, prefix = ''): string[] {
  return Object.entries(tree).flatMap(([key, value]) =>
    typeof value === 'string' ? [`${prefix}${key}`] : flatten(value, `${prefix}${key}.`),
  )
}

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory())
      return name === 'test' || name === 'mocks' ? [] : sources(path)
    return /\.(vue|ts)$/.test(name) && !name.endsWith('.test.ts') && !name.endsWith('.d.ts')
      ? [path]
      : []
  })
}

const enKeys = new Set(flatten(messages.en))
const frKeys = new Set(flatten(messages.fr))

/** Reason codes of api/openapi.yaml (Reason.code description) with their params. */
const REASONS: Record<string, string[]> = {
  schedule: ['preset', 'until'],
  preheat: ['preset', 'at'],
  season_off: [],
  no_device: [],
  no_schedule: [],
  no_rule: [],
  vacation: ['returnAt'],
  house_empty: ['since'],
  opening_open: ['since'],
  boost: ['delta', 'until'],
  manual_override: ['until'],
  room_vacant: ['preset', 'since'],
  anti_cycling_min_off: [],
  anti_cycling_min_on: [],
}

describe('catalogs', () => {
  it('have exactly the same keys in English and French', () => {
    expect([...enKeys].filter((k) => !frKeys.has(k))).toEqual([])
    expect([...frKeys].filter((k) => !enKeys.has(k))).toEqual([])
  })

  it('define every key used literally in the sources', () => {
    const used = new Set<string>()
    for (const file of sources(join(__dirname, '..'))) {
      const text = readFileSync(file, 'utf8')
      for (const match of text.matchAll(
        /\b(?:t|te|toasts\.(?:success|error))\('([a-zA-Z0-9_.-]+)'/g,
      )) {
        used.add(match[1])
      }
    }
    expect(used.size).toBeGreaterThan(150)
    expect([...used].filter((k) => !enKeys.has(k))).toEqual([])
    expect([...used].filter((k) => !frKeys.has(k))).toEqual([])
  })

  it('translate every reason code of the contract with its params', () => {
    for (const [code, params] of Object.entries(REASONS)) {
      for (const locale of ['en', 'fr'] as const) {
        const message = (messages[locale].reason as Record<string, string>)[code]
        expect(message, `reason.${code} in ${locale}`).toBeTypeOf('string')
        for (const param of params) expect(message).toContain(`{${param}}`)
      }
    }
  })

  it('translate every error code of the contract used by the UI', () => {
    for (const code of ['no_active_mode', 'no_enabled_target', 'preset_in_use', 'not_found']) {
      expect(enKeys.has(`errors.codes.${code}`)).toBe(true)
    }
  })

  it('use French typography: no-break space before ":", "?" and "»"', () => {
    const offenders = [...frKeys].filter((key) => {
      const value = key.split('.').reduce<unknown>((o, k) => (o as Tree)[k], messages.fr) as string
      return / [:?!;»]/.test(value) || /« /.test(value)
    })
    expect(offenders).toEqual([])
  })

  it('pluralise through vue-i18n (French treats 0 and 1 as singular)', () => {
    const fr = createAppI18n('fr').global
    expect(fr.t('overview.home', 0)).toBe('0 personne à la maison')
    expect(fr.t('overview.home', 1)).toBe('1 personne à la maison')
    expect(fr.t('overview.home', 2)).toBe('2 personnes à la maison')
    expect(fr.t('settings.presetUsage', 0)).toBe('aucun planning')
    expect(fr.t('settings.presetUsage', 1)).toBe('1 planning')
    expect(fr.t('settings.presetUsage', 3)).toBe('3 plannings')
    const en = createAppI18n('en').global
    expect(en.t('overview.home', 1)).toBe('1 person at home')
    expect(en.t('overview.home', 3)).toBe('3 people at home')
  })
})
