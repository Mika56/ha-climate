<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useDialogStore } from '@/stores/dialogs'

const dialogs = useDialogStore()
const confirmButton = ref<HTMLButtonElement | null>(null)

watch(
  () => dialogs.current,
  async (current) => {
    if (current) {
      await nextTick()
      confirmButton.value?.focus()
    }
  },
)
</script>

<template>
  <div
    v-if="dialogs.current"
    class="backdrop"
    @click.self="dialogs.answer(false)"
    @keydown.esc="dialogs.answer(false)"
  >
    <div
      class="card dialog"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="confirm-title"
      aria-describedby="confirm-message"
    >
      <h2 id="confirm-title">{{ dialogs.current.title }}</h2>
      <p id="confirm-message" class="muted">{{ dialogs.current.message }}</p>
      <div class="actions">
        <button type="button" class="btn text" @click="dialogs.answer(false)">
          {{ dialogs.current.cancelLabel }}
        </button>
        <button
          ref="confirmButton"
          type="button"
          class="btn filled"
          :class="{ danger: dialogs.current.danger }"
          data-test="confirm-accept"
          @click="dialogs.answer(true)"
        >
          {{ dialogs.current.confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>
