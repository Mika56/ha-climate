<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  CATEGORY_ORDER,
  entityCategory,
  matchesQuery,
  type EntityCategory,
  type PickerEntity,
} from '@/lib/entities'
import { mdiCheck, mdiChevronDown, mdiClose, mdiMagnify, mdiPlus } from '@/lib/icons'
import EntityLabel from './EntityLabel.vue'
import HaIcon from './HaIcon.vue'

const props = withDefaults(
  defineProps<{
    entities: PickerEntity[]
    areas?: { id: string; name: string }[]
    /** Area of the room: its entities are listed first, the others behind "show all". */
    areaId?: string | null
    /** `field` shows the chosen entity; `add` is a button that only emits what was picked. */
    variant?: 'field' | 'add'
    modelValue?: string | null
    /** Title of the dialog, accessible name of the trigger, text of the "add" button. */
    label: string
    clearable?: boolean
    invalid?: boolean
    exclude?: string[]
  }>(),
  {
    areas: () => [],
    areaId: null,
    variant: 'field',
    modelValue: null,
    clearable: false,
    invalid: false,
    exclude: () => [],
  },
)
const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  select: [entity: PickerEntity]
}>()

const { t } = useI18n()
const uid = useId()
const titleId = `${uid}-title`
const listId = `${uid}-list`
const optionId = (entityId: string): string => `${uid}-opt-${entityId}`

const isOpen = ref(false)
const query = ref('')
const showAll = ref(false)
const active = ref(0)
const trigger = ref<HTMLElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
const search = ref<HTMLInputElement | null>(null)

const areaName = (id: string | null): string | null =>
  props.areas.find((a) => a.id === id)?.name ?? null

const selected = computed(() => props.entities.find((e) => e.entityId === props.modelValue))

const candidates = computed(() => props.entities.filter((e) => !props.exclude.includes(e.entityId)))
const scoped = computed(() =>
  props.areaId === null
    ? candidates.value
    : candidates.value.filter((e) => e.areaId === props.areaId),
)
/** There are entities outside the room's area that "show all" would reveal. */
const hasOthers = computed(
  () => props.areaId !== null && scoped.value.length < candidates.value.length,
)
const pool = computed(() => (showAll.value || !hasOthers.value ? candidates.value : scoped.value))

const results = computed(() =>
  pool.value
    .filter((e) => matchesQuery(`${e.name} ${e.entityId} ${areaName(e.areaId) ?? ''}`, query.value))
    .sort((a, b) => a.name.localeCompare(b.name)),
)
const groups = computed(() => {
  const byCategory = new Map<EntityCategory, PickerEntity[]>()
  for (const entity of results.value) {
    const category = entityCategory(entity)
    byCategory.set(category, [...(byCategory.get(category) ?? []), entity])
  }
  return CATEGORY_ORDER.filter((c) => byCategory.has(c)).map((c) => ({
    category: c,
    items: byCategory.get(c) ?? [],
  }))
})
const flat = computed(() => groups.value.flatMap((g) => g.items))
const otherCount = computed(() => candidates.value.length - scoped.value.length)

watch([query, showAll], () => {
  active.value = 0
})
watch(active, async () => {
  await nextTick()
  const id = flat.value[active.value]?.entityId
  if (id) document.getElementById(optionId(id))?.scrollIntoView?.({ block: 'nearest' })
})
watch(isOpen, (value) => {
  document.body.style.overflow = value ? 'hidden' : ''
})
onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

async function open(): Promise<void> {
  query.value = ''
  active.value = 0
  showAll.value = !hasOthers.value || scoped.value.length === 0
  isOpen.value = true
  await nextTick()
  search.value?.focus()
}

function close(): void {
  isOpen.value = false
  void nextTick(() => trigger.value?.focus())
}

function choose(entity: PickerEntity): void {
  emit('update:modelValue', entity.entityId)
  emit('select', entity)
  close()
}

