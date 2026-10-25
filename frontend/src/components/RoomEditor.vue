<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, unwrap } from '@/api/client'
import type { DeviceRole, DiscoveredEntity, Discovery, Room, Schedule } from '@/api/types'
import { useDirtyGuard } from '@/composables/useDirtyGuard'
import { useEditableForm } from '@/composables/useEditableForm'
import { useErrorReporter } from '@/composables/useErrorReporter'
import { useFormatter } from '@/composables/useFormatter'
import { emptyDevice, emptyRoomForm, fromRoomForm, toRoomForm, type RoomForm } from '@/lib/rooms'
import { mdiClose, mdiDelete, mdiPlus } from '@/lib/icons'
import { useDialogStore } from '@/stores/dialogs'
import { usePresetStore } from '@/stores/presets'
import { useToastStore } from '@/stores/toasts'
import EntitySelect from './EntitySelect.vue'
import ErrorSummary from './ErrorSummary.vue'
import FormField from './FormField.vue'
import HaIcon from './HaIcon.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import UnitInput from './UnitInput.vue'

const props = defineProps<{ roomId: string | null }>()
const emit = defineEmits<{ saved: [room: Room]; deleted: [id: string] }>()

const { t } = useI18n()
const router = useRouter()
const fmt = useFormatter()
const presets = usePresetStore()
const dialogs = useDialogStore()
const toasts = useToastStore()
const report = useErrorReporter()

const { form, errors, saving, dirty, reset, save } = useEditableForm<RoomForm>()
useDirtyGuard(dirty)

const room = ref<Room | null>(null)
const discovery = ref<Discovery | null>(null)
const schedules = ref<Schedule[]>([])
const showAll = ref(false)
const addingOpening = ref(false)
const addingDevice = ref(false)
let initial: RoomForm | null = null

const isNew = computed(() => props.roomId === null)
const presetKeys = computed(() => presets.sorted.map((p) => p.key))

onMounted(async () => {
  try {
    const [rooms, schedulesList] = await Promise.all([
      props.roomId === null
        ? Promise.resolve(null)
        : unwrap(api.GET('/api/rooms/{roomId}', { params: { path: { roomId: props.roomId } } })),
      unwrap(api.GET('/api/schedules')),
    ])
    schedules.value = schedulesList
    room.value = rooms
    initial = rooms ? toRoomForm(rooms, presetKeys.value) : emptyRoomForm(presetKeys.value)
    reset(initial)
  } catch (error) {
    report(error)
  }
  try {
    discovery.value = await unwrap(api.GET('/api/discovery'))
  } catch {
    // Handled by the engine banner (503) or the network toast; the pickers stay empty.
  }
})

/** Entities of the room's area, unless "show all" is checked (or the entity has no area). */
function inScope(list: DiscoveredEntity[] | undefined): DiscoveredEntity[] {
  const area = form.value?.areaId
  if (!list) return []
  return showAll.value || !area ? list : list.filter((e) => e.areaId === area)
}

const temperatureSensors = computed(() => inScope(discovery.value?.temperatureSensors))
const presenceSensors = computed(() => inScope(discovery.value?.presenceSensors))
const openingSensors = computed(() =>
  inScope(discovery.value?.openingSensors).filter(
    (e) => !form.value?.openings.includes(e.entityId),
  ),
)
const freeClimates = computed(() =>
  inScope(discovery.value?.climates).filter(
    (e) => !form.value?.devices.some((d) => d.entityId === e.entityId),
  ),
)
const heatingSchedules = computed(() => schedules.value.filter((s) => s.mode === 'heat'))
const coolingSchedules = computed(() => schedules.value.filter((s) => s.mode === 'cool'))
const roles: DeviceRole[] = ['heating', 'cooling', 'reversible']

function entityName(entityId: string): string {
  const entity = discovery.value?.climates.find((c) => c.entityId === entityId)
  return entity ? `${entity.name}${entity.state ? ` · ${entity.state}` : ''}` : ''
}

function addOpening(entityId: string | null): void {
  if (entityId) form.value?.openings.push(entityId)
  addingOpening.value = false
}
function addDevice(entityId: string | null): void {
  if (entityId) form.value?.devices.push(emptyDevice(entityId))
  addingDevice.value = false
}

function rate(rate: { degreesPerHour: number; samples: number } | null | undefined): string {
  return rate
    ? t('rooms.rate', {
        rate: fmt.value.number(rate.degreesPerHour, 1),
        samples: rate.samples,
      })
    : t('rooms.rateNone')
}

