<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '@/api/client'
import type { ActorType, AuditEntry, Room } from '@/api/types'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useFormatter } from '@/composables/useFormatter'
import { dayBoundIso } from '@/lib/datetime'
import { safeTimeZone } from '@/lib/format'
import { usePreferencesStore } from '@/stores/preferences'

type Origin = 'all' | ActorType

const PAGE_SIZE = 50
const ORIGINS: Origin[] = ['all', 'user', 'automation', 'device', 'system']
const TONES: Record<ActorType, string> = {
  user: 'tone-info',
  automation: 'tone-ok',
  device: 'tone-warn',
  system: 'tone-neutral',
}

const { t } = useI18n()
const fmt = useFormatter()
const prefs = usePreferencesStore()
const report = useErrorReporter()

const rooms = ref<Room[]>([])
const roomId = ref('')
const origin = ref<Origin>('all')
const from = ref('')
const to = ref('')
const items = ref<AuditEntry[]>([])
const total = ref(0)
const loading = ref(false)
let requestId = 0

const zone = computed(() => safeTimeZone(prefs.timeZone))

/** Filters as API query parameters (shared by the table and the CSV link). */
const filters = computed(() => {
  const query: Record<string, string> = {}
  if (roomId.value) query.roomId = roomId.value
  if (origin.value !== 'all') query.actorType = origin.value
  const start = from.value ? dayBoundIso(from.value, false, zone.value) : null
  const end = to.value ? dayBoundIso(to.value, true, zone.value) : null
  if (start) query.from = start
  if (end) query.to = end
  return query
})

const exportHref = computed(() => {
  const params = new URLSearchParams(filters.value).toString()
  return `./api/audit/export${params ? `?${params}` : ''}`
})

async function load(append: boolean): Promise<void> {
  const current = ++requestId
  loading.value = true
  try {
    const f = filters.value
    const page = await unwrap(
      api.GET('/api/audit', {
        params: {
          query: {
            roomId: f.roomId,
            actorType: f.actorType as ActorType | undefined,
            from: f.from,
            to: f.to,
            limit: PAGE_SIZE,
            offset: append ? items.value.length : 0,
          },
        },
      }),
    )
    if (current !== requestId) return
    items.value = append ? [...items.value, ...page.items] : page.items
    total.value = page.total
  } catch (error) {
    report(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}

onMounted(async () => {
  try {
    rooms.value = await unwrap(api.GET('/api/rooms'))
  } catch (error) {
    report(error)
  }
  await load(false)
})
watch(filters, () => load(false))
</script>

<template>
  <div class="history">
    <div class="card filters">
      <label class="muted inline">
        {{ t('history.room') }}
        <select v-model="roomId" class="input" data-test="filter-room">
          <option value="">{{ t('history.allRooms') }}</option>
          <option v-for="room in rooms" :key="room.id" :value="room.id">{{ room.name }}</option>
        </select>
      </label>
      <label class="muted inline">
        {{ t('history.from') }}
        <input v-model="from" class="input" type="date" data-test="filter-from" />
      </label>
      <label class="muted inline">
        {{ t('history.to') }}
        <input v-model="to" class="input" type="date" data-test="filter-to" />
      </label>
      <div class="pills" role="group" :aria-label="t('history.origin')">
        <button
          v-for="o in ORIGINS"
          :key="o"
          type="button"
          class="pill"
          :class="{ on: origin === o }"
          :aria-pressed="origin === o"
          :data-test="`origin-${o}`"
          @click="origin = o"
        >
          {{ t(`history.origins.${o}`) }}
        </button>
      </div>
      <div class="grow"></div>
      <a class="btn" :href="exportHref" download data-test="export-csv">{{
        t('history.export')
      }}</a>
    </div>

    <section class="card" style="overflow: hidden">
      <div class="scroll-x">
        <table class="tbl">
          <thead>
            <tr>
              <th scope="col">{{ t('history.time') }}</th>
              <th scope="col">{{ t('history.room') }}</th>
              <th scope="col">{{ t('history.origin') }}</th>
              <th scope="col">{{ t('history.event') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in items" :key="entry.id" data-test="entry">
              <td class="muted nowrap">{{ fmt.smart(entry.occurredAt) }}</td>
              <td class="nowrap strong">{{ entry.roomName ?? t('history.house') }}</td>
              <td>
                <span class="chip" :class="TONES[entry.actorType]">
                  {{ t(`history.origins.${entry.actorType}`) }}
                </span>
              </td>
              <td>
                {{ entry.message }}
                <div v-if="entry.actorName" class="muted detail">{{ entry.actorName }}</div>
              </td>
            </tr>
            <tr v-if="!loading && items.length === 0">
              <td colspan="4" class="muted">{{ t('history.empty') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="foot">
        <span class="muted" data-test="count">
          {{ t('history.count', { shown: items.length, total }) }}
        </span>
        <button
          v-if="items.length < total"
          type="button"
          class="btn"
          :disabled="loading"
          data-test="load-more"
          @click="load(true)"
        >
          {{ t('history.loadMore') }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.history {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.filters {
  padding: 12px 16px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.inline {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}
.inline .input {
  width: auto;
}
.pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.grow {
  flex: 1;
}
.nowrap {
  white-space: nowrap;
}
.strong {
  font-weight: 500;
}
.detail {
  font-size: 12px;
  margin-top: 2px;
}
.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 16px;
  flex-wrap: wrap;
}
.tbl :deep(td) {
  vertical-align: top;
}
</style>
