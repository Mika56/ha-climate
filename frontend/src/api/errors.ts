import type { ValidationError } from './types'

/** Error raised for every non-2xx answer (status 0 = the request never reached the server). */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly violations: ValidationError['violations'] = [],
  ) {
    super(message)
    this.name = 'ApiError'
  }

  get isValidation(): boolean {
    return this.status === 422
  }
}

/** Maps a 422 error to `{ "devices[0].minOnSeconds": "message" }` (first message per path wins). */
export function fieldErrors(error: unknown): Record<string, string> {
  const out: Record<string, string> = {}
  if (error instanceof ApiError) {
    for (const v of error.violations) {
      if (!(v.path in out)) out[v.path] = v.message
    }
  }
  return out
}

interface ErrorBody {
  error?: unknown
  message?: unknown
  violations?: unknown
}

export function toApiError(status: number, body: unknown): ApiError {
  const b: ErrorBody = typeof body === 'object' && body !== null ? body : {}
  const code = typeof b.error === 'string' ? b.error : 'http_error'
  const message = typeof b.message === 'string' ? b.message : code
  const violations = Array.isArray(b.violations)
    ? (b.violations as ValidationError['violations'])
    : []
  return new ApiError(status, code, message, violations)
}
