<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ToggleSwitch from './ToggleSwitch.vue'

defineProps<{ paused: boolean; busy?: boolean }>()
defineEmits<{ 'update:paused': [paused: boolean] }>()

const { t } = useI18n()
</script>

<template>
  <div class="pause-switch">
    <div class="pause-text">
      <span class="pause-label">{{ t('pause.switch') }}</span>
      <p class="hint" data-test="pause-state">
        {{ paused ? t('pause.statePaused') : t('pause.stateActive') }}
      </p>
    </div>
    <ToggleSwitch
      :model-value="!paused"
      :label="t('pause.switch')"
      :disabled="busy"
      data-test="pause-switch"
      @update:model-value="$emit('update:paused', !$event)"
    />
  </div>
</template>

<style scoped>
.pause-switch {
  display: flex;
  align-items: center;
  gap: 12px;
}
.pause-text {
  flex: 1;
  min-width: 0;
}
.pause-label {
  font-weight: 500;
}
.pause-text .hint {
  margin: 4px 0 0;
}
</style>
