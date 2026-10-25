<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import type { NotificationSettings } from '@/api/types'
import ErrorSummary from '@/components/ErrorSummary.vue'
import HaIcon from '@/components/HaIcon.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import UnitInput from '@/components/UnitInput.vue'
import { useDirtyGuard } from '@/composables/useDirtyGuard'
import { useEditableForm } from '@/composables/useEditableForm'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { mdiBell } from '@/lib/icons'
import { useToastStore } from '@/stores/toasts'

interface TargetForm {
  service: string
  enabled: boolean
  name: string | null
  available: boolean
}
interface TypeForm {
  type: NotificationSettings['types'][number]['type']
  enabled: boolean
  delayMinutes: number | null
  persistentNotification: boolean
}
interface NotificationsForm {
  targets: TargetForm[]
  types: TypeForm[]
}

const TONES: Record<TypeForm['type'], string> = {
  window_open: 'tone-warn',
  setpoint_not_applicable: 'tone-warn',
  device_unresponsive: 'tone-error',
  sensor_unavailable: 'tone-error',
}

const { t } = useI18n()
const toasts = useToastStore()
const report = useErrorReporter()
const { form, errors, saving, dirty, reset, save } = useEditableForm<NotificationsForm>()
useDirtyGuard(dirty)
let initial: NotificationsForm | null = null

function toForm(settings: NotificationSettings): NotificationsForm {
  return {
    targets: settings.targets.map((target) => ({
      service: target.service,
      enabled: target.enabled,
      name: target.name ?? null,
      available: target.available ?? true,
    })),
    types: settings.types.map((type) => ({
      type: type.type,
      enabled: type.enabled,
      delayMinutes: type.delaySeconds === null ? null : Math.round(type.delaySeconds / 60),
      persistentNotification: type.persistentNotification,
    })),
  }
}

function toSettings(value: NotificationsForm): NotificationSettings {
  return {
    targets: value.targets.map(({ service, enabled }) => ({ service, enabled })),
    types: value.types.map((type) => ({
      type: type.type,
      enabled: type.enabled,
      delaySeconds: type.delayMinutes === null ? null : Math.round(type.delayMinutes * 60),
      persistentNotification: type.persistentNotification,
    })),
  }
}

onMounted(async () => {
  try {
    initial = toForm(await unwrap(api.GET('/api/notifications')))
    reset(initial)
  } catch (error) {
    report(error)
  }
})

async function submit(): Promise<void> {
  const ok = await save(async (value) => {
    await unwrap(api.PUT('/api/notifications', { body: toSettings(value) }))
  })
  if (ok) toasts.success('notifications.saved')
}

function cancel(): void {
  if (initial) reset(initial)
}

async function sendTest(): Promise<void> {
  try {
    await unwrap(api.POST('/api/notifications/test'))
    toasts.success('notifications.testSent')
  } catch (error) {
    report(error)
  }
}
</script>

<template>
  <form v-if="form" class="notifications" novalidate @submit.prevent="submit">
    <ErrorSummary class="full" :errors="errors" />

    <section class="card targets">
      <h2 class="h-section">{{ t('notifications.targets') }}</h2>
      <p class="hint">{{ t('notifications.targetsHint') }}</p>
      <label
        v-for="target in form.targets"
        :key="target.service"
        class="check row"
        data-test="target"
      >
        <input v-model="target.enabled" type="checkbox" :data-test="`target-${target.service}`" />
        <span class="target-text">
          <span class="service">{{ target.service }}</span>
          <span v-if="target.available && target.name" class="muted small">{{ target.name }}</span>
        </span>
        <span v-if="!target.available" class="chip tone-warn">{{
          t('notifications.unavailable')
        }}</span>
      </label>
      <p v-if="form.targets.length === 0" class="muted">{{ t('notifications.noTargets') }}</p>
      <div class="test">
        <button type="button" class="btn" :disabled="dirty" data-test="send-test" @click="sendTest">
          {{ t('notifications.test') }}
        </button>
        <p v-if="dirty" class="hint">{{ t('notifications.testSaveFirst') }}</p>
      </div>
    </section>

    <section class="card types">
      <h2 class="h-section">{{ t('notifications.types') }}</h2>
      <p class="hint">{{ t('notifications.typesHint') }}</p>
      <div v-for="(type, i) in form.types" :key="type.type" class="type-row" data-test="type">
        <span class="chip round" :class="TONES[type.type]" aria-hidden="true">
          <HaIcon :path="mdiBell" :size="18" />
        </span>
        <div class="type-body">
          <span class="type-title">{{ t(`notifications.type.${type.type}.title`) }}</span>
          <span class="muted small">{{ t(`notifications.type.${type.type}.desc`) }}</span>
          <p class="example">« {{ t(`notifications.type.${type.type}.example`) }} »</p>
          <label v-if="type.type === 'device_unresponsive'" class="inline muted small">
            {{ t('notifications.delay') }}
            <UnitInput
              v-model="type.delayMinutes"
              :unit="t('units.min')"
              :min="0"
              data-test="delay"
              :invalid="!!errors[`types[${i}].delaySeconds`]"
            />
          </label>
          <span v-if="errors[`types[${i}].delaySeconds`]" class="err">{{
            errors[`types[${i}].delaySeconds`]
          }}</span>
          <label v-if="type.type === 'sensor_unavailable'" class="check persistent">
            <input v-model="type.persistentNotification" type="checkbox" data-test="persistent" />
            {{ t('notifications.persistent') }}
          </label>
        </div>
        <ToggleSwitch
          v-model="type.enabled"
          :label="t('notifications.enable', { name: t(`notifications.type.${type.type}.title`) })"
        />
      </div>
    </section>

    <div class="actions full">
      <button type="button" class="btn text" :disabled="!dirty" @click="cancel">
        {{ t('common.cancel') }}
      </button>
      <button
        type="submit"
        class="btn filled"
        :disabled="saving || !dirty"
        data-test="save-notifications"
      >
        {{ t('common.save') }}
      </button>
    </div>
  </form>
  <p v-else class="muted" role="status">{{ t('common.loading') }}</p>
</template>

<style scoped>
.notifications {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: flex-start;
}
.full {
  flex: 1 1 100%;
}
.targets {
  flex: 1 1 320px;
  padding: 16px 20px 12px;
}
.types {
  flex: 999 1 560px;
  min-width: 0;
  padding: 16px 20px 8px;
}
.row {
  border-top: 1px solid var(--divider);
  min-height: 48px;
  padding: 6px 0;
}
.target-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
/* Service ids are long and have no spaces: break them only where needed, never letter by letter. */
.service {
  overflow-wrap: break-word;
  word-break: break-word;
}
.small {
  font-size: 13px;
}
.test {
  padding-top: 12px;
  border-top: 1px solid var(--divider);
}
.type-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid var(--divider);
}
.type-row .chip.round {
  width: 36px;
  height: 36px;
  justify-content: center;
}
.type-body {
  flex: 1 1 260px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.type-title {
  font-weight: 500;
}
.example {
  margin: 6px 0 0;
  font-size: 13px;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--neutral-tint);
}
.inline {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
}
.persistent {
  font-size: 14px;
}
</style>
