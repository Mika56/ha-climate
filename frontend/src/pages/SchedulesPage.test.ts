import { describe, expect, it } from 'vitest'
import { ID } from '@/mocks/data'
import { mockDb } from '@/mocks/db'
import { mountApp, norm, setChange, setInput, settle } from '@/test/utils'

type Wrapper = Awaited<ReturnType<typeof mountApp>>['wrapper']

const slots = (wrapper: Wrapper) => wrapper.findAll('[data-test="slot"]')
const mondaySlots = (wrapper: Wrapper) =>
  slots(wrapper).filter((s) => s.attributes('aria-label')?.startsWith('Lundi'))
const savedSchedule = () => mockDb.schedules.find((s) => s.id === ID.semaine)

describe('SchedulesPage', () => {
  it('redirects to the first schedule and draws the week', async () => {
    const { wrapper, router } = await mountApp('/schedules')
    expect(router.currentRoute.value.path).toBe(`/schedules/${ID.semaine}`)
    expect(mondaySlots(wrapper)).toHaveLength(5)
    expect(slots(wrapper)).toHaveLength(5 * 5 + 5 * 2)
    expect(wrapper.find('[data-test="used-by"]').text()).toContain('Salon')
    const first = mondaySlots(wrapper)[0]
    expect(first.attributes('aria-label')).toBe('Lundi, 00:00 à 06:30, Nuit')
    expect(first.attributes('aria-pressed')).toBe('true')
    const anchored = mondaySlots(wrapper)[4]
    expect(anchored.attributes('aria-label')).toContain('début ancré sur une entité')
    expect(norm(wrapper.find('[data-test="slot-title"]').text())).toBe(
      'Créneau sélectionné · Lundi 00:00 → 06:30',
    )
  })

  it('selects a slot with the keyboard-accessible buttons and edits its preset', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[1].trigger('click')
    expect(mondaySlots(wrapper)[1].attributes('aria-pressed')).toBe('true')
    await setInput(wrapper, '[data-test="slot-preset"]', 'eco')
    expect(mondaySlots(wrapper)[1].attributes('aria-label')).toContain('Éco')
    await wrapper.find('[data-test="save-schedule"]').trigger('click')
    await settle()
    const saved = savedSchedule()?.slots.filter((s) => s.weekday === 1)
    expect(saved?.[1].preset).toBe('eco')
    expect(wrapper.find('.toasts').text()).toContain('Planning enregistré.')
  })

  it('moves a fixed slot by editing its time', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[1].trigger('click')
    await setChange(wrapper, '[data-test="slot-time"]', '07:15')
    expect(mondaySlots(wrapper)[0].attributes('aria-label')).toContain('00:00 à 07:15')
    // An invalid time is ignored.
    await setChange(wrapper, '[data-test="slot-time"]', '')
    expect(mondaySlots(wrapper)[0].attributes('aria-label')).toContain('00:00 à 07:15')
  })

  it('turns a fixed slot into an anchored one with offset and fallback', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[3].trigger('click')
    await wrapper.find('[data-test="start-anchored"]').setValue(true)
    expect(mondaySlots(wrapper)[3].attributes('aria-label')).toContain('début ancré')
    await setInput(wrapper, '[data-test="slot-entity"]', 'input_datetime.reveil')
    await setInput(wrapper, '[data-test="slot-offset"] input', '-30')
    await setChange(wrapper, '[data-test="slot-fallback"]', '18:00')
    await wrapper.find('[data-test="save-schedule"]').trigger('click')
    await settle()
    const slot = savedSchedule()?.slots.filter((s) => s.weekday === 1)[3]
    expect(slot?.start).toEqual({
      type: 'anchored',
      entityId: 'input_datetime.reveil',
      offsetMinutes: -30,
      fallbackMinuteOfDay: 1080,
    })
    // Back to a fixed time keeps the drawn minute.
    await wrapper.find('[data-test="start-fixed"]').setValue(true)
    expect((wrapper.find('[data-test="slot-time"]').element as HTMLInputElement).value).toBe(
      '18:00',
    )
  })

  it('edits an existing anchored slot', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[4].trigger('click')
    expect((wrapper.find('[data-test="slot-entity"]').element as HTMLSelectElement).value).toBe(
      'input_datetime.heure_coucher',
    )
    expect(
      (wrapper.find('[data-test="slot-offset"] input').element as HTMLInputElement).value,
    ).toBe('-15')
  })

  it('splits, adds and deletes slots', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[0].trigger('click')
    await wrapper.find('[data-test="split-slot"]').trigger('click')
    expect(mondaySlots(wrapper)).toHaveLength(6)
    await wrapper.find('[data-test="add-slot"]').trigger('click')
    expect(mondaySlots(wrapper)).toHaveLength(7)
    await wrapper.find('[data-test="delete-slot"]').trigger('click')
    expect(mondaySlots(wrapper)).toHaveLength(6)
  })

  it('copies a day to other days', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    // Saturday has two slots fewer than Monday; copy Monday over Saturday and Sunday.
    await mondaySlots(wrapper)[0].trigger('click')
    await wrapper.find('[data-test="copy-day"]').trigger('click')
    await wrapper.find('[data-test="copy-to-6"]').setValue(true)
    await wrapper.find('[data-test="copy-to-7"]').setValue(true)
    await wrapper.find('[data-test="copy-apply"]').trigger('click')
    expect(wrapper.find('[data-test="copy-panel"]').exists()).toBe(false)
    const labels = slots(wrapper).map((s) => s.attributes('aria-label') ?? '')
    expect(labels.filter((l) => l.startsWith('Samedi'))).toHaveLength(5)
    expect(labels.filter((l) => l.startsWith('Dimanche'))).toHaveLength(5)
    await wrapper.find('[data-test="save-schedule"]').trigger('click')
    await settle()
    const saturday = savedSchedule()?.slots.filter((s) => s.weekday === 6) ?? []
    expect(saturday).toHaveLength(5)
    expect(saturday[4].start.type).toBe('anchored')
  })

  it('blocks saving two slots starting at the same time', async () => {
    const { wrapper } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[1].trigger('click')
    await setChange(wrapper, '[data-test="slot-time"]', '00:00')
    expect(wrapper.find('[data-test="duplicate-starts"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="save-schedule"]').attributes('disabled')).toBeDefined()
  })

  it('creates a new schedule and names it', async () => {
    const { wrapper, router } = await mountApp('/schedules/new')
    expect(slots(wrapper)).toHaveLength(7)
    // Name required: the 422 is mapped on the name field.
    await setInput(wrapper, '[data-test="slot-preset"]', 'eco')
    await wrapper.find('[data-test="save-schedule"]').trigger('click')
    await settle()
    expect(wrapper.text()).toContain('Le nom est obligatoire.')
    expect(mockDb.schedules.some((s) => s.name === 'Vacances')).toBe(false)
    await wrapper.find('[data-test="schedule-name"]').setValue('Vacances')
    await wrapper.find('[data-test="save-schedule"]').trigger('click')
    await settle()
    const created = mockDb.schedules.find((s) => s.name === 'Vacances')
    expect(created).toBeDefined()
    expect(router.currentRoute.value.path).toBe(`/schedules/${created?.id}`)
  })

  it('duplicates a schedule', async () => {
    const { wrapper, router } = await mountApp(`/schedules/${ID.semaine}`)
    await wrapper.find('[data-test="duplicate"]').trigger('click')
    expect((wrapper.find('[data-test="duplicate-name"]').element as HTMLInputElement).value).toBe(
      'Semaine standard (copie)',
    )
    await wrapper.find('[data-test="duplicate-form"]').trigger('submit')
    await settle()
    const copy = mockDb.schedules.find((s) => s.name === 'Semaine standard (copie)')
    expect(copy?.slots).toHaveLength(savedSchedule()?.slots.length ?? -1)
    expect(router.currentRoute.value.path).toBe(`/schedules/${copy?.id}`)
  })

  it('deletes a schedule after confirming the rooms it affects', async () => {
    const { wrapper, router } = await mountApp(`/schedules/${ID.semaine}`)
    await wrapper.find('[data-test="delete-schedule"]').trigger('click')
    expect(norm(document.body.textContent)).toContain(
      'Utilisé par Salon, Cuisine, Chambre parentale, Chambre enfant',
    )
    await wrapper.find('[data-test="confirm-accept"]').trigger('click')
    await settle()
    expect(mockDb.schedules.some((s) => s.id === ID.semaine)).toBe(false)
    expect(mockDb.rooms.find((r) => r.id === ID.salon)?.heatingScheduleId).toBeNull()
    expect(router.currentRoute.value.path).toBe(`/schedules/${ID.teletravail}`)
  })

  it('switches schedule with the selector and guards unsaved changes', async () => {
    const { wrapper, router } = await mountApp(`/schedules/${ID.semaine}`)
    await mondaySlots(wrapper)[1].trigger('click')
    await setInput(wrapper, '[data-test="slot-preset"]', 'eco')
    const select = wrapper.find('[data-test="schedule-select"]')
    ;(select.element as HTMLSelectElement).value = ID.teletravail
    const change = select.trigger('change')
    await settle()
    expect(document.body.textContent).toContain('Abandonner vos modifications')
    await wrapper.findAll('.dialog button')[0].trigger('click')
    await change
    await settle()
    expect(router.currentRoute.value.path).toBe(`/schedules/${ID.semaine}`)
    expect((select.element as HTMLSelectElement).value).toBe(ID.semaine)
  })

  it('offers to create the first schedule when there is none, instead of loading forever', async () => {
    mockDb.schedules = []
    const { wrapper, router } = await mountApp('/schedules')
    expect(router.currentRoute.value.path).toBe('/schedules')
    const empty = wrapper.find('[data-test="schedules-empty"]')
    expect(empty.exists()).toBe(true)
    expect(empty.text()).toContain('Aucun planning')
    expect(wrapper.text()).not.toContain('Chargement')
    expect(empty.find('[data-test="create-first-schedule"]').attributes('href')).toContain(
      '/schedules/new',
    )
  })

  it('tells when the requested schedule does not exist', async () => {
    const { wrapper } = await mountApp('/schedules/00000000-0000-4000-8000-000000000000')
    expect(wrapper.find('[data-test="schedules-empty"]').text()).toContain('n’existe pas')
  })

  it('shows a schedule that no room uses', async () => {
    mockDb.rooms.forEach((r) => (r.coolingScheduleId = null))
    const { wrapper } = await mountApp(`/schedules/${ID.eteChambres}`)
    expect(wrapper.find('[data-test="used-by"]').text()).toContain('aucune pièce')
  })
})
