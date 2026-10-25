<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Alert } from '@/api/types'
import { useFormatter } from '@/composables/useFormatter'
import { mdiAlert, mdiWindowOpenVariant } from '@/lib/icons'
import HaIcon from './HaIcon.vue'

defineProps<{ alerts: Alert[]; now: Date }>()
const { t } = useI18n()
const fmt = useFormatter()
</script>

<template>
  <section class="card" aria-labelledby="alerts-title" style="padding: 8px 0">
    <div class="alerts-head">
      <h2 id="alerts-title" class="h-section">{{ t('overview.alerts') }}</h2>
      <span class="chip" :class="alerts.length ? 'tone-error' : 'tone-ok'" data-test="alert-count">
        {{ alerts.length ? t('overview.alertsActive', alerts.length) : t('overview.alertsNone') }}
      </span>
    </div>
    <div v-for="alert in alerts" :key="alert.key" class="alert-row" data-test="alert">
      <span
        class="chip round"
        :class="alert.type === 'opening_open' ? 'tone-warn' : 'tone-error'"
        aria-hidden="true"
      >
        <HaIcon
          :path="alert.type === 'opening_open' ? mdiWindowOpenVariant : mdiAlert"
          :size="18"
        />
      </span>
      <div class="alert-text">
        <p class="alert-title">
          {{ t(`alert.${alert.type}.title`, { room: alert.roomName ?? '—' }) }}
        </p>
        <p class="muted alert-body">
          {{
            t(`alert.${alert.type}.body`, {
              entity: alert.entityId ?? '—',
              since: fmt.smart(alert.openedAt, now),
              duration: fmt.since(alert.openedAt, now),
            })
          }}
        </p>
      </div>
      <RouterLink v-if="alert.roomId" class="btn text" :to="`/rooms/${alert.roomId}`">
        {{ t('overview.viewRoom') }}
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.alerts-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
}
.alerts-head h2 {
  flex: 1;
}
.alert-row {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 12px 16px;
  border-top: 1px solid var(--divider);
}
.alert-text {
  flex: 1;
  min-width: 0;
}
.alert-title {
  margin: 0;
  font-weight: 500;
}
.alert-body {
  margin: 2px 0 0;
  font-size: 14px;
}
</style>
