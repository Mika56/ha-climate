<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { minutesToTime } from '@/lib/datetime'
import { mdiLink } from '@/lib/icons'
import { DAY_MINUTES, WEEKDAYS, daySegments, type EditorSlot } from '@/lib/schedule'
import { usePresetStore } from '@/stores/presets'
import HaIcon from './HaIcon.vue'

defineProps<{ slots: EditorSlot[]; selected: number | null }>()
defineEmits<{ select: [slot: EditorSlot] }>()

const { t } = useI18n()
const presets = usePresetStore()

const hours = [0, 3, 6, 9, 12, 15, 18, 21, 24]
const pct = (minutes: number): string => `${(minutes / DAY_MINUTES) * 100}%`

function label(day: number, start: number, end: number, slot: EditorSlot): string {
  const parts = [
    t(`days.long.${day}`),
    t('schedules.slotRange', { start: minutesToTime(start), end: minutesToTime(end) }),
    presets.label(slot.preset),
  ]
  if (slot.start.type === 'anchored') parts.push(t('schedules.anchoredSlot'))
  return parts.join(', ')
}
</script>

<template>
  <div class="scroll-x">
    <div class="timeline">
      <div class="line">
        <span></span>
        <div class="hours" aria-hidden="true">
          <span v-for="h in hours" :key="h" class="muted" :style="{ left: pct(h * 60) }">
            {{ minutesToTime(h * 60 === DAY_MINUTES ? 0 : h * 60) }}
          </span>
        </div>
      </div>
      <div v-for="day in WEEKDAYS" :key="day" class="line">
        <span class="day-label">{{ t(`days.short.${day}`) }}</span>
        <div class="track" role="group" :aria-label="t(`days.long.${day}`)">
          <button
            v-for="seg in daySegments(slots, day)"
            :key="seg.slot.uid"
            type="button"
            class="seg-slot"
            :class="{ sel: seg.slot.uid === selected }"
            :style="{
              left: pct(seg.start),
              width: pct(seg.end - seg.start),
              background: presets.color(seg.slot.preset),
            }"
            :aria-label="label(day, seg.start, seg.end, seg.slot)"
            :aria-pressed="seg.slot.uid === selected"
            data-test="slot"
            @click="$emit('select', seg.slot)"
          >
            <HaIcon v-if="seg.slot.start.type === 'anchored'" :path="mdiLink" :size="12" />
            <span v-if="seg.end - seg.start > 130">{{ presets.label(seg.slot.preset) }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.timeline {
  min-width: 720px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: grid;
  grid-template-columns: 56px minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}
.hours {
  position: relative;
  height: 16px;
}
.hours span {
  position: absolute;
  transform: translateX(-50%);
  font-size: 11px;
}
.day-label {
  font-size: 14px;
  font-weight: 500;
}
.track {
  position: relative;
  height: 44px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--neutral-tint);
}
</style>
