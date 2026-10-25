import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { UNKNOWN_PRESET_COLOR, usePresetStore } from './presets'

describe('preset store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('loads presets sorted by position and resolves labels and colours', async () => {
    const presets = usePresetStore()
    await presets.load()
    expect(presets.loaded).toBe(true)
    expect(presets.sorted.map((p) => p.key)).toEqual(['comfort', 'eco', 'night', 'away'])
    expect(presets.label('eco')).toBe('Éco')
    expect(presets.color('night')).toBe('#4a55a2')
  })

  it('falls back for an unknown preset key', async () => {
    const presets = usePresetStore()
    await presets.load()
    expect(presets.label('gone')).toBe('gone')
    expect(presets.color('gone')).toBe(UNKNOWN_PRESET_COLOR)
  })
})
