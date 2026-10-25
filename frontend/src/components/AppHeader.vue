<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { mdiAlert, mdiThermometer } from '@/lib/icons'
import { useLiveStore } from '@/stores/live'
import { usePreferencesStore } from '@/stores/preferences'
import HaIcon from './HaIcon.vue'

const { t } = useI18n()
const prefs = usePreferencesStore()
const live = useLiveStore()

const tabs = [
  { to: '/', key: 'nav.overview' },
  { to: '/rooms', key: 'nav.rooms' },
  { to: '/schedules', key: 'nav.schedules' },
  { to: '/settings', key: 'nav.settings' },
  { to: '/notifications', key: 'nav.notifications' },
  { to: '/history', key: 'nav.history' },
]

const selected = computed(() => prefs.explicitLocale ?? 'auto')

function onChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  void prefs.setLocale(value === 'auto' ? null : (value as 'en' | 'fr'))
}
</script>

<template>
  <header class="app-header">
    <div class="app-header-row">
      <span class="app-logo" aria-hidden="true"><HaIcon :path="mdiThermometer" /></span>
      <h1>{{ t('app.title') }}</h1>
      <span v-if="live.lost" class="chip tone-warn" role="status" data-test="live-lost">
        <HaIcon :path="mdiAlert" :size="16" />{{ t('app.liveLost') }}
      </span>
      <label class="muted lang">
        {{ t('app.language') }}
        <select class="select" :value="selected" data-test="language-select" @change="onChange">
          <option value="auto">
            {{ t('app.auto') }} · {{ t(`locale.${prefs.resolvedLocale.locale}`) }}
          </option>
          <option value="fr">{{ t('locale.fr') }}</option>
          <option value="en">{{ t('locale.en') }}</option>
        </select>
      </label>
    </div>
    <nav :aria-label="t('nav.label')" class="app-tabs">
      <RouterLink v-for="tab in tabs" :key="tab.to" :to="tab.to" class="tab">
        {{ t(tab.key) }}
      </RouterLink>
    </nav>
  </header>
</template>

<style scoped>
.app-header {
  background: var(--card);
  border-bottom: 1px solid var(--divider);
}
.app-header-row {
  max-width: 1240px;
  margin: 0 auto;
  padding: 12px 16px 4px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.app-header h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 500;
  flex: 1;
}
.app-logo {
  display: inline-flex;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  align-items: center;
  justify-content: center;
  background: var(--primary-tint);
  color: var(--primary);
}
.lang {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}
.lang .select {
  width: auto;
}
.app-tabs {
  max-width: 1240px;
  margin: 0 auto;
  padding: 0 8px;
  display: flex;
  overflow-x: auto;
}
</style>
