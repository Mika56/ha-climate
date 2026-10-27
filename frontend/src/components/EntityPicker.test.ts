import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { createAppI18n } from '@/i18n'
import type { PickerEntity } from '@/lib/entities'
import { norm } from '@/test/utils'
import EntityPicker from './EntityPicker.vue'

const entity = (
  entityId: string,
  name: string,
  areaId: string | null,
  state: string | null,
  extra: Partial<PickerEntity> = {},
): PickerEntity => ({ entityId, name, areaId, state, unit: null, deviceClass: null, ...extra })

const entities: PickerEntity[] = [
  entity('binary_sensor.salon_motion', 'Mouvement salon', 'salon', 'on', { deviceClass: 'motion' }),
  entity('light.salon_plafonnier', 'Plafonnier', 'salon', 'off'),
  entity('sensor.pc_power', 'Puissance PC', 'bureau', '148.2', { unit: 'W', deviceClass: 'power' }),
  entity('media_player.tv', 'Télévision', 'bureau', 'unavailable'),
  entity('sensor.bermuda_alex', 'Bermuda Alex', null, 'Bureau'),
]
const areas = [
  { id: 'salon', name: 'Salon' },
  { id: 'bureau', name: 'Bureau' },
]

function mountPicker(props: Record<string, unknown> = {}): VueWrapper {
  return mount(EntityPicker, {
    props: { entities, areas, label: 'Add a signal', variant: 'add', ...props },
    global: { plugins: [createAppI18n('en')] },
    attachTo: document.body,
  })
}

const ids = (wrapper: VueWrapper): (string | undefined)[] =>
  wrapper.findAll('[data-test="picker-option"]').map((o) => o.attributes('data-entity-id'))

const open = async (wrapper: VueWrapper): Promise<void> => {
  await wrapper.find('[data-test="picker-trigger"]').trigger('click')
  await nextTick()
}

const type = (wrapper: VueWrapper, text: string): Promise<void> =>
  wrapper.find('[data-test="picker-search"]').setValue(text)

