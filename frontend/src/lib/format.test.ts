import { describe, expect, it } from 'vitest'
import { createFormatter, formatMinutes, safeTimeZone } from './format'

describe('formatMinutes', () => {
  it.each([
    [0, '0\u00a0min'],
    [32.4, '32\u00a0min'],
    [60, '1\u00a0h'],
    [95, '1\u00a0h 35'],
    [-5, '0\u00a0min'],
  ])('%s -> %s', (value, expected) => {
    expect(formatMinutes(value)).toBe(expected)
  })
})

describe('safeTimeZone', () => {
  it('keeps a valid zone and drops an unknown one', () => {
    expect(safeTimeZone('Europe/Paris')).toBe('Europe/Paris')
    expect(safeTimeZone('Mars/Olympus')).toBeUndefined()
    expect(safeTimeZone(null)).toBeUndefined()
  })
})

describe('createFormatter', () => {
  const iso = '2026-10-25T16:05:00Z'

  it('formats times in the Home Assistant zone (24 h)', () => {
    expect(createFormatter('fr', 'Europe/Paris').time(iso)).toBe('17:05')
    expect(createFormatter('en', 'America/New_York').time(iso)).toBe('12:05')
    expect(createFormatter('en', 'Mars/Olympus').time(iso)).toMatch(/^\d\d:\d\d$/)
  })

  it('formats dates and chooses between time-only and date+time', () => {
    const fmt = createFormatter('fr', 'Europe/Paris')
    expect(fmt.date(iso)).toContain('25')
    expect(fmt.dateTime(iso)).toContain('17:05')
    expect(fmt.smart(iso, new Date('2026-10-25T20:00:00Z'))).toBe('17:05')
    expect(fmt.smart(iso, new Date('2026-10-26T20:00:00Z'))).toContain('25')
    expect(fmt.smart(iso)).toBeTruthy()
  })

  it('formats temperatures and deltas with a no-break space and the locale decimal mark', () => {
    const fr = createFormatter('fr', null)
    expect(fr.temperature(20.4)).toBe('20,4\u00a0°C')
    expect(fr.temperature(null)).toBe('—\u00a0°C')
    expect(fr.temperature(undefined)).toBe('—\u00a0°C')
    expect(fr.delta(1.5, '+')).toBe('+1,5\u00a0°C')
    expect(fr.delta(2, '−')).toBe('−2,0\u00a0°C')
    expect(createFormatter('en', null).temperature(20.4)).toBe('20.4\u00a0°C')
  })

  it('formats numbers and elapsed durations', () => {
    const fr = createFormatter('fr', null)
    expect(fr.number(1.25, 1)).toBe('1,3')
    expect(fr.number(12418)).toMatch(/^12.418$/)
    expect(fr.since('2026-10-25T10:00:00Z', new Date('2026-10-25T10:32:00Z'))).toBe('32\u00a0min')
  })
})
