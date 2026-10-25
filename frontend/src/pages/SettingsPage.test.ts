import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { server } from '@/test/server'
import { mountApp, norm, setChange, setInput, settle } from '@/test/utils'

type Wrapper = Awaited<ReturnType<typeof mountApp>>['wrapper']

async function pickFile(wrapper: Wrapper, name: string, content: string): Promise<void> {
  const input = wrapper.find<HTMLInputElement>('[data-test="import-file"]')
  const file = new File([content], name, { type: 'application/json' })
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  await settle()
}

describe('SettingsPage', () => {
  it('loads every section from the API', async () => {
    const { wrapper } = await mountApp('/settings')
    expect(wrapper.text()).toContain('Présence globale')
    expect(
      (wrapper.find('[data-test="person-person.alex"]').element as HTMLInputElement).checked,
    ).toBe(true)
    expect(
      (wrapper.find('[data-test="person-person.charlie"]').element as HTMLInputElement).checked,
    ).toBe(false)
    expect(
      (wrapper.find('[data-test="house-empty"] input').element as HTMLInputElement).value,
    ).toBe('15')
    expect(
      (wrapper.find('[data-test="manual-minutes"] input').element as HTMLInputElement).value,
    ).toBe('120')
    expect((wrapper.find('[data-test="retention"] input').element as HTMLInputElement).value).toBe(
      '90',
    )
    expect(norm(wrapper.text())).toContain('12 418 entrées')
    expect(wrapper.findAll('[data-test="preset-row"]')).toHaveLength(4)
    expect(norm(wrapper.find('[data-test="preset-row"]').text())).toContain('Confort')
    expect(wrapper.find('[data-test="export"]').attributes('href')).toBe('./api/backup')
  })

  it('saves general settings, converting minutes to seconds', async () => {
    const { wrapper } = await mountApp('/settings')
    expect(wrapper.find('[data-test="save-settings"]').attributes('disabled')).toBeDefined()
    await wrapper
      .findAll('.seg-btn')
      .find((b) => b.text() === 'Été')
      ?.trigger('click')
    await setInput(wrapper, '[data-test="house-empty"] input', '30')
    await wrapper.find('[data-test="person-person.charlie"]').setValue(true)
    await wrapper.find('[data-test="person-person.alex"]').setValue(false)
    await setInput(wrapper, '[data-test="weather-entity"]', 'weather.forecast_home')
    await setInput(wrapper, '[data-test="notification-locale"]', 'en')
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(mockDb.settings).toMatchObject({
      season: 'summer',
      houseEmptyDelaySeconds: 1800,
      trackedPersons: ['person.sam', 'person.charlie'],
      weatherEntity: 'weather.forecast_home',
      notificationLocale: 'en',
    })
    expect(wrapper.find('.toasts').text()).toContain('Réglages enregistrés.')
  })

  it('enables the vacation mode with local dates converted to the HA zone', async () => {
    const { wrapper } = await mountApp('/settings')
    await wrapper.find('input[aria-label="Activer le mode vacances"]').setValue(true)
    await setInput(wrapper, '[data-test="vacation-start"]', '2026-10-31T09:00')
    await setInput(wrapper, '[data-test="vacation-return"]', '2026-11-08T18:00')
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(mockDb.settings.vacation).toMatchObject({
      enabled: true,
      startAt: '2026-10-31T09:00:00+01:00',
      returnAt: '2026-11-08T18:00:00+01:00',
    })
  })

  it('maps a 422 on the vacation return date', async () => {
    const { wrapper } = await mountApp('/settings')
    await wrapper.find('input[aria-label="Activer le mode vacances"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(wrapper.text()).toContain('La date de retour est obligatoire.')
    expect(wrapper.find('[data-test="vacation-return"]').attributes('aria-invalid')).toBe('true')
    // Cancel drops the edits.
    await wrapper
      .findAll('.actions button')
      .find((b) => b.text() === 'Annuler')
      ?.trigger('click')
    expect(wrapper.find('[data-test="vacation-return"]').exists()).toBe(false)
  })

  it('creates, edits and deletes presets', async () => {
    const { wrapper } = await mountApp('/settings')
    await wrapper.find('[data-test="add-preset"]').trigger('click')
    await setInput(wrapper, '[data-test="preset-key"]', 'boost_max')
    await setInput(wrapper, '[data-test="preset-label"]', 'Max')
    await setInput(wrapper, '[data-test="preset-color"]', '#ff0000')
    await wrapper.find('[data-test="preset-form"]').trigger('submit')
    await settle()
    expect(mockDb.presets.find((p) => p.key === 'boost_max')).toMatchObject({
      label: 'Max',
      color: '#ff0000',
    })
    expect(wrapper.findAll('[data-test="preset-row"]')).toHaveLength(5)

    await wrapper.find('button[aria-label="Modifier le preset Max"]').trigger('click')
    expect((wrapper.find('[data-test="preset-key"]').element as HTMLInputElement).disabled).toBe(
      true,
    )
    await setInput(wrapper, '[data-test="preset-label"]', 'Maximum')
    await wrapper.find('[data-test="preset-form"]').trigger('submit')
    await settle()
    expect(mockDb.presets.find((p) => p.key === 'boost_max')?.label).toBe('Maximum')

    await wrapper.find('button[aria-label="Modifier le preset Maximum"]').trigger('click')
    await wrapper.find('[data-test="preset-delete"]').trigger('click')
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(mockDb.presets.some((p) => p.key === 'boost_max')).toBe(false)
  })

  it('maps preset validation errors and refuses to delete a used preset', async () => {
    const { wrapper } = await mountApp('/settings')
    await wrapper.find('[data-test="add-preset"]').trigger('click')
    await setInput(wrapper, '[data-test="preset-key"]', 'Bad Key')
    await wrapper.find('[data-test="preset-form"]').trigger('submit')
    await settle()
    expect(wrapper.text()).toContain('Identifiant invalide')
    await wrapper
      .findAll('[data-test="preset-form"] button')
      .find((b) => b.text() === 'Annuler')
      ?.trigger('click')

    await wrapper.find('button[aria-label="Modifier le preset Confort"]').trigger('click')
    await wrapper.find('[data-test="preset-delete"]').trigger('click')
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('encore utilisé')
    expect(mockDb.presets.some((p) => p.key === 'comfort')).toBe(true)
  })

  it('stores theme and keeps the explicit choice per user', async () => {
    const { wrapper } = await mountApp('/settings')
    expect(wrapper.find('.hc').classes()).not.toContain('dark')
    await setChange(wrapper, '[data-test="theme-select"]', 'dark')
    await settle()
    expect(mockDb.preferences.theme).toBe('dark')
    expect(wrapper.find('.hc').classes()).toContain('dark')
  })

  it('imports a backup only after a confirmation that warns about replacement', async () => {
    const { wrapper } = await mountApp('/settings')
    const backup = await (await fetch('http://localhost:3000/api/backup')).text()
    await pickFile(wrapper, 'ha.json', backup)
    expect(norm(document.body.textContent)).toContain('remplace toutes les pièces')
    await wrapper.findAll('.dialog button')[0].trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).not.toContain('Configuration restaurée')

    await pickFile(wrapper, 'ha.json', backup)
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(norm(wrapper.find('.toasts').text())).toContain(
      'Configuration restaurée : 6 pièces, 4 plannings, 4 presets.',
    )
    expect(wrapper.findAll('[data-test="preset-row"]')).toHaveLength(4)
  })

  it('rejects a file that is not JSON or not a backup', async () => {
    const { wrapper } = await mountApp('/settings')
    await pickFile(wrapper, 'x.json', 'not json')
    expect(wrapper.find('.toasts').text()).toContain('pas un document JSON valide')
    expect(document.querySelector('.dialog')).toBeNull()

    await pickFile(wrapper, 'x.json', JSON.stringify({ format: 'other' }))
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('pas une sauvegarde HA Climate valide')
    expect(mockDb.rooms).toHaveLength(6)
  })

  it('reports a failed import as a generic error', async () => {
    server.use(
      http.post('*/api/backup/restore', () => HttpResponse.json({ error: 'x' }, { status: 500 })),
    )
    const { wrapper } = await mountApp('/settings')
    await pickFile(wrapper, 'x.json', JSON.stringify({ format: 'ha-climate-backup', version: 1 }))
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('Une erreur est survenue')
    expect(mockDb.rooms.some((r) => r.id === ID.salon)).toBe(true)
  })

  it('selects an unknown weather entity without losing it', async () => {
    mockDb.settings.weatherEntity = 'weather.gone'
    const { wrapper } = await mountApp('/settings')
    expect((wrapper.find('[data-test="weather-entity"]').element as HTMLSelectElement).value).toBe(
      'weather.gone',
    )
  })
})
