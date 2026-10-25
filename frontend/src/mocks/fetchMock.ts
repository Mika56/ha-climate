import { getResponse } from 'msw'
import { handlers } from './handlers'

/** Answers `fetch` from the MSW handlers (no service worker needed); other requests pass through. */
export function installFetchMock(target: { fetch: typeof fetch } = window): void {
  const nativeFetch = target.fetch.bind(target)
  target.fetch = async (input, init) => {
    const request = new Request(input, init)
    return (await getResponse(handlers, request)) ?? nativeFetch(request)
  }
}