function onSearchKeydown(event: KeyboardEvent): void {
  const last = flat.value.length - 1
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    active.value = Math.min(active.value + 1, last)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    active.value = Math.max(active.value - 1, 0)
  } else if (event.key === 'Enter') {
    // Never submit the surrounding form from the search field.
    event.preventDefault()
    const entity = flat.value[active.value]
    if (entity) choose(entity)
  }
}

/** Escape closes; Tab stays inside the dialog. */
function onDialogKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    close()
    return
  }
  if (event.key !== 'Tab' || !dialog.value) return
  const focusable = [
    ...dialog.value.querySelectorAll<HTMLElement>('button:not([disabled]), input, [tabindex="0"]'),
  ]
  const first = focusable[0]
  const lastEl = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    lastEl.focus()
  } else if (!event.shiftKey && document.activeElement === lastEl) {
    event.preventDefault()
    first.focus()
  }
}
</script>

<template>
  <div class="picker">
    <button
      v-if="variant === 'add'"
      ref="trigger"
      type="button"
      class="btn"
      aria-haspopup="dialog"
      :aria-expanded="isOpen"
      data-test="picker-trigger"
      @click="open"
    >
      <HaIcon :path="mdiPlus" :size="18" />{{ label }}
    </button>
    <div v-else class="field-row">
      <button
        ref="trigger"
        type="button"
        class="input trigger"
        :class="{ empty: !modelValue }"
        aria-haspopup="dialog"
        :aria-expanded="isOpen"
        :aria-label="label"
        :aria-invalid="invalid || undefined"
        data-test="picker-trigger"
        @click="open"
      >
        <EntityLabel
          v-if="selected"
          :entity-id="selected.entityId"
          :name="selected.name"
          :state="selected.state"
          :unit="selected.unit"
          :device-class="selected.deviceClass"
          :area-name="areaName(selected.areaId)"
          show-state
        />
        <EntityLabel
          v-else-if="modelValue"
          :entity-id="modelValue"
          :area-name="t('common.notFound')"
        />
        <span v-else class="placeholder">{{ t('common.choose') }}</span>
        <HaIcon :path="mdiChevronDown" :size="20" />
      </button>
      <button
        v-if="clearable && modelValue"
        type="button"
        class="icon-btn"
        :aria-label="t('picker.clear')"
        data-test="picker-clear"
        @click="emit('update:modelValue', null)"
      >
        <HaIcon :path="mdiClose" :size="18" />
      </button>
    </div>

    <div v-if="isOpen" class="picker-backdrop" data-test="picker-backdrop" @click.self="close">
      <div
        ref="dialog"
        class="sheet"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        data-test="picker-dialog"
        @keydown="onDialogKeydown"
      >
        <header class="sheet-head">
          <h2 :id="titleId">{{ label }}</h2>
          <button
            type="button"
            class="icon-btn"
            :aria-label="t('picker.close')"
            data-test="picker-close"
            @click="close"
          >
            <HaIcon :path="mdiClose" :size="22" />
          </button>
        </header>

        <div class="search">
          <HaIcon :path="mdiMagnify" :size="20" />
          <input
            ref="search"
            v-model="query"
            class="search-input"
            type="search"
            role="combobox"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            aria-autocomplete="list"
            aria-expanded="true"
            :aria-controls="listId"
            :aria-activedescendant="flat[active] ? optionId(flat[active].entityId) : undefined"
            :aria-label="t('picker.search')"
            :placeholder="t('picker.search')"
            data-test="picker-search"
            @keydown="onSearchKeydown"
          />
        </div>

        <label v-if="hasOthers" class="check scope">
          <input v-model="showAll" type="checkbox" data-test="picker-show-all" />{{
            t('picker.showAll', { count: otherCount })
          }}
        </label>

        <p class="count" role="status" aria-live="polite">
          {{ t('picker.results', results.length) }}
        </p>

        <ul :id="listId" class="list" role="listbox" :aria-label="label">
          <li v-for="group in groups" :key="group.category" role="presentation">
            <span
              v-if="groups.length > 1"
              :id="`${uid}-g-${group.category}`"
              class="group-title"
              data-test="picker-group"
            >
              {{ t(`picker.category.${group.category}`) }}
            </span>
            <ul
              role="group"
              :aria-labelledby="groups.length > 1 ? `${uid}-g-${group.category}` : undefined"
            >
              <li
                v-for="entity in group.items"
                :id="optionId(entity.entityId)"
                :key="entity.entityId"
                role="option"
                class="option"
                :class="{ active: flat[active]?.entityId === entity.entityId }"
                :aria-selected="entity.entityId === modelValue"
                data-test="picker-option"
                :data-entity-id="entity.entityId"
                @click="choose(entity)"
              >
                <EntityLabel
                  :entity-id="entity.entityId"
                  :name="entity.name"
                  :state="entity.state"
                  :unit="entity.unit"
                  :device-class="entity.deviceClass"
                  :area-name="areaName(entity.areaId)"
                  show-state
                />
                <HaIcon v-if="entity.entityId === modelValue" :path="mdiCheck" :size="20" />
              </li>
            </ul>
          </li>
        </ul>

        <div v-if="results.length === 0" class="empty" data-test="picker-empty">
          <p>{{ t('picker.empty') }}</p>
          <button
            v-if="hasOthers && !showAll"
            type="button"
            class="btn text"
            @click="showAll = true"
          >
            {{ t('picker.searchEverywhere', { count: otherCount }) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.picker {
  min-width: 0;
}
.field-row {
  display: flex;
  align-items: center;
  gap: 4px;
}
.trigger {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 56px;
  padding: 6px 12px;
  cursor: pointer;
  text-align: left;
}
.placeholder {
  flex: 1;
  color: var(--text2);
}
.picker-backdrop {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(0, 0, 0, 0.5);
}
.sheet {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 520px;
  max-height: min(680px, 85vh);
  overflow: hidden;
  border-radius: 12px;
  background: var(--card);
  color: var(--text);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}
.sheet-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 8px 0 20px;
}
.sheet-head h2 {
  flex: 1;
  margin: 0;
  font-size: 18px;
  font-weight: 500;
}
.search {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 16px;
  padding: 0 12px;
  min-height: 48px;
  border-radius: 24px;
  background: var(--input);
  color: var(--text2);
}
.search-input {
  flex: 1;
  min-width: 0;
  min-height: 44px;
  border: none;
  outline: none;
  background: transparent;
  color: var(--text);
  font:
    16px Roboto,
    system-ui,
    sans-serif; /* 16px: iOS does not zoom the page on focus */
}
.search:focus-within {
  outline: 2px solid var(--primary);
}
.scope {
  min-height: 44px;
  padding: 0 20px;
}
.count {
  margin: 0;
  padding: 0 20px 4px;
  font-size: 12px;
  color: var(--text2);
}
.list,
.list ul {
  margin: 0;
  padding: 0;
  list-style: none;
}
.list {
  flex: 1;
  overflow-y: auto;
  padding-bottom: 8px;
  overscroll-behavior: contain;
}
.group-title {
  display: block;
  padding: 12px 20px 4px;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text2);
}
.option {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 56px;
  padding: 6px 16px 6px 20px;
  cursor: pointer;
}
.option:hover,
.option.active {
  background: var(--neutral-tint);
}
.option.active {
  box-shadow: inset 3px 0 0 var(--primary);
}
.option[aria-selected='true'] {
  color: var(--primary);
}
.empty {
  padding: 8px 20px 20px;
  color: var(--text2);
}
.empty p {
  margin: 0 0 8px;
}

@media (max-width: 640px) {
  .picker-backdrop {
    align-items: flex-end;
    padding: 0;
  }
  .sheet {
    max-width: none;
    height: 90vh;
    height: 90dvh;
    max-height: none;
    border-radius: 16px 16px 0 0;
    padding-bottom: env(safe-area-inset-bottom);
  }
}
</style>
