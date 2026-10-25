import { describe, expect, it } from 'vitest'
import {
  dayBoundIso,
  isoToLocalInput,
  localInputToIso,
  minutesToTime,
  timeToMinutes,
} from './datetime'

describe('time of day', () => {
  it('converts both ways', () => {
    expect(minutesToTime(0)).toBe('00:00')
    expect(minutesToTime(1439)).toBe('23:59')
    expect(timeToMinutes('08:30')).toBe(510)
    expect(timeToMinutes('8:05')).toBe(485)
  })

  it('rejects malformed times', () => {
    expect(timeToMinutes('')).toBeNull()
    expect(timeToMinutes('24:00')).toBeNull()
    expect(timeToMinutes('10:75')).toBeNull()
    expect(timeToMinutes('noon')).toBeNull()
  })
})

describe('zoned date-time inputs', () => {
  it('formats an instant as wall-clock time in the zone', () => {
    expect(isoToLocalInput('2026-11-08T17:00:00Z', 'Europe/Paris')).toBe('2026-11-08T18:00')
    expect(isoToLocalInput('2026-07-01T10:00:00Z', 'Europe/Paris')).toBe('2026-07-01T12:00')
    expect(isoToLocalInput(null, 'Europe/Paris')).toBe('')
  })

  it('adds the zone offset, winter and summer', () => {
    expect(localInputToIso('2026-11-08T18:00', 'Europe/Paris')).toBe('2026-11-08T18:00:00+01:00')
    expect(localInputToIso('2026-07-01T12:00', 'Europe/Paris')).toBe('2026-07-01T12:00:00+02:00')
    expect(localInputToIso('2026-01-01T08:00', 'America/New_York')).toBe(
      '2026-01-01T08:00:00-05:00',
    )
    expect(localInputToIso('2026-01-01T08:00', 'Asia/Kolkata')).toBe('2026-01-01T08:00:00+05:30')
  })

  it('handles the DST transition (offset changes inside the day)', () => {
    expect(localInputToIso('2026-03-29T01:00', 'Europe/Paris')).toBe('2026-03-29T01:00:00+01:00')
    expect(localInputToIso('2026-03-29T04:00', 'Europe/Paris')).toBe('2026-03-29T04:00:00+02:00')
    expect(localInputToIso('2026-10-25T04:00', 'Europe/Paris')).toBe('2026-10-25T04:00:00+01:00')
  })

  it('round-trips, and rejects malformed input', () => {
    const iso = localInputToIso('2026-11-08T18:00', 'Europe/Paris') as string
    expect(isoToLocalInput(iso, 'Europe/Paris')).toBe('2026-11-08T18:00')
    expect(localInputToIso('', 'Europe/Paris')).toBeNull()
    expect(localInputToIso('2026-11-08', 'Europe/Paris')).toBeNull()
  })

  it('computes the bounds of a day', () => {
    expect(dayBoundIso('2026-10-25', false, 'Europe/Paris')).toBe('2026-10-25T00:00:00+02:00')
    expect(dayBoundIso('2026-10-25', true, 'Europe/Paris')).toBe('2026-10-25T23:59:00+01:00')
  })
})
