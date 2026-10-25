<script setup lang="ts">
import { ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { mdiInformationOutline } from '@/lib/icons'
import HaIcon from './HaIcon.vue'

defineProps<{ text: string }>()

const { t } = useI18n()
const open = ref(false)
const id = useId()
</script>

<template>
  <span class="info-tip" @mouseenter="open = true" @mouseleave="open = false">
    <button
      type="button"
      class="icon-btn tip-btn"
      :aria-label="t('common.help')"
      :aria-expanded="open"
      :aria-describedby="open ? id : undefined"
      data-test="info-tip"
      @click="open = !open"
      @blur="open = false"
      @keydown.esc="open = false"
    >
      <HaIcon :path="mdiInformationOutline" :size="16" />
    </button>
    <span v-if="open" :id="id" role="tooltip" class="bubble" data-test="info-tip-text">{{
      text
    }}</span>
  </span>
</template>

<style scoped>
.info-tip {
  position: relative;
  display: inline-flex;
  vertical-align: middle;
}
.tip-btn {
  width: 28px;
  height: 28px;
  margin: -6px 0;
}
.bubble {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  z-index: 10;
  width: max-content;
  max-width: min(320px, 80vw);
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--card);
  border: 1px solid var(--divider);
  color: var(--text);
  box-shadow: 0 2px 8px rgb(0 0 0 / 30%);
  font-size: 13px;
  line-height: 1.4;
  white-space: normal;
}
</style>
