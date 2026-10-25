<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import type { Discovery, Locale, Season, Settings, ThemeChoice } from '@/api/types'
import BackupPanel from '@/components/BackupPanel.vue'
import ErrorSummary from '@/components/ErrorSummary.vue'
import FormField from '@/components/FormField.vue'
import PresetManager from '@/components/PresetManager.vue'
import SegmentedControl, { type SegmentOption } from '@/components/SegmentedControl.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import UnitInput from '@/components/UnitInput.vue'
import { useDirtyGuard } from '@/composables/useDirtyGuard'
import { useEditableForm } from '@/composables/useEditableForm'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useFormatter } from '@/composables/useFormatter'
import { safeTimeZone } from '@/lib/format'
import { fromSettingsForm, toSettingsForm, type SettingsForm } from '@/lib/settings'
import { usePreferencesStore } from '@/stores/preferences'
import { usePresetStore } from '@/stores/presets'
import { useToastStore } from '@/stores/toasts'

const { t, te } = useI18n()
const prefs = usePreferencesStore()
const presets = usePresetStore()
const toasts = useToastStore()
const report = useErrorReporter()
const fmt = useFormatter()

const { form, errors, saving, dirty, reset, save } = useEditableForm<SettingsForm>()
useDirtyGuard(dirty)

const discovery = ref<Discovery | null>(null)
const entryCount = ref(0)
let initial: SettingsForm | null = null

const zone = computed(() => safeTimeZone(prefs.timeZone))

const seasonOptions = computed<SegmentOption<Season>[]>(() => [
  { value: 'winter', label: t('season.winter') },
  { value: 'summer', label: t('season.summer') },
  { value: 'off', label: t('season.off') },
])

async function load(): Promise<void> {
  try {
    const settings: Settings = await unwrap(api.GET('/api/settings'))
    entryCount.value = settings.auditEntryCount ?? 0
    initial = toSettingsForm(settings, zone.value)
    reset(initial)
  } catch (error) {
    report(error)
  }
}

onMounted(async () => {
  await load()
  try {
    discovery.value = await unwrap(api.GET('/api/discovery'))
  } catch {
    // Pickers stay empty; the engine banner / network toast already informs the user.
  }
})

function toggleTracked(entityId: string, checked: boolean): void {
  if (!form.value) return
  const list = form.value.trackedPersons.filter((p) => p !== entityId)
  form.value.trackedPersons = checked ? [...list, entityId] : list
}

function personState(state: string | null): string {
  const key = `overview.person.${state}`
  return state && te(key) ? t(key) : (state ?? '—')
}

const localeOptions = computed(() => [
  { value: null, label: t('settings.automatic') },
  { value: 'fr' as Locale, label: t('locale.fr') },
  { value: 'en' as Locale, label: t('locale.en') },
])
const themeLabel = computed(() =>
  t('settings.themeAuto', {
    mode: t((prefs.haDark ?? prefs.resolvedTheme.dark) ? 'theme.dark' : 'theme.light'),
  }),
)

function onTheme(event: Event): void {
  void prefs.setTheme((event.target as HTMLSelectElement).value as ThemeChoice)
}

async function submit(): Promise<void> {
  const ok = await save(async (value) => {
    await unwrap(api.PUT('/api/settings', { body: fromSettingsForm(value, zone.value) }))
  })
  if (ok) toasts.success('settings.saved')
}

function cancel(): void {
  if (initial) reset(initial)
}

async function onRestored(): Promise<void> {
  await Promise.all([load(), presets.load()])
}
</script>

