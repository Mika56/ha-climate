import { http, HttpResponse } from 'msw'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { server } from '@/test/server'
import { resetApiHooks } from '@/test/utils'
import { api, apiHooks, createApi, defaultBaseUrl, unwrap } from './client'
import { ApiError, fieldErrors, toApiError } from './errors'

afterEach(resetApiHooks)

describe('base URL', () => {
  it('is resolved against the document so it works under any ingress path', () => {
    expect(defaultBaseUrl()).toBe(new URL('./', document.baseURI).href)
    const client = createApi('http://ha.local/api/hassio_ingress/abc123/')
    expect(client).toBeDefined()
  })

  it('calls ./api/... relative to the ingress path', async () => {
    let seen = ''
    server.use(
      http.get('http://ha.local/api/hassio_ingress/abc123/api/health', ({ request }) => {
        seen = request.url
        return HttpResponse.json({ status: 'ok' })
      }),
    )
    const client = createApi('http://ha.local/api/hassio_ingress/abc123/')
    const result = await unwrap(client.GET('/api/health'))
    expect(result).toEqual({ status: 'ok' })
    expect(seen).toBe('http://ha.local/api/hassio_ingress/abc123/api/health')
  })
})

describe('unwrap', () => {
  it('returns the data, and reports the engine only for engine-backed endpoints', async () => {
    const onEngineAvailable = vi.fn()
    apiHooks.onEngineAvailable = onEngineAvailable
    const presets = await unwrap(api.GET('/api/presets'))
    expect(presets).toHaveLength(4)
    expect(onEngineAvailable).not.toHaveBeenCalled()
    await unwrap(api.GET('/api/status'))
    expect(onEngineAvailable).toHaveBeenCalledOnce()
  })

  it('maps a 422 to an ApiError with violations', async () => {
    const error = await unwrap(
      api.POST('/api/rooms', {
        body: { name: '', temperatureSensor: null, devices: [], presetTemperatures: [] } as never,
      }),
    ).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).isValidation).toBe(true)
    expect(fieldErrors(error)).toEqual({ name: expect.stringContaining('obligatoire') })
  })

  it('keeps the first message of a repeated path and ignores other errors', () => {
    const error = new ApiError(422, 'validation_failed', 'x', [
      { path: 'name', message: 'first' },
      { path: 'name', message: 'second' },
    ])
    expect(fieldErrors(error)).toEqual({ name: 'first' })
    expect(fieldErrors(new Error('boom'))).toEqual({})
  })

  it('flags the engine as unavailable on 503', async () => {
    const unavailable = vi.fn()
    apiHooks.onEngineUnavailable = unavailable
    server.use(
      http.get('*/api/status', () =>
        HttpResponse.json({ error: 'engine_unavailable' }, { status: 503 }),
      ),
    )
    const error = await unwrap(api.GET('/api/status')).catch((e: unknown) => e)
    expect((error as ApiError).status).toBe(503)
    expect((error as ApiError).code).toBe('engine_unavailable')
    expect(unavailable).toHaveBeenCalledOnce()
  })

  it('does not touch the engine state on other HTTP errors', async () => {
    const onEngineAvailable = vi.fn()
    const onEngineUnavailable = vi.fn()
    apiHooks.onEngineAvailable = onEngineAvailable
    apiHooks.onEngineUnavailable = onEngineUnavailable
    const error = await unwrap(
      api.GET('/api/rooms/{roomId}', { params: { path: { roomId: 'nope' } } }),
    ).catch((e: unknown) => e)
    expect((error as ApiError).status).toBe(404)
    expect(onEngineAvailable).not.toHaveBeenCalled()
    expect(onEngineUnavailable).not.toHaveBeenCalled()
  })

  it('turns a network failure into an ApiError(0) and notifies', async () => {
    const onNetworkError = vi.fn()
    apiHooks.onNetworkError = onNetworkError
    server.use(http.get('*/api/presets', () => HttpResponse.error()))
    const error = await unwrap(api.GET('/api/presets')).catch((e: unknown) => e)
    expect((error as ApiError).status).toBe(0)
    expect(onNetworkError).toHaveBeenCalledOnce()
  })

  it('handles empty 204 answers', async () => {
    await expect(
      unwrap(api.PUT('/api/rooms/order', { body: { roomIds: [] } })),
    ).resolves.toBeUndefined()
  })
})

describe('toApiError', () => {
  it('copes with non-JSON bodies', () => {
    const error = toApiError(502, undefined)
    expect(error).toMatchObject({
      status: 502,
      code: 'http_error',
      message: 'http_error',
      violations: [],
    })
    expect(toApiError(500, { error: 'boom', message: 'Boom!' }).message).toBe('Boom!')
  })
})
