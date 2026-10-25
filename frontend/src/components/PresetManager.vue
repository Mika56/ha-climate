<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import { fieldErrors } from '@/api/errors'
import type { Preset, Schedule } from '@/api/types'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { mdiPencil, mdiPlus } from '@/lib/icons'
import { useDialogStore } from '@/stores/dialogs'
import { usePresetStore } from '@/stores/presets'
import { useToastStore } from '@/stores/toasts'
import FormField from './FormField.vue'
import HaIcon from './HaIcon.vue'

const { t } = useI18n()
const presets = usePresetStore()
const dialogs = useDialogStore()
const toasts = useToastStore()
const report = useErrorReporter()

interface Editing extends Preset {
  isNew: boolean
}

const editing = ref<Editing | null>(null)
const errors = ref<Record<string, string>>({})
const schedules = ref<Schedule[]>([])

const usage = computed(() =>
  Object.fromEntries(
    presets.list.map((p) => [
      p.key,
      schedules.value.filter((s) => s.slots.some((slot) => slot.preset === p.key)).length,
    ]),
  ),
)

onMounted(async () => {
  try {
    schedules.value = await unwrap(api.GET('/api/schedules'))
  } catch (error) {
    report(error)
  }
})

function startNew(): void {
  errors.value = {}
  const position = presets.list.reduce((max, p) => Math.max(max, p.position + 1), 0)
  editing.value = { isNew: true, key: '', label: '', color: '#2196f3', position }
}

function startEdit(preset: Preset): void {
  errors.value = {}
  editing.value = { ...preset, isNew: false }
}

async function save(): Promise<void> {
  const current = editing.value
  if (!current) return
  const body: Preset = {
    key: current.key,
    label: current.label.trim(),
    color: current.color,
    position: current.position,
  }
  try {
    if (current.isNew) await unwrap(api.POST('/api/presets', { body }))
    else {
      await unwrap(
        api.PUT('/api/presets/{presetKey}', { params: { path: { presetKey: body.key } }, body }),
      )
    }
    await presets.load()
    editing.value = null
    toasts.success('settings.presetSaved')
  } catch (error) {
    errors.value = fieldErrors(error)
    report(error)
  }
}

async function remove(): Promise<void> {
  const current = editing.value
  if (!current || current.isNew) return
  const confirmed = await dialogs.confirm({
    title: t('settings.presetDeleteTitle', { name: current.label }),
    message: t('settings.presetDeleteMessage'),
    confirmLabel: t('common.delete'),
    cancelLabel: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await unwrap(
      api.DELETE('/api/presets/{presetKey}', { params: { path: { presetKey: current.key } } }),
    )
    await presets.load()
    editing.value = null
    toasts.success('settings.presetDeleted')
  } catch (error) {
    report(error)
  }
}
</script>

<template>
  <section class="card panel" aria-labelledby="presets-title">
    <div class="head">
      <h2 id="presets-title" class="grow">{{ t('settings.presets') }}</h2>
      <button type="button" class="btn text" data-test="add-preset" @click="startNew">
        <HaIcon :path="mdiPlus" :size="18" />{{ t('common.add') }}
      </button>
    </div>
    <div v-for="preset in presets.sorted" :key="preset.key" class="row" data-test="preset-row">
      <span class="swatch" :style="{ background: preset.color }" aria-hidden="true"></span>
      <span class="grow">{{ preset.label }}</span>
      <span class="muted small">{{ t('settings.presetUsage', usage[preset.key] ?? 0) }}</span>
      <button
        type="button"
        class="icon-btn"
        :aria-label="t('settings.presetEdit', { name: preset.label })"
        @click="startEdit(preset)"
      >
        <HaIcon :path="mdiPencil" :size="18" />
      </button>
    </div>

    <form v-if="editing" class="editor card" data-test="preset-form" @submit.prevent="save">
      <div class="grid-fit">
        <FormField :label="t('settings.presetKey')" :error="errors['key']">
          <input
            v-model="editing.key"
            class="input"
            type="text"
            pattern="[a-z0-9_]{1,32}"
            maxlength="32"
            :disabled="!editing.isNew"
            data-test="preset-key"
          />
        </FormField>
        <FormField :label="t('settings.presetLabel')" :error="errors['label']">
          <input
            v-model="editing.label"
            class="input"
            type="text"
            maxlength="32"
            data-test="preset-label"
          />
        </FormField>
        <FormField :label="t('settings.presetColor')" :error="errors['color']">
          <input
            v-model="editing.color"
            class="input color"
            type="color"
            data-test="preset-color"
          />
        </FormField>
      </div>
      <p class="hint">{{ t('settings.presetKeyHint') }}</p>
      <div class="actions">
        <button
          v-if="!editing.isNew"
          type="button"
          class="btn text danger"
          data-test="preset-delete"
          @click="remove"
        >
          {{ t('common.delete') }}
        </button>
        <div class="grow"></div>
        <button type="button" class="btn text" @click="editing = null">
          {{ t('common.cancel') }}
        </button>
        <button type="submit" class="btn filled" data-test="preset-save">
          {{ t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.grow {
  flex: 1;
}
.swatch {
  width: 14px;
  height: 14px;
  border-radius: 4px;
}
.small {
  font-size: 13px;
}
.editor {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--bg);
}
.color {
  padding: 4px;
  min-width: 64px;
}
</style>