<template>
  <form v-if="form" class="settings" novalidate @submit.prevent="submit">
    <ErrorSummary class="full" :errors="errors" />

    <section class="card panel">
      <h2>{{ t('settings.season') }}</h2>
      <SegmentedControl
        v-model="form.season"
        :options="seasonOptions"
        :label="t('settings.season')"
      />
      <p class="hint">{{ t('settings.seasonHint') }}</p>
    </section>

    <section class="card panel">
      <div>
        <h2>{{ t('settings.presence') }}</h2>
        <p class="hint">{{ t('settings.presenceHint') }}</p>
      </div>
      <div>
        <label v-for="person in discovery?.persons ?? []" :key="person.entityId" class="check row">
          <input
            type="checkbox"
            :checked="form.trackedPersons.includes(person.entityId)"
            :data-test="`person-${person.entityId}`"
            @change="toggleTracked(person.entityId, ($event.target as HTMLInputElement).checked)"
          />
          <span class="grow">{{ person.entityId }}</span>
          <span class="chip" :class="person.state === 'home' ? 'tone-ok' : 'tone-neutral'">
            {{
              form.trackedPersons.includes(person.entityId)
                ? personState(person.state)
                : t('settings.notTracked')
            }}
          </span>
        </label>
      </div>
      <FormField :label="t('settings.houseEmpty')" :error="errors['houseEmptyDelaySeconds']">
        <UnitInput
          v-model="form.houseEmptyDelayMinutes"
          :unit="t('units.min')"
          :min="0"
          data-test="house-empty"
          :invalid="!!errors['houseEmptyDelaySeconds']"
        />
      </FormField>
    </section>

    <section class="card panel">
      <div class="head-row">
        <div class="grow">
          <h2>{{ t('settings.vacation') }}</h2>
          <p class="hint">{{ t('settings.vacationHint') }}</p>
        </div>
        <ToggleSwitch v-model="form.vacation.enabled" :label="t('settings.vacationToggle')" />
      </div>
      <div v-if="form.vacation.enabled" class="grid-fit">
        <FormField :label="t('settings.vacationStart')" :error="errors['vacation.startAt']">
          <input
            v-model="form.vacation.startAt"
            class="input"
            type="datetime-local"
            data-test="vacation-start"
          />
        </FormField>
        <FormField :label="t('settings.vacationReturn')" :error="errors['vacation.returnAt']">
          <input
            v-model="form.vacation.returnAt"
            class="input"
            type="datetime-local"
            data-test="vacation-return"
            :aria-invalid="!!errors['vacation.returnAt'] || undefined"
          />
        </FormField>
        <FormField :label="t('settings.vacationDuring')">
          <select v-model="form.vacation.offDuringVacation" class="input">
            <option :value="false">{{ t('settings.vacationAway') }}</option>
            <option :value="true">{{ t('settings.vacationOff') }}</option>
          </select>
        </FormField>
      </div>
    </section>

    <section class="card panel">
      <h2>{{ t('settings.manual') }}</h2>
      <p class="hint">{{ t('settings.manualHint') }}</p>
      <FormField :label="t('settings.manualDuration')" :error="errors['manualOverrideMinutes']">
        <UnitInput
          v-model="form.manualOverrideMinutes"
          :unit="t('units.min')"
          :min="1"
          :max="1440"
          data-test="manual-minutes"
          :invalid="!!errors['manualOverrideMinutes']"
        />
      </FormField>
    </section>

    <PresetManager />

    <section class="card panel">
      <h2>{{ t('settings.display') }}</h2>
      <FormField :label="t('settings.weather')" :error="errors['weatherEntity']">
        <select v-model="form.weatherEntity" class="input" data-test="weather-entity">
          <option :value="null">{{ t('common.none') }}</option>
          <option v-for="w in discovery?.weather ?? []" :key="w.entityId" :value="w.entityId">
            {{ w.entityId }}
          </option>
          <option
            v-if="
              form.weatherEntity &&
              !discovery?.weather.some((w) => w.entityId === form?.weatherEntity)
            "
            :value="form.weatherEntity"
          >
            {{ form.weatherEntity }}
          </option>
        </select>
      </FormField>
      <FormField :label="t('settings.uiLocale')">
        <select v-model="form.uiLocale" class="input" data-test="ui-locale">
          <option v-for="o in localeOptions" :key="String(o.value)" :value="o.value">
            {{ o.label }}
          </option>
        </select>
      </FormField>
      <FormField :label="t('settings.notificationLocale')">
        <select v-model="form.notificationLocale" class="input" data-test="notification-locale">
          <option v-for="o in localeOptions" :key="String(o.value)" :value="o.value">
            {{ o.label }}
          </option>
        </select>
      </FormField>
      <FormField :label="t('settings.theme')">
        <select
          class="input"
          :value="prefs.explicitTheme"
          data-test="theme-select"
          @change="onTheme"
        >
          <option value="auto">{{ themeLabel }}</option>
          <option value="light">{{ t('theme.light') }}</option>
          <option value="dark">{{ t('theme.dark') }}</option>
        </select>
      </FormField>
      <p class="hint">{{ t('settings.displayHint') }}</p>
    </section>

    <section class="card panel">
      <h2>{{ t('settings.audit') }}</h2>
      <FormField :label="t('settings.retention')" :error="errors['auditRetentionDays']">
        <UnitInput
          v-model="form.auditRetentionDays"
          :unit="t('units.days')"
          :min="1"
          :max="3650"
          data-test="retention"
          :invalid="!!errors['auditRetentionDays']"
        />
      </FormField>
      <p class="hint">{{ t('settings.auditCount', { count: fmt.number(entryCount) }) }}</p>
    </section>

    <BackupPanel @restored="onRestored" />

    <div class="actions full">
      <button type="button" class="btn text" :disabled="!dirty" @click="cancel">
        {{ t('common.cancel') }}
      </button>
      <button
        type="submit"
        class="btn filled"
        :disabled="saving || !dirty"
        data-test="save-settings"
      >
        {{ t('common.save') }}
      </button>
    </div>
  </form>
  <p v-else class="muted" role="status">{{ t('common.loading') }}</p>
</template>

<style scoped>
.settings {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr));
  gap: 16px;
  align-items: start;
}
.full {
  grid-column: 1 / -1;
}
.head-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.grow {
  flex: 1;
}
</style>
