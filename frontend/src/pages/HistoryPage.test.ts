import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { mountApp, norm, setInput, settle } from '@/test/utils'

describe('HistoryPage', () => {
  it('shows the first page and loads more', async () => {
    const { wrapper } = await mountApp('/history')
    expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(50)
    expect(norm(wrapper.find('[data-test="count"]').text())).toBe('50 entrées affichées sur 60')
    await wrapper.find('[data-test="load-more"]').trigger('click')
    await settle()
    expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(60)
    expect(wrapper.find('[data-test="load-more"]').exists()).toBe(false)
  })

  it('filters by origin pill', async () => {
    const { wrapper } = await mountApp('/history')
    await wrapper.find('[data-test="origin-device"]').trigger('click')
    await settle()
    const rows = wrapper.findAll('[data-test="entry"]')
    expect(rows).toHaveLength(mockDb.audit.filter((e) => e.actorType === 'device').length)
    expect(rows.every((r) => r.text().includes('Appareil'))).toBe(true)
    expect(wrapper.find('[data-test="origin-device"]').attributes('aria-pressed')).toBe('true')
    await wrapper.find('[data-test="origin-all"]').trigger('click')
    await settle()
    expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(50)
  })

  it('filters by room and by date range', async () => {
    const { wrapper } = await mountApp('/history')
    await setInput(wrapper, '[data-test="filter-room"]', ID.bureau)
    await settle()
    const bureau = mockDb.audit.filter((e) => e.roomId === ID.bureau).length
    expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(bureau)
    expect(wrapper.find('[data-test="entry"]').text()).toContain('Bureau')

    await setInput(wrapper, '[data-test="filter-room"]', '')
    const day = new Date(mockDb.audit[0].occurredAt).toISOString().slice(0, 10)
    await setInput(wrapper, '[data-test="filter-from"]', day)
    await setInput(wrapper, '[data-test="filter-to"]', day)
    await settle()
    const href = wrapper.find('[data-test="export-csv"]').attributes('href') ?? ''
    expect(href).toMatch(/^\.\/api\/audit\/export\?from=.+&to=.+/)
    expect(wrapper.findAll('[data-test="entry"]').length).toBeLessThanOrEqual(60)
  })

  it('builds the CSV link from the active filters', async () => {
    const { wrapper } = await mountApp('/history')
    expect(wrapper.find('[data-test="export-csv"]').attributes('href')).toBe('./api/audit/export')
    await setInput(wrapper, '[data-test="filter-room"]', ID.salon)
    await wrapper.find('[data-test="origin-user"]').trigger('click')
    await settle()
    expect(wrapper.find('[data-test="export-csv"]').attributes('href')).toBe(
      `./api/audit/export?roomId=${ID.salon}&actorType=user`,
    )
  })

  it('says so when nothing matches', async () => {
    mockDb.audit = []
    const { wrapper } = await mountApp('/history')
    expect(wrapper.text()).toContain('Aucune entrée ne correspond')
    expect(wrapper.find('[data-test="load-more"]').exists()).toBe(false)
  })

  it('labels house-wide entries and shows who acted', async () => {
    const { wrapper } = await mountApp('/history')
    expect(wrapper.text()).toContain('Maison')
    expect(wrapper.text()).toContain('Alex')
  })
})
