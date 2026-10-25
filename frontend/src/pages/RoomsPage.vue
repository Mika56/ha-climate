<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, unwrap } from '@/api/client'
import type { Room } from '@/api/types'
import HaIcon from '@/components/HaIcon.vue'
import RoomEditor from '@/components/RoomEditor.vue'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { mdiArrowDown, mdiArrowUp, mdiPlus } from '@/lib/icons'
import { useLiveStore } from '@/stores/live'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const live = useLiveStore()
const report = useErrorReporter()

const rooms = ref<Room[]>([])

/** `null` = nothing selected, `'new'` = creation form, otherwise a room id. */
const selected = computed(() => {
  const id = route.params.id
  return typeof id === 'string' && id !== '' ? id : null
})
const editorRoomId = computed(() => (selected.value === 'new' ? null : selected.value))

async function load(): Promise<void> {
  try {
    rooms.value = await unwrap(api.GET('/api/rooms'))
  } catch (error) {
    report(error)
  }
}
onMounted(load)

async function move(index: number, offset: -1 | 1): Promise<void> {
  const target = index + offset
  if (target < 0 || target >= rooms.value.length) return
  const previous = rooms.value
  const next = [...previous]
  next.splice(target, 0, ...next.splice(index, 1))
  rooms.value = next
  try {
    await unwrap(api.PUT('/api/rooms/order', { body: { roomIds: next.map((r) => r.id) } }))
  } catch (error) {
    rooms.value = previous
    report(error)
  }
}

async function onDeleted(): Promise<void> {
  await router.replace('/rooms')
  await load()
}
</script>

<template>
  <div class="rooms-layout">
    <aside class="card room-list" :aria-label="t('rooms.list')">
      <div class="list-head">
        <h2 class="h-section">{{ t('nav.rooms') }}</h2>
        <RouterLink
          class="icon-btn"
          to="/rooms/new"
          :aria-label="t('rooms.add')"
          data-test="add-room"
        >
          <HaIcon :path="mdiPlus" />
        </RouterLink>
      </div>
      <p v-if="rooms.length === 0" class="muted empty">{{ t('rooms.empty') }}</p>
      <div v-for="(room, index) in rooms" :key="room.id" class="room-row">
        <RouterLink
          class="room-item"
          :class="{ on: selected === room.id }"
          :to="`/rooms/${room.id}`"
          :aria-current="selected === room.id ? 'page' : undefined"
        >
          <span class="grow">{{ room.name }}</span>
          <span
            v-if="(live.byId[room.id]?.alerts.length ?? 0) > 0"
            class="chip tone-error alert-chip"
          >
            {{ t('rooms.alerts', live.byId[room.id]?.alerts.length ?? 0) }}
          </span>
        </RouterLink>
        <button
          type="button"
          class="icon-btn"
          :disabled="index === 0"
          :aria-label="t('rooms.moveUp', { name: room.name })"
          @click="move(index, -1)"
        >
          <HaIcon :path="mdiArrowUp" :size="18" />
        </button>
        <button
          type="button"
          class="icon-btn"
          :disabled="index === rooms.length - 1"
          :aria-label="t('rooms.moveDown', { name: room.name })"
          @click="move(index, 1)"
        >
          <HaIcon :path="mdiArrowDown" :size="18" />
        </button>
      </div>
    </aside>

    <div class="editor-col">
      <RoomEditor
        v-if="selected"
        :key="selected"
        :room-id="editorRoomId"
        @saved="load"
        @deleted="onDeleted"
      />
      <p v-else class="muted">{{ t('rooms.select') }}</p>
    </div>
  </div>
</template>

<style scoped>
.rooms-layout {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
  align-items: flex-start;
}
.room-list {
  flex: 1 1 260px;
  padding: 8px 0;
  overflow: hidden;
}
.list-head {
  display: flex;
  align-items: center;
  padding: 4px 8px 4px 16px;
}
.list-head h2 {
  flex: 1;
}
.room-row {
  display: flex;
  align-items: center;
}
.room-row .room-item {
  flex: 1;
  min-width: 0;
}
.grow {
  flex: 1;
}
.alert-chip {
  padding: 2px 8px;
  font-size: 12px;
}
.empty {
  padding: 0 16px;
}
.editor-col {
  flex: 999 1 560px;
  min-width: 0;
}
</style>
