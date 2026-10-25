import { describe, expect, it } from 'vitest'
import { mockDb } from '@/mocks/db'
import { mountApp, norm, setInput, settle } from '@/test/utils'

describe('NotificationsPage', () => {
  it('lists targets (flagging unavailable ones) and types with their example', async () => {
    const { wrapper } = await mountApp('/notifications')
    expect(wrapper.findAll('[data-test="target"]')).toHaveLength(4)
    const old = wrapper.findAll('[data-test="target"]')[3]
    expect(old.text()).toContain('notify.old_phone')
    expect(old.text()).toContain('indisponible')
    expect(wrapper.findAll('[data-test="type"]')).toHaveLength(4)
    expect(norm(wrapper.text())).toContain('Salle de bain : la fenêtre est ouverte depuis 30 min.')
    expect((wrapper.find('[data-test="delay"] input').element as HTMLInputElement).value).toBe('5')
  })

  it('saves toggles, the unresponsive delay in seconds and the persistent flag', async () => {
    const { wrapper } = await mountApp('/notifications')
    await wrapper.find('[data-test="target-notify.mobile_app_ipad"]').setValue(true)
    await wrapper.findAll('input[role="switch"]')[0].setValue(false)
    await setInput(wrapper, '[data-test="delay"] input', '10')
    await wrapper.find('[data-test="persistent"]').setValue(false)
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(
      mockDb.notifications.targets.find((t) => t.service === 'notify.mobile_app_ipad')?.enabled,
    ).toBe(true)
    expect(mockDb.notifications.types).toEqual([
      { type: 'window_open', enabled: false, delaySeconds: null, persistentNotification: false },
      {
        type: 'setpoint_not_applicable',
        enabled: true,
        delaySeconds: null,
        persistentNotification: false,
      },
      {
        type: 'device_unresponsive',
        enabled: true,
        delaySeconds: 600,
        persistentNotification: false,
      },
      {
        type: 'sensor_unavailable',
        enabled: true,
        delaySeconds: null,
        persistentNotification: false,
      },
    ])
    expect(wrapper.find('.toasts').text()).toContain('Notifications enregistrées.')
  })

  it('maps a 422 on the delay and can be cancelled', async () => {
    const { wrapper } = await mountApp('/notifications')
    await setInput(wrapper, '[data-test="delay"] input', '5000')
    await wrapper.find('form').trigger('submit')
    await settle()
    expect(wrapper.text()).toContain('types[2].delaySeconds')
    await wrapper.findAll('.actions button')[0].trigger('click')
    expect((wrapper.find('[data-test="delay"] input').element as HTMLInputElement).value).toBe('5')
  })

  it('sends a test notification, and explains when no target is enabled', async () => {
    const { wrapper } = await mountApp('/notifications')
    await wrapper.find('[data-test="send-test"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('Notification de test envoyée.')

    mockDb.notifications.targets.forEach((t) => (t.enabled = false))
    await wrapper.find('[data-test="send-test"]').trigger('click')
    await settle()
    expect(wrapper.find('.toasts').text()).toContain('Aucune destination de notification')
  })

  it('asks to save before testing', async () => {
    const { wrapper } = await mountApp('/notifications')
    await wrapper.find('[data-test="target-notify.mobile_app_ipad"]').setValue(true)
    expect(wrapper.find('[data-test="send-test"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Enregistrez vos modifications')
  })
})
