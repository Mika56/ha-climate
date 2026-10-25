import type { ScheduleInput, SlotStart } from '@/api/types'

export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const
export const DAY_MINUTES = 1440

/** A slot with a local identity so selection survives edits and re-sorting. */
export interface EditorSlot {
  uid: number
  weekday: number
  start: SlotStart
  preset: string
}

export interface DraftSchedule {
  name: string
  mode: 'heat' | 'cool'
  slots: EditorSlot[]
}

export interface Segment {
  slot: EditorSlot
  start: number
  end: number
}

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

let nextUid = 1
const uid = (): number => nextUid++

/** The minute a slot is drawn at (anchored slots use their fallback time). */
export function startMinute(start: SlotStart): number {
  return start.type === 'fixed' ? start.minuteOfDay : start.fallbackMinuteOfDay
}

export function toDraft(schedule: ScheduleInput): DraftSchedule {
  return {
    name: schedule.name,
    mode: schedule.mode,
    slots: schedule.slots.map((s) => ({
      uid: uid(),
      weekday: s.weekday,
      start: clone(s.start),
      preset: s.preset,
    })),
  }
}

/** Slots in the order they are sent to the API (by day, then start). */
export function sortedSlots(slots: EditorSlot[]): EditorSlot[] {
  return [...slots].sort(
    (a, b) => a.weekday - b.weekday || startMinute(a.start) - startMinute(b.start),
  )
}

/** Serialises the draft without local ids. */
export function toInput(draft: DraftSchedule): ScheduleInput {
  const slots = sortedSlots(draft.slots).map((s) => ({
    weekday: s.weekday,
    start: s.start,
    preset: s.preset,
  }))
  return { name: draft.name.trim(), mode: draft.mode, slots }
}

/** A new schedule: one all-day slot per weekday. */
export function newDraft(mode: 'heat' | 'cool', preset: string): DraftSchedule {
  return {
    name: '',
    mode,
    slots: WEEKDAYS.map((weekday) => ({
      uid: uid(),
      weekday,
      start: { type: 'fixed', minuteOfDay: 0 },
      preset,
    })),
  }
}

export function daySlots(slots: EditorSlot[], weekday: number): EditorSlot[] {
  return slots
    .filter((s) => s.weekday === weekday)
    .sort((a, b) => startMinute(a.start) - startMinute(b.start))
}

/** A slot ends where the next one of the same day starts (or at midnight). */
export function daySegments(slots: EditorSlot[], weekday: number): Segment[] {
  const day = daySlots(slots, weekday)
  return day.map((slot, i) => ({
    slot,
    start: startMinute(slot.start),
    end: i + 1 < day.length ? startMinute(day[i + 1].start) : DAY_MINUTES,
  }))
}

/** Splits a slot in two at the middle (rounded to 5 minutes); returns the new slot. */
export function splitSlot(slots: EditorSlot[], target: EditorSlot): EditorSlot | null {
  const segment = daySegments(slots, target.weekday).find((s) => s.slot.uid === target.uid)
  if (!segment) return null
  const middle = Math.round((segment.start + segment.end) / 2 / 5) * 5
  if (middle <= segment.start || middle >= segment.end) return null
  const created: EditorSlot = {
    uid: uid(),
    weekday: target.weekday,
    start: { type: 'fixed', minuteOfDay: middle },
    preset: target.preset,
  }
  slots.push(created)
  return created
}

/** Adds a slot to a day by splitting its longest segment (or at midnight on an empty day). */
export function addSlot(slots: EditorSlot[], weekday: number, preset: string): EditorSlot | null {
  const segments = daySegments(slots, weekday)
  if (segments.length === 0) {
    const created: EditorSlot = {
      uid: uid(),
      weekday,
      start: { type: 'fixed', minuteOfDay: 0 },
      preset,
    }
    slots.push(created)
    return created
  }
  const longest = segments.reduce((a, b) => (b.end - b.start > a.end - a.start ? b : a))
  return splitSlot(slots, longest.slot)
}

/** Replaces the slots of the target days with copies of the source day's slots. */
export function copyDay(slots: EditorSlot[], from: number, to: number[]): EditorSlot[] {
  const source = daySlots(slots, from)
  const kept = slots.filter((s) => !to.includes(s.weekday))
  const copies = to.flatMap((weekday) => source.map((s) => ({ ...clone(s), uid: uid(), weekday })))
  return [...kept, ...copies]
}

/** True when two slots of the same day start at the same minute. */
export function hasDuplicateStarts(slots: EditorSlot[]): boolean {
  const seen = new Set<string>()
  for (const s of slots) {
    const key = `${s.weekday}:${startMinute(s.start)}`
    if (seen.has(key)) return true
    seen.add(key)
  }
  return false
}
