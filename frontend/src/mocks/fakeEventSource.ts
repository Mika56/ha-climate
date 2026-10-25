import type { EventSourceLike } from '@/stores/live'

type Listener = (ev: MessageEvent<string>) => void

/**
 * An EventSource that never touches the network. Tests drive it with `open()`, `emit()` and `fail()`;
 * `MockLiveEventSource` (mock mode) drives it from the mock database.
 */
export class FakeEventSource implements EventSourceLike {
  static instances: FakeEventSource[] = []

  static reset(): void {
    FakeEventSource.instances = []
  }

  static get last(): FakeEventSource | undefined {
    return FakeEventSource.instances[FakeEventSource.instances.length - 1]
  }

  readyState = 0
  onopen: ((ev: Event) => void) | null = null
  onerror: ((ev: Event) => void) | null = null
  private readonly listeners = new Map<string, Set<Listener>>()

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this)
  }

  addEventListener(type: string, listener: Listener): void {
    const set = this.listeners.get(type) ?? new Set<Listener>()
    set.add(listener)
    this.listeners.set(type, set)
  }

  close(): void {
    this.readyState = 2
  }

  open(): void {
    this.readyState = 1
    this.onopen?.(new Event('open'))
  }

  emit(type: string, data: string): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener(new MessageEvent(type, { data }))
    }
  }

  /** Simulates a connection error; `closed` = the browser gave up retrying. */
  fail(closed = false): void {
    this.readyState = closed ? 2 : 0
    this.onerror?.(new Event('error'))
  }
}
