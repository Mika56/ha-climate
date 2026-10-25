import { describe, expect, it, vi } from 'vitest'
import { installFetchMock } from './fetchMock'

describe('installFetchMock', () => {
  it('answers API calls from the handlers and lets other requests through', async () => {
    const native = vi.fn(async () => new Response('native'))
    const target = { fetch: native as unknown as typeof fetch }
    installFetchMock(target)

    const health = await target.fetch('http://localhost:3000/api/health')
    expect(await health.json()).toEqual({ status: 'ok' })
    expect(native).not.toHaveBeenCalled()

    const other = await target.fetch('http://localhost:3000/some/asset.js')
    expect(await other.text()).toBe('native')
    expect(native).toHaveBeenCalledOnce()
  })
})
