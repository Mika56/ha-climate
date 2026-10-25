/** Helpers converting between `<input type="datetime-local">` values and RFC 3339 in a given zone. */

const pad = (n: number, width = 2): string => String(n).padStart(width, '0')

export function minutesToTime(minuteOfDay: number): string {
  return `${pad(Math.floor(minuteOfDay / 60))}:${pad(minuteOfDay % 60)}`
}

/** `"08:30"` -> 510; null when malformed. */
export function timeToMinutes(value: string): number | null {
  const match = /^(\d{1,2}):(\d{2})$/.exec(value)
  if (!match) return null
  const minutes = Number(match[1]) * 60 + Number(match[2])
  return Number(match[1]) < 24 && Number(match[2]) < 60 ? minutes : null
}

interface Parts {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

function partsInZone(instant: number, timeZone: string | undefined): Parts {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
  const out: Record<string, number> = {}
  for (const p of fmt.formatToParts(new Date(instant))) {
    if (p.type !== 'literal') out[p.type] = Number(p.value)
  }
  return out as unknown as Parts
}

/** Offset of the zone from UTC at an instant, in minutes. */
function offsetMinutes(instant: number, timeZone: string | undefined): number {
  const p = partsInZone(instant, timeZone)
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second)
  return Math.round((asUtc - Math.floor(instant / 1000) * 1000) / 60000)
}

/** RFC 3339 date-time -> `YYYY-MM-DDTHH:mm` as displayed in the zone. */
export function isoToLocalInput(iso: string | null, timeZone?: string): string {
  if (!iso) return ''
  const p = partsInZone(new Date(iso).getTime(), timeZone)
  return `${pad(p.year, 4)}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour)}:${pad(p.minute)}`
}

/** `YYYY-MM-DDTHH:mm` read as wall-clock time in the zone -> RFC 3339 with offset. */
export function localInputToIso(local: string, timeZone?: string): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local)
  if (!m) return null
  const wall = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]))
  let offset = offsetMinutes(wall, timeZone)
  const corrected = offsetMinutes(wall - offset * 60000, timeZone)
  if (corrected !== offset) offset = corrected
  const sign = offset < 0 ? '-' : '+'
  const abs = Math.abs(offset)
  return `${local}:00${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
}

/** Start (`end` false) or end (`end` true) of a `YYYY-MM-DD` day in the zone, as RFC 3339. */
export function dayBoundIso(day: string, end: boolean, timeZone?: string): string | null {
  return localInputToIso(`${day}T${end ? '23:59' : '00:00'}`, timeZone)
}
