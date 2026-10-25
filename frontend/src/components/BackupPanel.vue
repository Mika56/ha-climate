<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import { ApiError } from '@/api/errors'
import type { Backup } from '@/api/types'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { mdiDownload, mdiUpload } from '@/lib/icons'
import { useDialogStore } from '@/stores/dialogs'
import { useToastStore } from '@/stores/toasts'
import HaIcon from './HaIcon.vue'

const emit = defineEmits<{ restored: [] }>()

const { t } = useI18n()
const dialogs = useDialogStore()
const toasts = useToastStore()
const report = useErrorReporter()
const fileInput = ref<HTMLInputElement | null>(null)

async function onFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  let backup: Backup
  try {
    backup = JSON.parse(await file.text()) as Backup
  } catch {
    toasts.error('settings.importInvalid')
    return
  }
  const confirmed = await dialogs.confirm({
    title: t('settings.importTitle'),
    message: t('settings.importWarning', { name: file.name }),
    confirmLabel: t('settings.importConfirm'),
    cancelLabel: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  try {
    const counts = await unwrap(api.POST('/api/backup/restore', { body: backup }))
    toasts.success('settings.imported', counts)
    emit('restored')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) toasts.error('settings.importRejected')
    else report(error)
  }
}
</script>

<template>
  <section class="card panel" aria-labelledby="backup-title">
    <h2 id="backup-title">{{ t('settings.backup') }}</h2>
    <p class="hint">{{ t('settings.backupHint') }}</p>
    <div class="actions start">
      <a class="btn" href="./api/backup" download="ha-climate-backup.json" data-test="export">
        <HaIcon :path="mdiDownload" :size="18" />{{ t('settings.export') }}
      </a>
      <button type="button" class="btn" data-test="import" @click="fileInput?.click()">
        <HaIcon :path="mdiUpload" :size="18" />{{ t('settings.import') }}
      </button>
      <input
        ref="fileInput"
        class="sr-only"
        type="file"
        accept="application/json,.json"
        tabindex="-1"
        :aria-label="t('settings.importFile')"
        data-test="import-file"
        @change="onFile"
      />
    </div>
    <p class="chip tone-warn warning">{{ t('settings.importReplaces') }}</p>
  </section>
</template>

<style scoped>
.start {
  justify-content: flex-start;
}
.warning {
  margin: 0;
  align-self: flex-start;
}
</style>
