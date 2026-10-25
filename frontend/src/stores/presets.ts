import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, unwrap } from '@/api/client'
import type { Preset } from '@/api/types'

/** Colour used for a preset key that is not (or no longer) defined. */
export const UNKNOWN_PRESET_COLOR = '#6b6b6b'

export const usePresetStore = defineStore('presets', () => {
  const list = ref<Preset[]>([])
  const loaded = ref(false)

  const sorted = computed(() => [...list.value].sort((a, b) => a.position - b.position))

  async function load(): Promise<void> {
    list.value = await unwrap(api.GET('/api/presets'))
    loaded.value = true
  }

  function find(key: string): Preset | undefined {
    return list.value.find((p) => p.key === key)
  }

  const label = (key: string): string => find(key)?.label ?? key
  const color = (key: string): string => find(key)?.color ?? UNKNOWN_PRESET_COLOR

  return { list, loaded, sorted, load, find, label, color }
})
