import type { Locale } from '@/api/types'
import { INTL_LOCALES } from './locale'

const NBSP = ' '

/** Returns the zone when Intl knows it, otherwise undefined (browser zone). */
export function safeTimeZone(zone: string | null | undefined): string | undefined {
  if (!zone) return undefined
  try {
    new Intl.DateTimeFormat('en', { timeZone: zone })
    return zone
  } catch {
    return undefined
  }
}

export function formatMinutes(total: number): string {
  const minutes = Math.max(0, Math.round(total))
  if (minutes < 60) return `${minutes}${NBSP}min`
  const h = Math.floor(minutes / 60)
  const rest = minutes % 60
  return `${h}${NBSP}h${rest > 0 ? ` ${String(rest).padStart(2, '0')}` : ''}`
}

export interface Formatter {
  time: (iso: string) => string
  date: (iso: string) => string
  dateTime: (iso: string) => string
  /** Time only when the instant is today (in the HA zone), otherwise date and time. */
  smart: (iso: string, now?: Date) => string
  /** `20,4 °C` with a no-break space; `—` for null. */
  temperature: (value: number | null | undefined) => string
  /** `+1,5 °C` / `−1,5 °C` (a delta, signed). */
  delta: (value: number, sign: '+' | '−') => string
  number: (value: number, digits?: number) => string
  /** Minutes elapsed between `iso` and `now`. */
  since: (iso: string, now: Date) => string
}

export function createFormatter(locale: Locale, timeZone: string | null): Formatter {
  const tag = INTL_LOCALES[locale]
  const tz = safeTimeZone(timeZone)
  const timeFmt = new Intl.DateTimeFormat(tag, { hour: '2-digit', minute: '2-digit', timeZone: tz })
  const dateFmt = new Intl.DateTimeFormat(tag, { day: 'numeric', month: 'short', timeZone: tz })
  const dayKey = new Intl.DateTimeFormat('en-CA', { timeZone: tz })
  const oneDecimal = new Intl.NumberFormat(tag, {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  })

  const time = (iso: string): string => timeFmt.format(new Date(iso))
  const date = (iso: string): string => dateFmt.format(new Date(iso))
  return {
    time,
    date,
    dateTime: (iso) => `${date(iso)} ${time(iso)}`,
    smart: (iso, now = new Date()) =>
      dayKey.format(new Date(iso)) === dayKey.format(now) ? time(iso) : `${date(iso)} ${time(iso)}`,
    temperature: (value) =>
      value === null || value === undefined ? `—${NBSP}°C` : `${oneDecimal.format(value)}${NBSP}°C`,
    delta: (value, sign) => `${sign}${oneDecimal.format(value)}${NBSP}°C`,
    number: (value, digits = 0) =>
      new Intl.NumberFormat(tag, { maximumFractionDigits: digits }).format(value),
    since: (iso, now) => formatMinutes((now.getTime() - new Date(iso).getTime()) / 60000),
  }
}
