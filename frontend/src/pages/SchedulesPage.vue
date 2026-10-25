<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, unwrap } from '@/api/client'
import type { DiscoveredEntity, Schedule } from '@/api/types'
import ErrorSummary from '@/components/ErrorSummary.vue'
import FormField from '@/components/FormField.vue'
import HaIcon from '@/components/HaIcon.vue'
import ScheduleTimeline from '@/components/ScheduleTimeline.vue'
import SlotForm from '@/components/SlotForm.vue'
import { useDirtyGuard } from '@/composables/useDirtyGuard'
import { useEditableForm } from '@/composables/useEditableForm'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { minutesToTime } from '@/lib/datetime'
import { mdiLink, mdiPlus } from '@/lib/icons'
import {
  WEEKDAYS,
  addSlot,
  copyDay,
  daySegments,
  hasDuplicateStarts,
  newDraft,
  sortedSlots,
  splitSlot,
  toDraft,
  toInput,
  type DraftSchedule,
  type EditorSlot,
} from '@/lib/schedule'
import { useDialogStore } from '@/stores/dialogs'
import { usePresetStore } from '@/stores/presets'
import { useToastStore } from '@/stores/toasts'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const presets = usePresetStore()
const dialogs = useDialogStore()
const toasts = useToastStore()
const report = useErrorReporter()

const { form: draft, errors, saving, dirty, reset, save } = useEditableForm<DraftSchedule>()
useDirtyGuard(dirty)

const schedules = ref<Schedule[]>([])
const timeEntities = ref<DiscoveredEntity[]>([])
const selectedUid = ref<number | null>(null)
const selectedDay = ref(1)
const copying = ref(false)
const copyTargets = ref<number[]>([])
const duplicating = ref(false)
const duplicateName = ref('')

const routeId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' && id !== '' ? id : null
})
const isNew = computed(() => routeId.value === 'new')
const current = computed(() => schedules.value.find((s) => s.id === routeId.value) ?? null)
const selectedSlot = computed(
  () => draft.value?.slots.find((s) => s.uid === selectedUid.value) ?? null,
)
const duplicateStarts = computed(() =>
  draft.value ? hasDuplicateStarts(draft.value.slots) : false,
)
const entityError = computed(() => {
  const slot = selectedSlot.value
  if (!slot || !draft.value) return undefined
  const index = sortedSlots(draft.value.slots).findIndex((s) => s.uid === slot.uid)
  return errors.value[`slots[${index}].start.entityId`]
})

async function load(): Promise<void> {
  try {
    schedules.value = await unwrap(api.GET('/api/schedules'))
  } catch (error) {
    report(error)
  }
}

function openDraft(): void {
  copying.value = false
  duplicating.value = false
  if (isNew.value) {
    const first = presets.sorted[0]?.key ?? ''
    reset(newDraft('heat', first))
  } else if (current.value) {
    reset(toDraft(current.value))
  } else {
    draft.value = null
    return
  }
  const first = draft.value?.slots.find((s) => s.weekday === 1) ?? draft.value?.slots[0]
  selectedUid.value = first?.uid ?? null
  selectedDay.value = first?.weekday ?? 1
}

const loaded = ref(false)

onMounted(async () => {
  await load()
  loaded.value = true
  try {
    timeEntities.value = (await unwrap(api.GET('/api/discovery'))).timeEntities
  } catch {
    // The anchored-slot entity list stays empty (engine banner / network toast already shown).
  }
  if (routeId.value === null && schedules.value.length > 0) {
    await router.replace(`/schedules/${schedules.value[0].id}`)
  }
})
watch([routeId, loaded], openDraft, { immediate: true })

async function onSelectSchedule(event: Event): Promise<void> {
  const select = event.target as HTMLSelectElement
  await router.push(`/schedules/${select.value}`)
  select.value = routeId.value ?? ''
}

function select(slot: EditorSlot): void {
  selectedUid.value = slot.uid
  selectedDay.value = slot.weekday
}

function patchSlot(patch: Partial<Pick<EditorSlot, 'preset' | 'start'>>): void {
  if (selectedSlot.value) Object.assign(selectedSlot.value, patch)
}

function split(): void {
  if (!draft.value || !selectedSlot.value) return
  const created = splitSlot(draft.value.slots, selectedSlot.value)
  if (created) select(created)
}

function add(): void {
  if (!draft.value) return
  const created = addSlot(draft.value.slots, selectedDay.value, presets.sorted[0]?.key ?? '')
  if (created) select(created)
}

function removeSlot(): void {
  if (!draft.value || !selectedSlot.value) return
  const day = selectedSlot.value.weekday
  draft.value.slots = draft.value.slots.filter((s) => s.uid !== selectedSlot.value?.uid)
  const next = daySegments(draft.value.slots, day)[0]?.slot ?? draft.value.slots[0]
  selectedUid.value = next?.uid ?? null
}

function toggleTarget(day: number): void {
  copyTargets.value = copyTargets.value.includes(day)
    ? copyTargets.value.filter((d) => d !== day)
    : [...copyTargets.value, day]
}

