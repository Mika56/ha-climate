import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { allStatuses } from '@/mocks/db'
import { FakeEventSource } from '@/mocks/fakeEventSource'
import { BACKOFF_MAX_MS, BACKOFF_START_MS, useLiveStore } from './live'

const factory = (url: string) => new FakeEventSource(url)

describe('live store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('loads a snapshot in room order', async () => {
    const live = useLiveStore()
    await live.loadSnapshot()
    expect(live.loaded).toBe(true)
    expect(live.rooms.map((r) => r.name)).toEqual([
      'Salon',
      'Cuisine',
      'Chambre parentale',
      'Chambre enfant',
      'Bureau',
      'Salle de bain',
    ])
  })

  it('applies room events and appends unknown rooms', () => {
    const live = useLiveStore()
    live.start(factory)
    const source = FakeEventSource.last as FakeEventSource
    expect(source.url).toBe('./api/live')
    source.open()
    expect(live.connected).toBe(true)

    const [first, second] = allStatuses()
    source.emit('room', JSON.stringify(first))
    source.emit('room', JSON.stringify(second))
    source.emit('room', JSON.stringify({ ...first, currentTemperature: 23 }))
    expect(live.rooms).toHaveLength(2)
    expect(live.byId[first.roomId].currentTemperature).toBe(23)
  })

  it('ignores a malformed event', () => {
    const live = useLiveStore()
    live.start(factory)
    FakeEventSource.last?.emit('room', '{not json')
    expect(live.rooms).toHaveLength(0)
  })

  it('is idempotent to start and clears everything on stop', () => {
    const live = useLiveStore()
    live.start(factory)
    live.start(factory)
    expect(FakeEventSource.instances).toHaveLength(1)
    FakeEventSource.last?.open()
    live.stop()
    expect(live.started).toBe(false)
    expect(live.connected).toBe(false)
    expect(FakeEventSource.last?.readyState).toBe(2)
  })

  it('flags a lost connection while the browser retries by itself', () => {
    const live = useLiveStore()
    live.start(factory)
    const source = FakeEventSource.last as FakeEventSource
    source.open()
    source.fail(false)
    expect(live.lost).toBe(true)
    expect(live.connected).toBe(false)
    expect(FakeEventSource.instances).toHaveLength(1)
    source.open()
    expect(live.lost).toBe(false)
  })

  it('reconnects with exponential backoff when the browser gives up', () => {
    vi.useFakeTimers()
    const live = useLiveStore()
    live.start(factory)

    FakeEventSource.last?.fail(true)
    expect(live.lost).toBe(true)
    vi.advanceTimersByTime(BACKOFF_START_MS - 1)
    expect(FakeEventSource.instances).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(FakeEventSource.instances).toHaveLength(2)

    FakeEventSource.last?.fail(true)
    vi.advanceTimersByTime(BACKOFF_START_MS * 2)
    expect(FakeEventSource.instances).toHaveLength(3)

    // The delay caps, and resets after a successful connection.
    for (let i = 0; i < 8; i++) {
      FakeEventSource.last?.fail(true)
      vi.advanceTimersByTime(BACKOFF_MAX_MS)
    }
    const count = FakeEventSource.instances.length
    FakeEventSource.last?.open()
    FakeEventSource.last?.fail(true)
    vi.advanceTimersByTime(BACKOFF_START_MS)
    expect(FakeEventSource.instances).toHaveLength(count + 1)
    expect(live.lost).toBe(true)
  })

  it('does not reconnect after stop()', () => {
    vi.useFakeTimers()
    const live = useLiveStore()
    live.start(factory)
    FakeEventSource.last?.fail(true)
    live.stop()
    vi.advanceTimersByTime(BACKOFF_MAX_MS)
    expect(FakeEventSource.instances).toHaveLength(1)
  })

  it('uses the global EventSource by default', () => {
    const live = useLiveStore()
    live.start()
    expect(FakeEventSource.instances).toHaveLength(1)
  })
})