async function submit(): Promise<void> {
  const ok = await save(async (value) => {
    const body = fromRoomForm(value)
    const saved = props.roomId
      ? await unwrap(
          api.PUT('/api/rooms/{roomId}', { params: { path: { roomId: props.roomId } }, body }),
        )
      : await unwrap(api.POST('/api/rooms', { body }))
    room.value = saved
    emit('saved', saved)
  })
  if (ok) {
    toasts.success('rooms.saved')
    if (room.value && isNew.value) await router.replace(`/rooms/${room.value.id}`)
  }
}

function cancel(): void {
  if (initial) reset(initial)
}

async function remove(): Promise<void> {
  const current = room.value
  if (!current) return
  const confirmed = await dialogs.confirm({
    title: t('rooms.deleteTitle', { name: current.name }),
    message: t('rooms.deleteMessage'),
    confirmLabel: t('common.delete'),
    cancelLabel: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await unwrap(api.DELETE('/api/rooms/{roomId}', { params: { path: { roomId: current.id } } }))
    if (initial) reset(initial)
    toasts.success('rooms.deleted')
    emit('deleted', current.id)
  } catch (error) {
    report(error)
  }
}
</script>

<template>
  <form v-if="form" class="editor" novalidate @submit.prevent="submit">
    <div class="title-row">
      <h2 data-test="room-title">{{ form.name || t('rooms.newRoom') }}</h2>
      <button
        v-if="!isNew"
        type="button"
        class="btn text danger"
        data-test="delete-room"
        @click="remove"
      >
        {{ t('rooms.delete') }}
      </button>
    </div>

    <ErrorSummary :errors="errors" />

    <section class="card panel">
      <h3>{{ t('rooms.general') }}</h3>
      <div class="grid-fit">
        <FormField :label="t('rooms.name')" :error="errors['name']">
          <input
            v-model="form.name"
            class="input"
            type="text"
            :aria-invalid="!!errors['name'] || undefined"
            data-test="room-name"
          />
        </FormField>
        <FormField :label="t('rooms.area')" :error="errors['areaId']">
          <select v-model="form.areaId" class="input" data-test="room-area">
            <option :value="null">{{ t('common.none') }}</option>
            <option v-for="area in discovery?.areas ?? []" :key="area.id" :value="area.id">
              {{ area.name }}
            </option>
          </select>
        </FormField>
      </div>
    </section>

    <section class="card panel">
      <div>
        <h3>{{ t('rooms.sensors') }}</h3>
        <p class="hint">{{ t('rooms.sensorsHint') }}</p>
      </div>
      <label class="check">
        <input v-model="showAll" type="checkbox" data-test="show-all" />{{ t('rooms.showAll') }}
      </label>
      <div class="grid-fit">
        <FormField :label="t('rooms.temperature')" :error="errors['temperatureSensor']">
          <EntitySelect
            v-model="form.temperatureSensor"
            :entities="temperatureSensors"
            :invalid="!!errors['temperatureSensor']"
          />
        </FormField>
        <FormField :label="t('rooms.presence')" :error="errors['presenceSensor']">
          <EntitySelect
            v-model="form.presenceSensor"
            :entities="presenceSensors"
            :empty-label="t('common.none')"
          />
        </FormField>
      </div>
      <div class="field">
        <span>{{ t('rooms.openings') }}</span>
        <div class="chips">
          <span
            v-for="(entityId, index) in form.openings"
            :key="entityId"
            class="chip tone-neutral opening-chip"
          >
            {{ entityId }}
            <button
              type="button"
              class="icon-btn small"
              :aria-label="t('rooms.removeEntity', { entity: entityId })"
              @click="form.openings.splice(index, 1)"
            >
              <HaIcon :path="mdiClose" :size="14" />
            </button>
          </span>
          <EntitySelect
            v-if="addingOpening"
            :model-value="null"
            :entities="openingSensors"
            :label="t('rooms.addOpening')"
            data-test="opening-select"
            @update:model-value="addOpening"
          />
          <button
            v-else
            type="button"
            class="btn text"
            data-test="add-opening"
            @click="addingOpening = true"
          >
            <HaIcon :path="mdiPlus" :size="18" />{{ t('rooms.addOpening') }}
          </button>
        </div>
      </div>
    </section>

    <section class="card panel">
      <div class="head-row">
        <div class="grow">
          <h3>{{ t('rooms.devices') }}</h3>
          <p class="hint">{{ t('rooms.devicesHint') }}</p>
        </div>
        <EntitySelect
          v-if="addingDevice"
          :model-value="null"
          :entities="freeClimates"
          :label="t('rooms.addDevice')"
          data-test="device-select"
          @update:model-value="addDevice"
        />
        <button
          v-else
          type="button"
          class="btn"
          data-test="add-device"
          @click="addingDevice = true"
        >
          <HaIcon :path="mdiPlus" :size="18" />{{ t('rooms.addDevice') }}
        </button>
      </div>
      <p v-if="errors['devices']" class="err" role="alert">{{ errors['devices'] }}</p>
      <div class="scroll-x">
        <table v-if="form.devices.length" class="tbl">
          <thead>
            <tr>
              <th scope="col">{{ t('rooms.deviceEntity') }}</th>
              <th scope="col">{{ t('rooms.role') }}</th>
              <th scope="col">{{ t('rooms.minOn') }}</th>
              <th scope="col">{{ t('rooms.minOff') }}</th>
              <th scope="col">{{ t('rooms.hysteresis') }}</th>
              <th scope="col">
                <span class="sr-only">{{ t('common.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(device, i) in form.devices" :key="device.entityId">
              <td>
                <div class="strong">{{ device.entityId }}</div>
                <div class="muted tiny">{{ entityName(device.entityId) }}</div>
              </td>
              <td>
                <select
                  v-model="device.role"
                  class="input"
                  :aria-label="t('rooms.roleOf', { entity: device.entityId })"
                >
                  <option v-for="role in roles" :key="role" :value="role">
                    {{ t(`role.${role}`) }}
                  </option>
                </select>
              </td>
              <td>
                <UnitInput
                  v-model="device.minOnMinutes"
                  :unit="t('units.min')"
                  :min="0"
                  :max="120"
                  :label="t('rooms.minOnOf', { entity: device.entityId })"
                  :invalid="!!errors[`devices[${i}].minOnSeconds`]"
                />
                <span v-if="errors[`devices[${i}].minOnSeconds`]" class="err">{{
                  errors[`devices[${i}].minOnSeconds`]
                }}</span>
              </td>
              <td>
                <UnitInput
                  v-model="device.minOffMinutes"
                  :unit="t('units.min')"
                  :min="0"
                  :max="120"
                  :label="t('rooms.minOffOf', { entity: device.entityId })"
                  :invalid="!!errors[`devices[${i}].minOffSeconds`]"
                />
                <span v-if="errors[`devices[${i}].minOffSeconds`]" class="err">{{
                  errors[`devices[${i}].minOffSeconds`]
                }}</span>
              </td>
              <td>
                <UnitInput
                  v-model="device.hysteresis"
                  unit="°C"
                  :step="0.1"
                  :min="0"
                  :max="2"
                  :label="t('rooms.hysteresisOf', { entity: device.entityId })"
                  :invalid="!!errors[`devices[${i}].hysteresis`]"
                />
                <span v-if="errors[`devices[${i}].hysteresis`]" class="err">{{
                  errors[`devices[${i}].hysteresis`]
                }}</span>
              </td>
              <td>
                <button
                  type="button"
                  class="icon-btn"
                  :aria-label="t('rooms.removeEntity', { entity: device.entityId })"
                  @click="form.devices.splice(i, 1)"
                >
                  <HaIcon :path="mdiDelete" :size="18" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">{{ t('rooms.noDevices') }}</p>
      </div>
    </section>

    <section class="card panel">
      <div>
        <h3>{{ t('rooms.presetTemps') }}</h3>
        <p class="hint">{{ t('rooms.presetTempsHint') }}</p>
      </div>
      <div class="scroll-x">
        <table class="tbl">
          <thead>
            <tr>
              <th scope="col">{{ t('rooms.preset') }}</th>
              <th scope="col">{{ t('rooms.heatingWinter') }}</th>
              <th scope="col">{{ t('rooms.coolingSummer') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in form.presetTemperatures" :key="row.preset">
              <td>
                <span class="preset-name">
                  <span
                    class="swatch"
                    :style="{ background: presets.color(row.preset) }"
                    aria-hidden="true"
                  ></span>
                  {{ presets.label(row.preset) }}
                </span>
              </td>
              <td>
                <UnitInput
                  v-model="row.heat"
                  unit="°C"
                  :step="0.5"
                  nullable
                  :label="t('rooms.presetHeat', { preset: presets.label(row.preset) })"
                  :invalid="!!errors[`presetTemperatures[${i}].heat`]"
                />
                <span v-if="errors[`presetTemperatures[${i}].heat`]" class="err">{{
                  errors[`presetTemperatures[${i}].heat`]
                }}</span>
              </td>
              <td>
                <UnitInput
                  v-model="row.cool"
                  unit="°C"
                  :step="0.5"
                  nullable
                  :label="t('rooms.presetCool', { preset: presets.label(row.preset) })"
                  :invalid="!!errors[`presetTemperatures[${i}].cool`]"
                />
                <span v-if="errors[`presetTemperatures[${i}].cool`]" class="err">{{
                  errors[`presetTemperatures[${i}].cool`]
                }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card panel">
      <h3>{{ t('rooms.schedules') }}</h3>
      <div class="grid-fit">
        <FormField :label="t('rooms.heatingSchedule')" :error="errors['heatingScheduleId']">
          <select v-model="form.heatingScheduleId" class="input" data-test="heating-schedule">
            <option :value="null">{{ t('common.none') }}</option>
            <option v-for="s in heatingSchedules" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </FormField>
        <FormField :label="t('rooms.coolingSchedule')" :error="errors['coolingScheduleId']">
          <select v-model="form.coolingScheduleId" class="input" data-test="cooling-schedule">
            <option :value="null">{{ t('common.none') }}</option>
            <option v-for="s in coolingSchedules" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </FormField>
      </div>
      <div>
        <RouterLink class="btn text" to="/schedules">{{ t('rooms.editSchedules') }}</RouterLink>
      </div>
    </section>

    <section class="card panel">
      <h3>{{ t('rooms.delays') }}</h3>
      <div class="grid-fit">
        <FormField :label="t('rooms.vacancyDelay')" :error="errors['vacancyDelaySeconds']">
          <UnitInput
            v-model="form.vacancyDelayMinutes"
            :unit="t('units.min')"
            :min="0"
            data-test="vacancy-delay"
            :invalid="!!errors['vacancyDelaySeconds']"
          />
        </FormField>
        <FormField :label="t('rooms.windowCutDelay')" :error="errors['windowCutDelaySeconds']">
          <UnitInput
            v-model="form.windowCutDelayMinutes"
            :unit="t('units.min')"
            :min="0"
            :invalid="!!errors['windowCutDelaySeconds']"
          />
        </FormField>
        <FormField
          :label="t('rooms.windowNotifyDelay')"
          :error="errors['windowNotifyDelaySeconds']"
        >
          <UnitInput
            v-model="form.windowNotifyDelayMinutes"
            :unit="t('units.min')"
            :min="0"
            :invalid="!!errors['windowNotifyDelaySeconds']"
          />
        </FormField>
      </div>
    </section>

    <section class="card panel">
      <div class="head-row">
        <div class="grow">
          <h3>{{ t('rooms.preheat') }}</h3>
          <p class="hint">{{ t('rooms.preheatHint') }}</p>
        </div>
        <ToggleSwitch v-model="form.preheatEnabled" :label="t('rooms.preheatToggle')" />
      </div>
      <div v-if="form.preheatEnabled" class="grid-fit">
        <FormField :label="t('rooms.preheatMax')" :error="errors['preheatMaxMinutes']">
          <UnitInput
            v-model="form.preheatMaxMinutes"
            :unit="t('units.min')"
            :min="0"
            :invalid="!!errors['preheatMaxMinutes']"
          />
        </FormField>
        <div class="field">
          <span>{{ t('rooms.rateHeat') }}</span>
          <p class="read" data-test="rate-heat">{{ rate(room?.learnedRates.heat) }}</p>
        </div>
        <div class="field">
          <span>{{ t('rooms.rateCool') }}</span>
          <p class="read" data-test="rate-cool">{{ rate(room?.learnedRates.cool) }}</p>
        </div>
      </div>
    </section>

    <div class="sticky-actions">
      <button type="button" class="btn text" :disabled="!dirty" @click="cancel">
        {{ t('common.cancel') }}
      </button>
      <button type="submit" class="btn filled" :disabled="saving || !dirty" data-test="save-room">
        {{ t('common.save') }}
      </button>
    </div>
  </form>
  <p v-else class="muted" role="status">{{ t('common.loading') }}</p>
</template>

<style scoped>
.editor {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.title-row,
.head-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.title-row h2 {
  margin: 0;
  font-size: 24px;
  font-weight: 400;
  flex: 1;
}
.grow {
  flex: 1;
  min-width: 220px;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.opening-chip {
  padding: 4px 4px 4px 12px;
}
.icon-btn.small {
  width: 32px;
  height: 32px;
}
.strong {
  font-weight: 500;
}
.tiny {
  font-size: 12px;
}
.preset-name {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.swatch {
  width: 12px;
  height: 12px;
  border-radius: 3px;
}
.read {
  margin: 0;
  min-height: 44px;
  display: flex;
  align-items: center;
}
.sticky-actions {
  position: sticky;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 12px 0;
  background: var(--bg);
}
</style>
