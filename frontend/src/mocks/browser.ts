import { installFetchMock } from './fetchMock'
import { handlers } from './handlers'
import { MockLiveEventSource } from './live'

/**
 * Starts the mock API (dev:mock only) and returns the fake EventSource to install.
 * The MSW service worker is preferred; where service workers are unavailable (insecure origin such
 * as a LAN address, embedded webviews) the same handlers answer through a `fetch` wrapper.
 */
export async function startMocks(): Promise<typeof EventSource> {
  try {
    const { setupWorker } = await import('msw/browser')
    await setupWorker(...handlers).start({
      serviceWorker: { url: './mockServiceWorker.js' },
      onUnhandledRequest: 'bypass',
    })
  } catch {
    installFetchMock()
  }
  return MockLiveEventSource as unknown as typeof EventSource
}
