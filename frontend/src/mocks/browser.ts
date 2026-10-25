import { setupWorker } from 'msw/browser'
import { handlers } from './handlers'
import { MockLiveEventSource } from './live'

/** Starts the MSW service worker (dev:mock only) and returns the fake EventSource to install. */
export async function startMocks(): Promise<typeof EventSource> {
  await setupWorker(...handlers).start({
    serviceWorker: { url: './mockServiceWorker.js' },
    onUnhandledRequest: 'bypass',
  })
  return MockLiveEventSource as unknown as typeof EventSource
}
