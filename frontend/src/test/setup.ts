import { enableAutoUnmount } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, vi } from 'vitest'
import { resetMockDb } from '@/mocks/db'
import { FakeEventSource } from '@/mocks/fakeEventSource'
import { server } from './server'

enableAutoUnmount(afterEach)

beforeAll(() => {
  server.listen({ onUnhandledFrame: 'error' })
})

afterEach(() => {
  server.resetHandlers()
  resetMockDb()
  FakeEventSource.reset()
  localStorage.clear()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

afterAll(() => {
  server.close()
})

// jsdom has no EventSource: tests that need the live stream drive this fake.
globalThis.EventSource = FakeEventSource as unknown as typeof EventSource
