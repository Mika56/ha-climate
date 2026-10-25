import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { server } from '@/test/server'
import { mountApp, norm, setInput, settle } from '@/test/utils'

describe('RoomsPage', () => {
  it('lists rooms with their alerts and selects one', async () => {
    const { wrapper, router } = await mountApp('/rooms')
    const items = wrapper.findAll('.room-item')
    expect(items.map((i) => i.text())).toEqual(
      expect.arrayContaining([
        expect.stringContaining('Salon'),
        expect.stringContaining('Cuisine'),
      ]),
    )
    expect(wrapper.text()).toContain('Sélectionnez une pièce')
    await items[0].trigger('click')
    await settle()
    expect(router.currentRoute.value.path).toBe(`/rooms/${ID.salon}`)
    expect(wrapper.find('[data-test="room-title"]').text()).toBe('Salon')
  })

  it('shows the room form with converted delays and learned rates', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    expect((wrapper.find('[data-test="room-name"]').element as HTMLInputElement).value).toBe(
      'Salon',
    )
    expect(
      (wrapper.find('[data-test="vacancy-delay"] input').element as HTMLInputElement).value,
    ).toBe('20')
    expect(norm(wrapper.find('[data-test="rate-heat"]').text())).toBe('1,2 °C/h · 34 mesures')
    expect(wrapper.find('[data-test="rate-cool"]').text()).toContain('0,9')
    expect(wrapper.text()).toContain('climate.salon_radiateur')
    expect(wrapper.text()).toContain('binary_sensor.salon_baie_vitree')
    const area = wrapper.find('[data-test="room-area"]').element as HTMLSelectElement
    expect(area.value).toBe('salon')
  })

  it('filters sensors by area unless "show all" is checked', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    const options = () =>
      wrapper
        .findAll('select')
        .flatMap((s) => s.findAll('option').map((o) => o.text()))
        .filter((t) => t.startsWith('sensor.'))
        .map(norm)
    expect(options()).toEqual(['sensor.salon_temperature (20.4 °C)'])
    await wrapper.find('[data-test="show-all"]').setValue(true)
    expect(options().length).toBeGreaterThan(3)
  })

  it('saves an edited room (seconds in the API) and marks the form clean', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    expect(wrapper.find('[data-test="save-room"]').attributes('disabled')).toBeDefined()
    await setInput(wrapper, '[data-test="room-name"]', 'Grand salon')
    await setInput(wrapper, '[data-test="vacancy-delay"] input', '30')
    expect(wrapper.find('[data-test="save-room"]').attributes('disabled')).toBeUndefined()
    await wrapper.find('form').trigger('submit')
    await settle()
    const saved = mockDb.rooms.find((r) => r.id === ID.salon)
    expect(saved?.name).toBe('Grand salon')
    expect(saved?.vacancyDelaySeconds).toBe(1800)
    expect(wrapper.find('.toasts').text()).toContain('Pièce enregistrée.')
    expect(wrapper.find('[data-test="save-room"]').attributes('disabled')).toBeDefined()
  })

  it('maps a 422 to the fields and keeps the form dirty', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    await setInput(wrapper, '[data-test="room-name"]', '')
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(wrapper.text()).toContain('Le nom est obligatoire')
    expect(wrapper.find('[data-test="room-name"]').attributes('aria-invalid')).toBe('true')
    expect(mockDb.rooms[0].name).toBe('Salon')

    // A device-level violation lands next to the right input.
    await setInput(wrapper, '[data-test="room-name"]', 'Salon')
    const minOn = wrapper.find('input[aria-label="Marche minimale de climate.salon_radiateur"]')
    ;(minOn.element as HTMLInputElement).value = '500'
    await minOn.trigger('input')
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(wrapper.text()).toContain('devices[0].minOnSeconds')
    expect(wrapper.find('td .err').text()).toContain('entre 0 et 7200')
  })

  it('creates a room and navigates to it', async () => {
    const { wrapper, router } = await mountApp('/rooms/new')
    expect(wrapper.find('[data-test="room-title"]').text()).toBe('Nouvelle pièce')
    await setInput(wrapper, '[data-test="room-name"]', 'Garage')
    await setInput(wrapper, '[data-test="room-area"]', '')
    await wrapper.find('[data-test="add-device"]').trigger('click')
    await setInput(wrapper, '[data-test="device-select"]', 'climate.bureau_radiateur')
    await wrapper.find('[data-test="heating-schedule"]').setValue(ID.semaine)
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(mockDb.rooms.at(-1)?.name).toBe('Garage')
    expect(mockDb.rooms.at(-1)?.devices[0].entityId).toBe('climate.bureau_radiateur')
    expect(router.currentRoute.value.path).toBe(`/rooms/${mockDb.rooms.at(-1)?.id}`)
  })

  it('explains which opening sensors are listed in a help bubble', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.cuisine}`)
    const tip = wrapper.find('[data-test="info-tip"]')
    expect(tip.attributes('aria-label')).toBe('Aide')
    expect(wrapper.find('[data-test="info-tip-text"]').exists()).toBe(false)

    await tip.trigger('click')
    expect(tip.attributes('aria-expanded')).toBe('true')
    const text = wrapper.find('[data-test="info-tip-text"]')
    expect(text.text()).toContain('Fenêtre, Porte, Ouverture ou Porte de garage')
    expect(tip.attributes('aria-describedby')).toBe(text.attributes('id'))

    await tip.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-test="info-tip-text"]').exists()).toBe(false)
    await wrapper.find('.info-tip').trigger('mouseenter')
    expect(wrapper.find('[data-test="info-tip-text"]').exists()).toBe(true)
    await wrapper.find('.info-tip').trigger('mouseleave')
    await tip.trigger('click')
    await tip.trigger('blur')
    expect(wrapper.find('[data-test="info-tip-text"]').exists()).toBe(false)
  })

  it('adds and removes openings, devices and role', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.cuisine}`)
    await wrapper.find('[data-test="show-all"]').setValue(true)
    await wrapper.find('[data-test="add-opening"]').trigger('click')
    await setInput(wrapper, '[data-test="opening-select"]', 'binary_sensor.porte_garage')
    expect(wrapper.text()).toContain('binary_sensor.porte_garage')
    await wrapper.find('button[aria-label="Retirer binary_sensor.porte_garage"]').trigger('click')
    expect(wrapper.text()).not.toContain('binary_sensor.porte_garage')

    const role = wrapper.find('select[aria-label="Rôle de climate.cuisine_radiateur"]')
    await role.setValue('reversible')
    await wrapper.find('button[aria-label="Retirer climate.cuisine_radiateur"]').trigger('click')
    expect(wrapper.text()).toContain('Aucun appareil pour le moment.')
  })

  it('toggles preheating and reverts with Cancel', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.salon}`)
    const toggle = wrapper.find('input[aria-label="Activer le préchauffage"]')
    await toggle.setValue(false)
    expect(wrapper.find('[data-test="rate-heat"]').exists()).toBe(false)
    const cancel = wrapper.findAll('.sticky-actions button')[0]
    await cancel.trigger('click')
    expect(wrapper.find('[data-test="rate-heat"]').exists()).toBe(true)
  })

  it('deletes a room after confirmation', async () => {
    const { wrapper, router } = await mountApp(`/rooms/${ID.bureau}`)
    await wrapper.find('[data-test="delete-room"]').trigger('click')
    expect(norm(document.body.textContent)).toContain('Supprimer Bureau ?')
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(mockDb.rooms.some((r) => r.id === ID.bureau)).toBe(false)
    expect(router.currentRoute.value.path).toBe('/rooms')
  })

  it('keeps the room when the deletion is cancelled', async () => {
    const { wrapper } = await mountApp(`/rooms/${ID.bureau}`)
    await wrapper.find('[data-test="delete-room"]').trigger('click')
    await wrapper.findAll('.dialog button')[0].trigger('click')
    await settle()
    expect(mockDb.rooms.some((r) => r.id === ID.bureau)).toBe(true)
  })

  it('reorders rooms', async () => {
    const { wrapper } = await mountApp('/rooms')
    await wrapper.find('button[aria-label="Descendre Salon"]').trigger('click')
    await settle()
    expect(mockDb.rooms.map((r) => r.name).slice(0, 2)).toEqual(['Cuisine', 'Salon'])
    await wrapper.find('button[aria-label="Monter Salon"]').trigger('click')
    await settle()
    expect(mockDb.rooms[0].name).toBe('Salon')
  })

  it('restores the order when reordering fails', async () => {
    server.use(
      http.put('*/api/rooms/order', () => HttpResponse.json({ error: 'x' }, { status: 500 })),
    )
    const { wrapper } = await mountApp('/rooms')
    await wrapper.find('button[aria-label="Descendre Salon"]').trigger('click')
    await settle()
    expect(wrapper.findAll('.room-item')[0].text()).toContain('Salon')
  })

  it('asks before leaving a dirty form and stays when refused', async () => {
    const { wrapper, router } = await mountApp(`/rooms/${ID.salon}`)
    await setInput(wrapper, '[data-test="room-name"]', 'Autre')
    const navigation = router.push(`/rooms/${ID.cuisine}`)
    await settle()
    expect(norm(document.body.textContent)).toContain('Abandonner vos modifications ?')
    await wrapper.findAll('.dialog button')[0].trigger('click')
    await navigation
    expect(router.currentRoute.value.path).toBe(`/rooms/${ID.salon}`)

    const second = router.push('/settings')
    await settle()
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await second
    expect(router.currentRoute.value.path).toBe('/settings')
  })
})
