import type { Locale, Season, Settings } from '@/api/types'
import { isoToLocalInput, localInputToIso } from './datetime'

/** Global settings as edited in the UI (minutes, and local date-time inputs). */
export interface SettingsForm {
  season: Season
  trackedPersons: string[]
  houseEmptyDelayMinutes: number
  manualOverrideMinutes: number
  vacation: { enabled: boolean; startAt: string; returnAt: string; offDuringVacation: boolean }
  weatherEntity: string | null
  uiLocale: Locale | null
  notificationLocale: Locale | null
  auditRetentionDays: number
}

/** Settings without the pause (see {@link fromSettingsForm}). */
export function toSettingsForm(settings: Settings, timeZone?: string): SettingsForm {
  return {
    season: settings.season,
    trackedPersons: [...settings.trackedPersons],
    houseEmptyDelayMinutes: Math.round(settings.houseEmptyDelaySeconds / 60),
    manualOverrideMinutes: settings.manualOverrideMinutes,
    vacation: {
      enabled: settings.vacation.enabled,
      startAt: isoToLocalInput(settings.vacation.startAt, timeZone),
      returnAt: isoToLocalInput(settings.vacation.returnAt, timeZone),
      offDuringVacation: settings.vacation.offDuringVacation,
    },
    weatherEntity: settings.weatherEntity,
    uiLocale: settings.uiLocale,
    notificationLocale: settings.notificationLocale,
    auditRetentionDays: settings.auditRetentionDays,
  }
}

/** The settings to save. The pause is not edited here: it has its own switch, which applies at once. */
export function fromSettingsForm(form: SettingsForm, timeZone?: string): Omit<Settings, 'paused'> {
  return {
    season: form.season,
    trackedPersons: form.trackedPersons,
    houseEmptyDelaySeconds: Math.round(form.houseEmptyDelayMinutes * 60),
    manualOverrideMinutes: form.manualOverrideMinutes,
    vacation: {
      enabled: form.vacation.enabled,
      startAt: localInputToIso(form.vacation.startAt, timeZone),
      returnAt: localInputToIso(form.vacation.returnAt, timeZone),
      offDuringVacation: form.vacation.offDuringVacation,
    },
    weatherEntity: form.weatherEntity,
    uiLocale: form.uiLocale,
    notificationLocale: form.notificationLocale,
    auditRetentionDays: form.auditRetentionDays,
  }
}
