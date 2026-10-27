import type { VueWrapper } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { mountApp, norm, setInput, settle } from '@/test/utils'

const rows = (wrapper: VueWrapper) => wrapper.findAll('[data-test="signal-row"]')
const saved = (id: string) => mockDb.rooms.find((r) => r.id === id)?.presenceSignals

async function addSignal(wrapper: VueWrapper, entityId: string, everyArea = false): Promise<void> {
  await wrapper.find('[data-test="add-signal"] [data-test="picker-trigger"]').trigger('click')
  if (everyArea) await wrapper.find('[data-test="picker-show-all"]').setValue(true)
  await wrapper.find(`[data-test="picker-option"][data-entity-id="${entityId}"]`).trigger('click')
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('form').trigger('submit')
  await settle()
}

describe('presence signals of the room form', () => {
  it('lists the signals with name, live state and the parameter of their kind', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    const [power, bermuda] = rows(wrapper)
    expect(power.text()).toContain('Puissance PC bureau')
    expect(power.text()).toContain('sensor.bureau_pc_power')
    expect(norm(power.find('[data-test="entity-state"]').text())).toBe('148.2 W')
    expect(power.find('[aria-pressed="true"]').text()).toBe('Au-dessus')
    expect(
      (power.find('[data-test="signal-threshold"] input').element as HTMLInputElement).value,
    ).toBe('20')
    expect(power.find('[data-test="signal-value"]').exists()).toBe(false)

    expect(bermuda.find('[aria-pressed="true"]').text()).toBe('Égal à')
    const value = bermuda.find('[data-test="signal-value"]')
    expect((value.element as HTMLInputElement).value).toBe('Bureau')
    expect(bermuda.find('[data-test="signal-threshold"]').exists()).toBe(false)
    // The suggestions are the areas of Home Assistant plus the current state.
    const options = bermuda.findAll('datalist option').map((o) => o.attributes('value'))
    expect(options).toEqual(['Salon', 'Cuisine', 'Chambres', 'Bureau', 'Salle de bain'])
  })

  it('explains how the signals are combined in a help bubble', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    const tip = wrapper.find('[data-test="signals-section"] [data-test="info-tip"]')
    await tip.trigger('click')
    const text = wrapper.find('[data-test="signals-section"] [data-test="info-tip-text"]').text()
    expect(text).toContain('Une lumière restée allumée garde la pièce occupée')
    expect(text).toContain('indisponible est ignoré')
  })

  it('says so when there is no signal', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.parents}`)
    expect(wrapper.find('[data-test="no-signals"]').text()).toContain('Aucun signal')
  })

  it('adds signals with the kind and parameter suggested by the source', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.parents}`)
    await addSignal(wrapper, 'binary_sensor.salon_occupancy', true)
    await addSignal(wrapper, 'light.salon_plafonnier', true)
    await addSignal(wrapper, 'sensor.salon_tv_power', true)
    await addSignal(wrapper, 'sensor.bermuda_sam_area', true)
    expect(rows(wrapper)).toHaveLength(4)
    expect(wrapper.find('[data-test="no-signals"]').exists()).toBe(false)
    await submit(wrapper)
    expect(saved(ID.parents)).toEqual([
      { entityId: 'binary_sensor.salon_occupancy', kind: 'active', threshold: null, value: null },
      { entityId: 'light.salon_plafonnier', kind: 'active', threshold: null, value: null },
      { entityId: 'sensor.salon_tv_power', kind: 'above', threshold: 20, value: null },
      { entityId: 'sensor.bermuda_sam_area', kind: 'equals', threshold: null, value: 'Salon' },
    ])
  })

  it('does not offer a signal twice', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    await wrapper.find('[data-test="add-signal"] [data-test="picker-trigger"]').trigger('click')
    const ids = wrapper
      .findAll('[data-test="picker-option"]')
      .map((o) => o.attributes('data-entity-id'))
    expect(ids).not.toContain('sensor.bureau_pc_power')
    expect(ids).toContain('light.bureau_lampe')
  })

  it('changes the kind, keeping a sensible parameter for the new one', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    const kind = (row: number, label: string) =>
      rows(wrapper)
        [row].findAll('.seg-btn')
        .find((b) => b.text() === label)!
        .trigger('click')

    await kind(0, 'Actif')
    expect(rows(wrapper)[0].find('[data-test="signal-threshold"]').exists()).toBe(false)
    await kind(0, 'Égal à')
    expect(
      (rows(wrapper)[0].find('[data-test="signal-value"]').element as HTMLInputElement).value,
    ).toBe('148.2')
    await kind(0, 'Au-dessus')
    expect(
      (rows(wrapper)[0].find('[data-test="signal-threshold"] input').element as HTMLInputElement)
        .value,
    ).toBe('20')
    await submit(wrapper)
    expect(saved(ID.bureau)?.[0]).toEqual({
      entityId: 'sensor.bureau_pc_power',
      kind: 'above',
      threshold: 20,
      value: null,
    })
  })

  it('edits the threshold and the value and sends them', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    await setInput(wrapper, '[data-test="signal-threshold"] input', '75.5')
    await setInput(wrapper, '[data-test="signal-value"]', 'Salon')
    await submit(wrapper)
    expect(saved(ID.bureau)).toEqual([
      { entityId: 'sensor.bureau_pc_power', kind: 'above', threshold: 75.5, value: null },
      { entityId: 'sensor.bermuda_alex_area', kind: 'equals', threshold: null, value: 'Salon' },
    ])
  })

  it('removes a signal', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    await wrapper.find('[data-test="signal-remove"]').trigger('click')
    expect(rows(wrapper)).toHaveLength(1)
    await submit(wrapper)
    expect(saved(ID.bureau)?.map((s) => s.entityId)).toEqual(['sensor.bermuda_alex_area'])
  })

  it('shows the validation error under the right row and keeps the form dirty', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    await setInput(wrapper, '[data-test="signal-value"]', '')
    const threshold = wrapper.find<HTMLInputElement>('[data-test="signal-threshold"] input')
    threshold.element.value = ''
    await threshold.trigger('input')
    await submit(wrapper)
    expect(wrapper.text()).toContain('presenceSignals[0].threshold')
    const [power, bermuda] = rows(wrapper)
    expect(power.find('[data-test="signal-error"]').text()).toBe('Le seuil est obligatoire.')
    expect(bermuda.find('[data-test="signal-error"]').text()).toBe('La valeur est obligatoire.')
    expect(power.find('input').attributes('aria-invalid')).toBe('true')
    expect(bermuda.find('[data-test="signal-value"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[data-test="save-room"]').attributes('disabled')).toBeUndefined()
  })

  it('flags a signal whose entity no longer exists', async () => {
    mockDb.rooms[0].presenceSignals = [
      { entityId: 'binary_sensor.gone', kind: 'active', threshold: null, value: null },
    ]
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    expect(rows(wrapper)[0].text()).toContain('binary_sensor.gone')
    expect(rows(wrapper)[0].text()).toContain('introuvable')
  })
})
