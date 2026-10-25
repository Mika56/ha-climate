import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, unwrap } from '@/api/client'
import type { RoomStatus } from '@/api/types'

/** The subset of EventSource we rely on (so tests and the mock mode can inject a fake). */
export interface EventSourceLike {
  readyState: number
  onopen: ((ev: Event) => void) | null
  onerror: ((ev: Event) => void) | null
  addEventListener: (type: string, listener: (ev: MessageEvent<string>) => void) => void
  close: () => void
}

export type EventSourceFactory = (url: string) => EventSourceLike

const CLOSED = 2
export const BACKOFF_START_MS = 1000
export const BACKOFF_MAX_MS = 30000

const defaultFactory: EventSourceFactory = (url) =>
  new EventSource(url) as unknown as EventSourceLike

/** Live room statuses: one snapshot over HTTP, then `room` events over SSE (`./api/live`). */
export const useLiveStore = defineStore('live', () => {
  const byId = ref<Record<string, RoomStatus>>({})
  const order = ref<string[]>([])
  const loaded = ref(false)
  const started = ref(false)
  const connected = ref(false)
  /** True once a connection error happened and until the stream is back (drives the header indicator). */
  const lost = ref(false)

  const rooms = computed(() =>
    order.value.map((id) => byId.value[id]).filter((s): s is RoomStatus => s !== undefined),
  )

  function upsert(status: RoomStatus): void {
    if (!(status.roomId in byId.value)) order.value.push(status.roomId)
    byId.value[status.roomId] = status
  }

  async function loadSnapshot(): Promise<void> {
    const list = await unwrap(api.GET('/api/status'))
    byId.value = Object.fromEntries(list.map((s) => [s.roomId, s]))
    order.value = list.map((s) => s.roomId)
    loaded.value = true
  }

  let source: EventSourceLike | null = null
  let timer: ReturnType<typeof setTimeout> | null = null
  let delay = BACKOFF_START_MS
  let factory: EventSourceFactory = defaultFactory

  function open(): void {
    source = factory('./api/live')
    source.onopen = () => {
      connected.value = true
      lost.value = false
      delay = BACKOFF_START_MS
    }
    source.addEventListener('room', (ev) => {
      try {
        upsert(JSON.parse(ev.data) as RoomStatus)
      } catch {
        // Ignore a malformed event; the next snapshot will correct the state.
      }
    })
    source.onerror = () => {
      connected.value = false
      lost.value = true
      // EventSource retries by itself unless the browser gave up (CLOSED): then we reconnect with backoff.
      if (source?.readyState === CLOSED) {
        source.close()
        source = null
        timer = setTimeout(() => {
          timer = null
          if (started.value) open()
        }, delay)
        delay = Math.min(delay * 2, BACKOFF_MAX_MS)
      }
    }
  }

  function start(createSource: EventSourceFactory = defaultFactory): void {
    if (started.value) return
    factory = createSource
    started.value = true
    delay = BACKOFF_START_MS
    open()
  }

  function stop(): void {
    started.value = false
    connected.value = false
    lost.value = false
    if (timer) clearTimeout(timer)
    timer = null
    source?.close()
    source = null
  }

  return { byId, order, rooms, loaded, started, connected, lost, upsert, loadSnapshot, start, stop }
})
