import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TOAST_DURATION_MS, useToastStore } from './toasts'

describe('toast store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('queues toasts and dismisses them after a delay', () => {
    vi.useFakeTimers()
    const toasts = useToastStore()
    toasts.success('a.key', { n: 1 })
    toasts.error('b.key')
    toasts.errorText('server said no')
    expect(toasts.items.map((t) => t.kind)).toEqual(['success', 'error', 'error'])
    vi.advanceTimersByTime(TOAST_DURATION_MS)
    expect(toasts.items).toHaveLength(0)
  })

  it('dismisses a single toast', () => {
    const toasts = useToastStore()
    const id = toasts.push({ kind: 'info', text: 'hello' })
    toasts.push({ kind: 'info', text: 'other' })
    toasts.dismiss(id)
    expect(toasts.items.map((t) => t.text)).toEqual(['other'])
  })
})
