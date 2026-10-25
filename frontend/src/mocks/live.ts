import { allStatuses, statusBus } from './db'
import { FakeEventSource } from './fakeEventSource'

/** Mock-mode stand-in for `new EventSource('./api/live')`: snapshot on connect, then bus updates. */
export class MockLiveEventSource extends FakeEventSource {
  private unsubscribe: () => void

  constructor(url: string) {
    super(url)
    this.unsubscribe = statusBus.subscribe((status) => this.emit('room', JSON.stringify(status)))
    setTimeout(() => {
      if (this.readyState === 2) return
      this.open()
      for (const status of allStatuses()) this.emit('room', JSON.stringify(status))
    }, 0)
  }

  override close(): void {
    super.close()
    this.unsubscribe()
  }
}
