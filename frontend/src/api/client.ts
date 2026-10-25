import createClient from 'openapi-fetch'
import { ApiError, toApiError } from './errors'
import type { paths } from './schema'

/** Side-effect hooks wired by the app store (kept here to avoid an import cycle). */
export const apiHooks = {
  onNetworkError: (): void => {},
  onEngineUnavailable: (): void => {},
  onEngineAvailable: (): void => {},
}

/**
 * The base URL is resolved against the document, so `./api/...` works under any ingress path
 * (`/api/hassio_ingress/<token>/`) as well as at the root of the dev server.
 */
export function defaultBaseUrl(): string {
  return new URL('./', document.baseURI).href
}

export function createApi(baseUrl: string = defaultBaseUrl()) {
  // Resolve `fetch` at call time so that it can be replaced (tests, MSW) after the client is created.
  return createClient<paths>({ baseUrl, fetch: (request) => globalThis.fetch(request) })
}

export const api = createApi()

/** Endpoints answered by the engine process: a success there proves the engine is back. */
const ENGINE_BACKED =
  /\/api\/(status|discovery|rooms\/[^/]+\/(boost|override)|notifications\/test)$/

interface Result<T> {
  data?: T
  error?: unknown
  response: Response
}

/** Awaits an openapi-fetch call, returning its data or throwing an ApiError. */
export async function unwrap<T>(call: Promise<Result<T>>): Promise<T> {
  let result: Result<T>
  try {
    result = await call
  } catch {
    apiHooks.onNetworkError()
    throw new ApiError(0, 'network_error', 'Network error')
  }
  const { response } = result
  if (response.ok) {
    if (ENGINE_BACKED.test(new URL(response.url, document.baseURI).pathname)) {
      apiHooks.onEngineAvailable()
    }
    return result.data as T
  }
  if (response.status === 503) apiHooks.onEngineUnavailable()
  throw toApiError(response.status, result.error)
}