describe('EntityPicker', () => {
  it('opens an accessible dialog with a focused search field', async () => {
    const wrapper = mountPicker()
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(false)
    await open(wrapper)
    const dialog = wrapper.find('[data-test="picker-dialog"]')
    expect(dialog.attributes('role')).toBe('dialog')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.text()).toContain('Add a signal')
    const search = wrapper.find('[data-test="picker-search"]')
    expect(search.attributes('role')).toBe('combobox')
    expect(document.activeElement).toBe(search.element)
    expect(wrapper.find('[role="listbox"]').exists()).toBe(true)
    expect(wrapper.find('[role="status"]').text()).toBe('5 results')
  })

  it('shows friendly name, entity id, area, unit and state, grouped by category', async () => {
    const wrapper = mountPicker()
    await open(wrapper)
    const power = wrapper.find('[data-entity-id="sensor.pc_power"]')
    expect(power.text()).toContain('Puissance PC')
    expect(power.text()).toContain('sensor.pc_power')
    expect(power.text()).toContain('Bureau')
    expect(norm(power.text())).toContain('148.2 W')
    expect(wrapper.find('[data-entity-id="media_player.tv"]').text()).toContain('Unavailable')
    expect(wrapper.find('[data-entity-id="light.salon_plafonnier"]').text()).toContain('Off')
    expect(wrapper.findAll('[data-test="picker-group"]').map((g) => g.text())).toEqual([
      'Presence sensors',
      'Lights',
      'Media players',
      'Power sensors',
      'Room sensors',
    ])
    expect(wrapper.findAll('svg').length).toBeGreaterThan(5)
  })

  it('hides the group titles when there is a single category', async () => {
    const wrapper = mountPicker({ entities: entities.slice(1, 2) })
    await open(wrapper)
    expect(wrapper.find('[data-test="picker-group"]').exists()).toBe(false)
  })

  it('filters on name, entity id and area, ignoring case and accents', async () => {
    const wrapper = mountPicker()
    await open(wrapper)
    await type(wrapper, 'television')
    expect(ids(wrapper)).toEqual(['media_player.tv'])
    await type(wrapper, 'PC_POWER')
    expect(ids(wrapper)).toEqual(['sensor.pc_power'])
    await type(wrapper, 'bureau')
    expect(ids(wrapper)).toEqual(['media_player.tv', 'sensor.pc_power'])
    await type(wrapper, 'salon mouvement')
    expect(ids(wrapper)).toEqual(['binary_sensor.salon_motion'])
    expect(wrapper.find('[role="status"]').text()).toBe('1 result')
  })

  it('starts on the room area and reveals the others on demand', async () => {
    const wrapper = mountPicker({ areaId: 'salon' })
    await open(wrapper)
    expect(ids(wrapper)).toEqual(['binary_sensor.salon_motion', 'light.salon_plafonnier'])
    const toggle = wrapper.find('[data-test="picker-show-all"]')
    expect(wrapper.text()).toContain('Also show entities from other areas (3)')
    await toggle.setValue(true)
    expect(ids(wrapper)).toHaveLength(5)
  })

  it('shows matches of other areas under their own header when the area has none', async () => {
    const wrapper = mountPicker({ areaId: 'salon' })
    await open(wrapper)
    await type(wrapper, 'bermuda')
    expect(wrapper.find('[data-test="picker-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="picker-others"]').text()).toBe('Other areas')
    expect(ids(wrapper)).toEqual(['sensor.bermuda_alex'])
    expect(wrapper.text()).toContain('Also show entities from other areas (1)')
    // The count follows the search; the empty state is at the top when nothing matches anywhere.
    await type(wrapper, 'zzz')
    expect(wrapper.find('[data-test="picker-empty"]').text()).toContain('No entity matches.')
    expect(wrapper.find('[data-test="picker-others"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="picker-show-all"]').exists()).toBe(false)
    await type(wrapper, 'plafonnier')
    expect(wrapper.find('[data-test="picker-others"]').exists()).toBe(false)
  })

  it('lists everything when the area holds nothing', async () => {
    const wrapper = mountPicker({ areaId: 'sdb' })
    await open(wrapper)
    expect(ids(wrapper)).toHaveLength(5)
  })

  it('moves with the arrow keys and picks with Enter without submitting the form', async () => {
    const parent = mount(
      {
        components: { EntityPicker },
        data: () => ({ entities, picked: '', submitted: false }),
        template:
          '<form @submit.prevent="submitted = true"><EntityPicker :entities="entities" label="Pick" variant="add" @select="picked = $event.entityId" /></form>',
      },
      { global: { plugins: [createAppI18n('en')] }, attachTo: document.body },
    )
    await parent.find('[data-test="picker-trigger"]').trigger('click')
    await nextTick()
    const search = parent.find('[data-test="picker-search"]')
    const active = () => parent.find('.option.active').attributes('data-entity-id')
    expect(active()).toBe('binary_sensor.salon_motion')
    expect(search.attributes('aria-activedescendant')).toBe(
      parent.find('.option.active').attributes('id'),
    )
    await search.trigger('keydown', { key: 'ArrowDown' })
    expect(active()).toBe('light.salon_plafonnier')
    await search.trigger('keydown', { key: 'ArrowUp' })
    await search.trigger('keydown', { key: 'ArrowUp' })
    expect(active()).toBe('binary_sensor.salon_motion')
    for (let i = 0; i < 9; i++) await search.trigger('keydown', { key: 'ArrowDown' })
    expect(active()).toBe('sensor.bermuda_alex')
    await search.trigger('keydown', { key: 'Enter' })
    expect(parent.vm.picked).toBe('sensor.bermuda_alex')
    expect(parent.vm.submitted).toBe(false)
    expect(parent.find('[data-test="picker-dialog"]').exists()).toBe(false)
  })

  it('ignores Enter when nothing matches', async () => {
    const wrapper = mountPicker()
    await open(wrapper)
    await type(wrapper, 'zzz')
    await wrapper.find('[data-test="picker-search"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('select')).toBeUndefined()
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(true)
  })

  it('emits the entity on click and closes', async () => {
    const wrapper = mountPicker()
    await open(wrapper)
    await wrapper.find('[data-entity-id="light.salon_plafonnier"]').trigger('click')
    expect(wrapper.emitted('select')?.[0]).toEqual([entities[1]])
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['light.salon_plafonnier'])
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(false)
  })

  it('closes with Escape, the close button or the backdrop and gives the focus back', async () => {
    const wrapper = mountPicker()
    const trigger = wrapper.find('[data-test="picker-trigger"]').element
    await open(wrapper)
    await wrapper.find('[data-test="picker-search"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(false)
    await nextTick()
    expect(document.activeElement).toBe(trigger)

    await open(wrapper)
    await wrapper.find('[data-test="picker-close"]').trigger('click')
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(false)

    await open(wrapper)
    await wrapper.find('[data-test="picker-backdrop"]').trigger('click')
    expect(wrapper.find('[data-test="picker-dialog"]').exists()).toBe(false)
    expect(wrapper.emitted('select')).toBeUndefined()
  })

  it('keeps the focus inside the dialog with Tab', async () => {
    const wrapper = mountPicker({ areaId: 'salon' })
    await open(wrapper)
    const dialog = wrapper.find('[data-test="picker-dialog"]')
    const close = wrapper.find<HTMLButtonElement>('[data-test="picker-close"]')
    const toggle = wrapper.find<HTMLInputElement>('[data-test="picker-show-all"]')
    toggle.element.focus()
    await dialog.trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(close.element)
    await dialog.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(toggle.element)
    close.element.focus()
    await dialog.trigger('keydown', { key: 'Tab', shiftKey: true })
    await dialog.trigger('keydown', { key: 'Tab' })
    // Tab in the middle of the dialog is left to the browser.
    wrapper.find<HTMLInputElement>('[data-test="picker-search"]').element.focus()
    await dialog.trigger('keydown', { key: 'Tab' })
    expect(dialog.exists()).toBe(true)
  })

  it('locks the page scroll while open', async () => {
    const wrapper = mountPicker()
    await open(wrapper)
    expect(document.body.style.overflow).toBe('hidden')
    await wrapper.find('[data-test="picker-close"]').trigger('click')
    await nextTick()
    expect(document.body.style.overflow).toBe('')
    await open(wrapper)
    wrapper.unmount()
    expect(document.body.style.overflow).toBe('')
  })

  it('leaves out the excluded entities', async () => {
    const wrapper = mountPicker({ exclude: ['sensor.pc_power', 'media_player.tv'] })
    await open(wrapper)
    expect(ids(wrapper)).toEqual([
      'binary_sensor.salon_motion',
      'light.salon_plafonnier',
      'sensor.bermuda_alex',
    ])
  })

  describe('field variant', () => {
    it('shows the chosen entity, marks it in the list and replaces it', async () => {
      const wrapper = mountPicker({
        variant: 'field',
        modelValue: 'sensor.pc_power',
        label: 'Temperature',
      })
      const trigger = wrapper.find('[data-test="picker-trigger"]')
      expect(trigger.text()).toContain('Puissance PC')
      expect(trigger.attributes('aria-label')).toBe('Temperature')
      await open(wrapper)
      expect(wrapper.find('[data-entity-id="sensor.pc_power"]').attributes('aria-selected')).toBe(
        'true',
      )
      await wrapper.find('[data-entity-id="sensor.bermuda_alex"]').trigger('click')
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['sensor.bermuda_alex'])
    })

    it('shows a placeholder when empty and flags an unknown entity', async () => {
      const empty = mountPicker({ variant: 'field', modelValue: null, invalid: true })
      expect(empty.find('[data-test="picker-trigger"]').text()).toContain('Choose')
      expect(empty.find('[data-test="picker-trigger"]').attributes('aria-invalid')).toBe('true')
      expect(empty.find('[data-test="picker-clear"]').exists()).toBe(false)

      const lost = mountPicker({ variant: 'field', modelValue: 'sensor.gone' })
      expect(lost.find('[data-test="picker-trigger"]').text()).toContain('sensor.gone')
      expect(lost.find('[data-test="picker-trigger"]').text()).toContain('not found')
    })

    it('can be cleared when clearable', async () => {
      const wrapper = mountPicker({
        variant: 'field',
        modelValue: 'sensor.pc_power',
        clearable: true,
      })
      await wrapper.find('[data-test="picker-clear"]').trigger('click')
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
    })
  })
})
