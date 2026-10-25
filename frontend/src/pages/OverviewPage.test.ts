import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { allStatuses, mockDb } from '@/mocks/db'
import { FakeEventSource } from '@/mocks/fakeEventSource'
import { server } from '@/test/server'
import { mountApp, settle } from '@/test/utils'

function card(wrapper: Awaited<ReturnType<typeof mountApp>>['wrapper'], name: string) {
  const found = wrapper.findAll('article').find((a) => a.find('h3').text() === name)
  if (!found) throw new Error(`no card for ${name}`)
  return found
}

describe('OverviewPage', () => {
  it('shows season, presence, weather, vacation, alerts, room cards and recent decisions', async () => {
    const { wrapper } = await mountApp('/')
    const text = wrapper.text()
    expect(text).toContain('2 présents')
    expect(wrapper.find('[data-test="presence-detail"]').text()).toContain('Alex')
    expect(text).toContain('12,5\u00a0°C')
    expect(text).toContain('Partiellement nuageux')
    expect(text).toContain('Désactivé')
    expect(wrapper.findAll('[data-test="alert"]')).toHaveLength(2)
    expect(wrapper.findAll('article')).toHaveLength(6)
    expect(wrapper.find('[data-test="rooms-summary"]').text()).toMatch(/sur 6 en chauffe/)
    expect(text).toContain('Boost +1,5 °C demandé par Alex')
  })

  it('renders room cards with activity, targets, badges and reason sentences', async () => {
    const { wrapper } = await mountApp('/')
    const salon = card(wrapper, 'Salon')
    expect(salon.find('[data-test="activity"]').text()).toBe('Chauffe en cours')
    expect(salon.find('[data-test="target"]').text()).toBe('21,0\u00a0°C')
    expect(salon.find('[data-test="applied"]').text()).toBe(
      'Réglage actif\u00a0: Chauffage 21,0\u00a0°C',
    )
    expect(salon.find('[data-test="reason"]').text()).toMatch(
      /^Planning\u00a0: Confort jusqu’à \d\d:\d\d\./,
    )

    const cuisine = card(wrapper, 'Cuisine')
    expect(cuisine.text()).toContain('Inoccupée · 25\u00a0min')
    expect(cuisine.find('[data-test="activity"]').text()).toBe('Au repos')

    const parents = card(wrapper, 'Chambre parentale')
    expect(parents.text()).toContain('Capteur indisponible')
    expect(parents.find('[data-test="current"]').text()).toBe('—\u00a0°C')

    const sdb = card(wrapper, 'Salle de bain')
    expect(sdb.text()).toContain('Fenêtre ouverte · 32\u00a0min')
    expect(sdb.find('[data-test="target"]').text()).toBe('Coupé')
    expect(sdb.find('[data-test="applied"]').text()).toBe('Réglage actif\u00a0: Éteint')
    expect(sdb.find('[data-test="boost-open"]').exists()).toBe(false)

    expect(card(wrapper, 'Bureau').text()).toContain('Surcharge manuelle')
    expect(card(wrapper, 'Chambre enfant').text()).toContain('Boost +1,5\u00a0°C')
  })

  it('starts a boost from the inline panel and updates the card', async () => {
    const { wrapper } = await mountApp('/')
    const salon = card(wrapper, 'Salon')
    await salon.find('[data-test="boost-open"]').trigger('click')
    expect(salon.find('[data-test="boost-delta"]').text()).toBe('+1,5\u00a0°C')

    await salon.find('[data-test="boost-inc"]').trigger('click')
    await salon.find('[data-test="boost-inc"]').trigger('click')
    expect(salon.find('[data-test="boost-delta"]').text()).toBe('+2,5\u00a0°C')
    await salon.find('[data-test="boost-dec"]').trigger('click')
    await salon.find('[data-test="boost-duration-120"]').trigger('click')
    expect(salon.find('[data-test="boost-duration-120"]').attributes('aria-pressed')).toBe('true')
    await salon.find('[data-test="boost-confirm"]').trigger('click')
    await settle()

    expect(mockDb.overrides[ID.salon]).toMatchObject({ type: 'boost', delta: 2 })
    const updated = card(wrapper, 'Salon')
    expect(updated.find('[data-test="target"]').text()).toBe('23,0\u00a0°C')
    expect(updated.find('[data-test="boost-delta"]').exists()).toBe(false)
    expect(updated.find('[data-test="cancel-override"]').text()).toBe('Annuler le boost')
  })

  it('offers "next slot" and bounds the stepper', async () => {
    const { wrapper } = await mountApp('/')
    const salon = card(wrapper, 'Salon')
    await salon.find('[data-test="boost-open"]').trigger('click')
    for (let i = 0; i < 10; i++) await salon.find('[data-test="boost-dec"]').trigger('click')
    expect(salon.find('[data-test="boost-delta"]').text()).toBe('+0,5\u00a0°C')
    expect(salon.find('[data-test="boost-dec"]').attributes('disabled')).toBeDefined()
    for (let i = 0; i < 12; i++) await salon.find('[data-test="boost-inc"]').trigger('click')
    expect(salon.find('[data-test="boost-delta"]').text()).toBe('+5,0\u00a0°C')
    await salon.find('[data-test="boost-duration-next"]').trigger('click')
    await salon.find('[data-test="boost-confirm"]').trigger('click')
    await settle()
    expect(mockDb.overrides[ID.salon]).toMatchObject({
      delta: 5,
      untilNextSlot: true,
      endsAt: null,
    })
    expect(card(wrapper, 'Salon').text()).toContain('prochain créneau')
  })

  it('cancels a boost and a manual override', async () => {
    const { wrapper } = await mountApp('/')
    await card(wrapper, 'Chambre enfant').find('[data-test="cancel-override"]').trigger('click')
    await settle()
    expect(mockDb.overrides[ID.enfant]).toBeUndefined()
    expect(card(wrapper, 'Chambre enfant').find('[data-test="cancel-override"]').exists()).toBe(
      false,
    )

    const bureau = card(wrapper, 'Bureau')
    expect(bureau.find('[data-test="cancel-override"]').text()).toBe('Annuler la surcharge')
    await bureau.find('[data-test="cancel-override"]').trigger('click')
    await settle()
    expect(mockDb.overrides[ID.bureau]).toBeUndefined()
  })

  it('closes the boost panel on cancel', async () => {
    const { wrapper } = await mountApp('/')
    const salon = card(wrapper, 'Salon')
    await salon.find('[data-test="boost-open"]').trigger('click')
    await salon
      .findAll('button')
      .find((b) => b.text() === 'Annuler')
      ?.trigger('click')
    expect(salon.find('[data-test="boost-delta"]').exists()).toBe(false)
  })

  it('shows a toast when the engine refuses the boost', async () => {
    const { wrapper } = await mountApp('/')
    server.use(
      http.post('*/api/rooms/:roomId/boost', () =>
        HttpResponse.json({ error: 'no_active_mode' }, { status: 409 }),
      ),
    )
    const salon = card(wrapper, 'Salon')
    await salon.find('[data-test="boost-open"]').trigger('click')
    await salon.find('[data-test="boost-confirm"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('Rien à booster')
  })

  it('switches the season and reflects the change', async () => {
    const { wrapper } = await mountApp('/')
    const summer = wrapper.findAll('.seg-btn').find((b) => b.text().includes('Été'))
    await summer?.trigger('click')
    await settle()
    expect(mockDb.settings.season).toBe('summer')
    expect(wrapper.find('.seg-btn.on').text()).toBe('Été')
    expect(wrapper.find('[data-test="rooms-summary"]').text()).toMatch(/en refroidissement/)

    expect(wrapper.findAll('.seg-btn').map((b) => b.text())).toEqual(['Hiver', 'Été'])
  })

  it('shows a switch, no banner and live room cards while HA Climate is active', async () => {
    const { wrapper } = await mountApp('/')
    const toggle = wrapper.find('[data-test="pause-switch"]')
    expect(toggle.attributes('role')).toBe('switch')
    expect(toggle.attributes('aria-label')).toBe('HA Climate actif')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('[data-test="pause-banner"]').exists()).toBe(false)
    expect(card(wrapper, 'Salon').text()).not.toContain('Simulation')
  })

  it('pauses with the switch: banner, simulation chips, wording and disabled boosts', async () => {
    const { wrapper } = await mountApp('/')
    await wrapper.find('[data-test="pause-switch"]').setValue(false)
    await settle()
    expect(mockDb.settings.paused).toBe(true)
    expect(wrapper.find('[data-test="pause-banner"]').text()).toContain(
      'HA Climate est en pause\u00a0: aucune action n’est envoyée, l’historique continue.',
    )
    expect(wrapper.find('[data-test="pause-state"]').text()).toContain('En pause')
    expect(wrapper.find('.toasts').text()).toContain('HA Climate est en pause.')

    const salon = card(wrapper, 'Salon')
    expect(salon.text()).toContain('Simulation')
    expect(salon.text()).toContain('Appliquerait')
    expect(salon.text()).not.toContain('Consigne')
    expect(salon.find('[data-test="target"]').text()).toBe('21,0\u00a0°C')
    const boost = salon.find('[data-test="boost-open"]')
    expect(boost.attributes('disabled')).toBeDefined()
    expect(boost.attributes('title')).toBe(
      'Le boost est indisponible tant que HA Climate est en pause.',
    )
    expect(
      card(wrapper, 'Chambre enfant').find('[data-test="cancel-override"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('resumes from the banner button', async () => {
    mockDb.settings.paused = true
    const { wrapper } = await mountApp('/')
    expect(wrapper.find('[data-test="pause-banner"]').exists()).toBe(true)
    expect(card(wrapper, 'Salon').text()).toContain('Simulation')

    await wrapper.find('[data-test="pause-resume"]').trigger('click')
    await settle()

    expect(mockDb.settings.paused).toBe(false)
    expect(wrapper.find('[data-test="pause-banner"]').exists()).toBe(false)
    expect(card(wrapper, 'Salon').text()).not.toContain('Simulation')
    expect(card(wrapper, 'Salon').find('[data-test="boost-open"]').attributes('disabled')).toBe(
      undefined,
    )
    expect(wrapper.find('.toasts').text()).toContain('HA Climate a repris.')
  })

  it('goes back to the previous state when pausing fails', async () => {
    const { wrapper } = await mountApp('/')
    server.use(http.put('*/api/pause', () => HttpResponse.json({ error: 'boom' }, { status: 500 })))

    await wrapper.find('[data-test="pause-switch"]').setValue(false)
    await settle()

    expect(wrapper.find('[data-test="pause-banner"]').exists()).toBe(false)
    expect((wrapper.find('[data-test="pause-switch"]').element as HTMLInputElement).checked).toBe(
      true,
    )
    expect(wrapper.find('.toasts').text()).toContain('Une erreur est survenue')
  })

  it('reverts the season when the request fails', async () => {
    const { wrapper } = await mountApp('/')
    server.use(
      http.put('*/api/season', () => HttpResponse.json({ error: 'boom' }, { status: 500 })),
    )
    const summer = wrapper.findAll('.seg-btn').find((b) => b.text().includes('Été'))
    await summer?.trigger('click')
    await settle()
    expect(wrapper.find('.seg-btn.on').text()).toBe('Hiver')
    expect(wrapper.find('.toasts').text()).toContain('Une erreur est survenue')
  })

  it('applies live updates pushed over SSE', async () => {
    const { wrapper } = await mountApp('/')
    const source = FakeEventSource.last
    expect(source?.url).toBe('./api/live')
    source?.open()
    const [first] = allStatuses()
    const updated = { ...first, currentTemperature: 22.2 }
    source?.emit('room', JSON.stringify(updated))
    await settle()
    expect(card(wrapper, 'Salon').find('[data-test="current"]').text()).toBe('22,2\u00a0°C')
  })

  it('shows what devices without hvac_action are set to, even while paused', async () => {
    mockDb.settings.paused = true
    const { wrapper } = await mountApp('/')
    const source = FakeEventSource.last
    source?.open()
    const cuisine = allStatuses().find((s) => s.name === 'Cuisine')
    if (!cuisine) throw new Error('no cuisine')
    const [device] = cuisine.devices
    source?.emit(
      'room',
      JSON.stringify({
        ...cuisine,
        devices: [
          { ...device, state: 'heat', targetTemperature: 20, running: null },
          {
            ...device,
            entityId: 'climate.cuisine_2',
            state: 'off',
            targetTemperature: 18,
            running: null,
          },
        ],
      }),
    )
    await settle()
    const card_ = card(wrapper, 'Cuisine')
    expect(card_.find('[data-test="activity"]').text()).toBe('Allumé')
    expect(card_.find('[data-test="applied"]').text()).toBe(
      'Réglage actif\u00a0: Chauffage 20,0\u00a0°C · Éteint',
    )
    expect(wrapper.find('[data-test="rooms-summary"]').text()).toMatch(/^4 sur 6/)
  })

  it('merges live alerts and handles an empty or weatherless house', async () => {
    mockDb.settings.weatherEntity = null
    mockDb.settings.vacation = {
      enabled: true,
      startAt: '2026-10-31T09:00:00+01:00',
      returnAt: '2026-11-08T18:00:00+01:00',
      offDuringVacation: true,
    }
    mockDb.alerts = []
    const { wrapper } = await mountApp('/')
    expect(wrapper.text()).toContain('Choisir une entité')
    expect(wrapper.find('[data-test="vacation-state"]').text()).toBe('Activées')
    expect(wrapper.text()).toContain('Retour ')
    expect(wrapper.find('[data-test="alert-count"]').text()).toBe('Aucune')
  })

  it('invites to add a room when there is none', async () => {
    mockDb.rooms = []
    const { wrapper } = await mountApp('/')
    expect(wrapper.text()).toContain('Aucune pièce pour le moment.')
  })
})