function applyCopy(): void {
  if (!draft.value) return
  draft.value.slots = copyDay(draft.value.slots, selectedDay.value, copyTargets.value)
  copying.value = false
  copyTargets.value = []
}

async function submit(): Promise<void> {
  if (duplicateStarts.value) return
  let savedId = ''
  const ok = await save(async (value) => {
    const body = toInput(value)
    const saved = isNew.value
      ? await unwrap(api.POST('/api/schedules', { body }))
      : await unwrap(
          api.PUT('/api/schedules/{scheduleId}', {
            params: { path: { scheduleId: routeId.value ?? '' } },
            body,
          }),
        )
    savedId = saved.id
  })
  if (!ok) return
  toasts.success('schedules.saved')
  await load()
  if (isNew.value) await router.replace(`/schedules/${savedId}`)
}

async function remove(): Promise<void> {
  const schedule = current.value
  if (!schedule) return
  const rooms = schedule.usedBy.map((r) => r.roomName).join(', ')
  const confirmed = await dialogs.confirm({
    title: t('schedules.deleteTitle', { name: schedule.name }),
    message: rooms ? t('schedules.deleteUsed', { rooms }) : t('schedules.deleteUnused'),
    confirmLabel: t('common.delete'),
    cancelLabel: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await unwrap(
      api.DELETE('/api/schedules/{scheduleId}', { params: { path: { scheduleId: schedule.id } } }),
    )
    if (draft.value) reset(draft.value)
    toasts.success('schedules.deleted')
    schedules.value = schedules.value.filter((s) => s.id !== schedule.id)
    await router.replace(schedules.value[0] ? `/schedules/${schedules.value[0].id}` : '/schedules')
  } catch (error) {
    report(error)
  }
}

function startDuplicate(): void {
  duplicateName.value = t('schedules.copyName', { name: current.value?.name ?? '' })
  duplicating.value = true
}

async function confirmDuplicate(): Promise<void> {
  if (!current.value) return
  try {
    const copy = await unwrap(
      api.POST('/api/schedules/{scheduleId}/duplicate', {
        params: { path: { scheduleId: current.value.id } },
        body: { name: duplicateName.value },
      }),
    )
    duplicating.value = false
    await load()
    await router.push(`/schedules/${copy.id}`)
  } catch (error) {
    report(error)
  }
}

function slotTitle(slot: EditorSlot): string {
  const seg = daySegments(draft.value?.slots ?? [], slot.weekday).find(
    (s) => s.slot.uid === slot.uid,
  )
  return t('schedules.selected', {
    day: t(`days.long.${slot.weekday}`),
    start: minutesToTime(seg?.start ?? 0),
    end: minutesToTime(seg?.end ?? 1440),
  })
}
</script>

<template>
  <div class="schedules">
    <div class="top-row">
      <FormField :label="t('schedules.schedule')">
        <select
          class="input"
          :value="routeId ?? ''"
          data-test="schedule-select"
          @change="onSelectSchedule"
        >
          <option v-if="isNew" value="new">{{ t('schedules.newSchedule') }}</option>
          <option v-for="s in schedules" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </FormField>
      <span
        v-if="draft"
        class="chip mode"
        :class="draft.mode === 'heat' ? 'tone-heat' : 'tone-cool'"
      >
        {{ draft.mode === 'heat' ? t('schedules.heating') : t('schedules.cooling') }}
      </span>
      <div class="grow"></div>
      <button
        type="button"
        class="btn"
        :disabled="isNew || dirty || !current"
        data-test="duplicate"
        @click="startDuplicate"
      >
        {{ t('schedules.duplicate') }}
      </button>
      <RouterLink class="btn" to="/schedules/new" data-test="new-schedule">
        <HaIcon :path="mdiPlus" :size="18" />{{ t('schedules.new') }}
      </RouterLink>
    </div>

    <form
      v-if="duplicating"
      class="card panel dup"
      data-test="duplicate-form"
      @submit.prevent="confirmDuplicate"
    >
      <FormField :label="t('schedules.duplicateName')">
        <input v-model="duplicateName" class="input" type="text" data-test="duplicate-name" />
      </FormField>
      <div class="actions">
        <button type="button" class="btn text" @click="duplicating = false">
          {{ t('common.cancel') }}
        </button>
        <button type="submit" class="btn filled" data-test="duplicate-confirm">
          {{ t('schedules.duplicate') }}
        </button>
      </div>
    </form>

    <p v-if="current" class="muted used-by" data-test="used-by">
      <template v-if="current.usedBy.length">
        {{ t('schedules.usedBy') }}
        <template v-for="(room, i) in current.usedBy" :key="room.roomId">
          <RouterLink :to="`/rooms/${room.roomId}`">{{ room.roomName }}</RouterLink
          ><template v-if="i < current.usedBy.length - 1">, </template> </template
        >. {{ t('schedules.usedByHint') }}
      </template>
      <template v-else>{{ t('schedules.unused') }}</template>
    </p>

    <template v-if="draft">
      <ErrorSummary :errors="errors" />
      <p
        v-if="duplicateStarts"
        class="card banner tone-error"
        role="alert"
        data-test="duplicate-starts"
      >
        {{ t('schedules.duplicateStarts') }}
      </p>

      <section class="card panel">
        <div class="grid-fit meta">
          <FormField :label="t('schedules.name')" :error="errors['name']">
            <input
              v-model="draft.name"
              class="input"
              type="text"
              data-test="schedule-name"
              :aria-invalid="!!errors['name'] || undefined"
            />
          </FormField>
          <FormField :label="t('schedules.mode')">
            <select
              v-model="draft.mode"
              class="input"
              :disabled="!isNew && (current?.usedBy.length ?? 0) > 0"
            >
              <option value="heat">{{ t('schedules.heating') }}</option>
              <option value="cool">{{ t('schedules.cooling') }}</option>
            </select>
          </FormField>
        </div>

        <div class="legend-row">
          <h2 class="h-section grow">{{ t('schedules.week') }}</h2>
          <ul class="legend">
            <li v-for="p in presets.sorted" :key="p.key">
              <span class="swatch" :style="{ background: p.color }" aria-hidden="true"></span
              >{{ p.label }}
            </li>
            <li class="muted">
              <HaIcon :path="mdiLink" :size="14" />{{ t('schedules.legendAnchored') }}
            </li>
          </ul>
        </div>

        <ScheduleTimeline :slots="draft.slots" :selected="selectedUid" @select="select" />

        <div class="row-actions">
          <button type="button" class="btn text" data-test="copy-day" @click="copying = !copying">
            {{ t('schedules.copyDay') }}
          </button>
          <button type="button" class="btn text" data-test="add-slot" @click="add">
            {{ t('schedules.addSlot') }}
          </button>
        </div>

        <fieldset v-if="copying" class="copy" data-test="copy-panel">
          <legend>{{ t('schedules.copyTo', { day: t(`days.long.${selectedDay}`) }) }}</legend>
          <label v-for="day in WEEKDAYS.filter((d) => d !== selectedDay)" :key="day" class="check">
            <input
              type="checkbox"
              :checked="copyTargets.includes(day)"
              :data-test="`copy-to-${day}`"
              @change="toggleTarget(day)"
            />
            {{ t(`days.long.${day}`) }}
          </label>
          <div class="actions">
            <button type="button" class="btn text" @click="copying = false">
              {{ t('common.cancel') }}
            </button>
            <button
              type="button"
              class="btn filled"
              :disabled="copyTargets.length === 0"
              data-test="copy-apply"
              @click="applyCopy"
            >
              {{ t('schedules.copy') }}
            </button>
          </div>
        </fieldset>
      </section>

      <section v-if="selectedSlot" class="card panel" aria-labelledby="slot-title">
        <h2 id="slot-title" class="h-section" data-test="slot-title">
          {{ slotTitle(selectedSlot) }}
        </h2>
        <SlotForm
          :item="selectedSlot"
          :time-entities="timeEntities"
          :can-delete="draft.slots.length > 1"
          :entity-error="entityError"
          @change="patchSlot"
          @split="split"
          @delete="removeSlot"
        />
        <p class="hint">{{ t('schedules.slotHint') }}</p>
      </section>

      <div class="actions">
        <button
          v-if="current"
          type="button"
          class="btn text danger"
          data-test="delete-schedule"
          @click="remove"
        >
          {{ t('schedules.delete') }}
        </button>
        <div class="grow"></div>
        <button type="button" class="btn text" :disabled="!dirty" @click="openDraft">
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="btn filled"
          :disabled="saving || !dirty || duplicateStarts"
          data-test="save-schedule"
          @click="submit"
        >
          {{ t('common.save') }}
        </button>
      </div>
    </template>
    <p v-else-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
    <section v-else class="card panel empty" data-test="schedules-empty">
      <p class="muted">
        {{ schedules.length === 0 ? t('schedules.empty') : t('schedules.notFound') }}
      </p>
      <RouterLink class="btn filled" to="/schedules/new" data-test="create-first-schedule">
        <HaIcon :path="mdiPlus" :size="18" />{{ t('schedules.new') }}
      </RouterLink>
    </section>
  </div>
</template>

<style scoped>
.schedules {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.top-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 16px;
}
.top-row .field {
  flex: 1 1 280px;
  max-width: 360px;
}
.mode {
  margin-bottom: 10px;
}
.grow {
  flex: 1;
}
.used-by {
  margin: 0;
  font-size: 14px;
}
.meta {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 360px));
}
.legend-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.legend {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  font-size: 13px;
}
.legend li {
  display: flex;
  align-items: center;
  gap: 6px;
}
.swatch {
  width: 12px;
  height: 12px;
  border-radius: 3px;
}
.row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.copy {
  border: 1px solid var(--divider);
  border-radius: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px 20px;
}
.copy .actions {
  flex-basis: 100%;
}
.dup {
  max-width: 480px;
}
</style>
