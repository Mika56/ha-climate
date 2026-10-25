<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/AppHeader.vue'
import ConfirmHost from '@/components/ConfirmHost.vue'
import ToastHost from '@/components/ToastHost.vue'
import { useAppStore } from '@/stores/app'
import { useLiveStore } from '@/stores/live'
import { usePreferencesStore } from '@/stores/preferences'

const { t, locale } = useI18n()
const app = useAppStore()
const prefs = usePreferencesStore()
const live = useLiveStore()

// Language and theme follow the user's choice, then Home Assistant, then the browser.
watchEffect(() => {
  locale.value = prefs.resolvedLocale.locale
  document.documentElement.lang = prefs.resolvedLocale.locale
  document.documentElement.style.colorScheme = prefs.resolvedTheme.dark ? 'dark' : 'light'
})

// The live stream is served by the engine: once it is connected, the engine is obviously up.
watch(
  () => live.connected,
  (connected) => {
    if (connected) app.engineUnavailable = false
  },
)

const rootClass = computed(() => ({
  hc: true,
  dark: prefs.resolvedTheme.dark,
  'ha-themed': Object.keys(prefs.appliedThemeVars).length > 0,
}))

let stopObserving = (): void => {}

async function start(): Promise<void> {
  await app.bootstrap()
  if (app.booted) live.start()
}

onMounted(() => {
  stopObserving = prefs.startObserving()
  void start()
})
onBeforeUnmount(() => {
  stopObserving()
  live.stop()
})
</script>

<template>
  <div :class="rootClass" :style="prefs.appliedThemeVars">
    <AppHeader />
    <div
      v-if="app.engineUnavailable"
      class="banner tone-warn"
      role="alert"
      data-test="engine-banner"
    >
      {{ t('app.engineUnavailable') }}
    </div>
    <main class="page">
      <template v-if="app.booted">
        <RouterView />
      </template>
      <div v-else-if="app.bootFailed" class="card panel" role="alert">
        <p>{{ t('app.bootFailed') }}</p>
        <div>
          <button type="button" class="btn" @click="start">{{ t('common.retry') }}</button>
        </div>
      </div>
      <p v-else class="muted" role="status">{{ t('common.loading') }}</p>
    </main>
    <ToastHost />
    <ConfirmHost />
  </div>
</template>
