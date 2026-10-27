import { describe, expect, it } from 'vitest'
import type { DiscoveredEntity } from '@/api/types'
import { entityCategory, entityIcon, isActiveState, isUnavailable, matchesQuery } from './entities'
import { mdiCircleOutline, mdiFlash, mdiLightbulb, mdiMotionSensor, mdiPowerPlug } from './icons'
import { newSignal, normalizeSignal } from './rooms'

const e = (entityId: string, deviceClass: string | null = null): DiscoveredEntity => ({
  entityId,
  name: entityId,
  areaId: null,
  state: null,
  deviceClass,
})

describe('entity helpers', () => {
  it('categorises by domain and device class', () => {
    const cases: [DiscoveredEntity, string][] = [
      [e('binary_sensor.a', 'motion'), 'presence'],
      [e('binary_sensor.a'), 'presence'],
      [e('binary_sensor.a', 'window'), 'opening'],
      [e('input_boolean.a'), 'presence'],
      [e('light.a'), 'light'],
      [e('switch.a'), 'switch'],
      [e('fan.a'), 'switch'],
      [e('media_player.a'), 'media'],
      [e('sensor.a', 'power'), 'power'],
      [e('sensor.a', 'temperature'), 'temperature'],
      [e('sensor.a'), 'roomSensor'],
      [e('climate.a'), 'climate'],
      [e('person.a'), 'other'],
    ]
    for (const [entity, category] of cases) expect(entityCategory(entity)).toBe(category)
  })

  it('picks an icon by device class, then domain', () => {
    expect(entityIcon(e('binary_sensor.a', 'motion'))).toBe(mdiMotionSensor)
    expect(entityIcon(e('sensor.a', 'power'))).toBe(mdiFlash)
    expect(entityIcon(e('light.a'))).toBe(mdiLightbulb)
    expect(entityIcon(e('switch.a', 'outlet'))).toBe(mdiPowerPlug)
    expect(entityIcon(e('switch.a', 'power'))).toBe(mdiPowerPlug)
    expect(entityIcon(e('weird.a'))).toBe(mdiCircleOutline)
  })

  it('matches every word, ignoring case and accents', () => {
    expect(matchesQuery('Salle de Bàin sensor.sdb', 'salle BAIN')).toBe(true)
    expect(matchesQuery('Salon', 'salle')).toBe(false)
    expect(matchesQuery('anything', '  ')).toBe(true)
  })

  it('knows unavailable and active states', () => {
    expect([null, 'unavailable', 'unknown', 'on'].map(isUnavailable)).toEqual([
      true,
      true,
      true,
      false,
    ])
    expect(['on', 'playing', 'paused', 'off', 'idle', null].map(isActiveState)).toEqual([
      true,
      true,
      true,
      false,
      false,
      false,
    ])
  })
})

describe('presence signal model', () => {
  it('gives each kind its own parameter and clears the other', () => {
    const entity = { entityId: 'sensor.x', state: 'Bureau' }
    expect(newSignal(entity, 'active')).toEqual({
      entityId: 'sensor.x',
      kind: 'active',
      threshold: null,
      value: null,
    })
    expect(newSignal(entity, 'above')).toMatchObject({ threshold: 20, value: null })
    expect(newSignal(entity, 'equals')).toMatchObject({ threshold: null, value: 'Bureau' })
  })

  it('keeps the previous parameter and ignores unusable states', () => {
    const previous = { entityId: 'sensor.x', kind: 'above', threshold: 50, value: 'Salon' } as const
    expect(newSignal({ entityId: 'sensor.x', state: null }, 'above', previous).threshold).toBe(50)
    expect(newSignal({ entityId: 'sensor.x', state: 'unknown' }, 'equals').value).toBe('')
    expect(newSignal({ entityId: 'sensor.x', state: null }, 'equals', previous).value).toBe('Salon')
  })

  it('normalises a signal before sending it', () => {
    expect(normalizeSignal({ entityId: 'a.b', kind: 'active', threshold: 5, value: 'x' })).toEqual({
      entityId: 'a.b',
      kind: 'active',
      threshold: null,
      value: null,
    })
    expect(normalizeSignal({ entityId: 'a.b', kind: 'above', threshold: 5, value: 'x' })).toEqual({
      entityId: 'a.b',
      kind: 'above',
      threshold: 5,
      value: null,
    })
    expect(normalizeSignal({ entityId: 'a.b', kind: 'equals', threshold: 5, value: 'x' })).toEqual({
      entityId: 'a.b',
      kind: 'equals',
      threshold: null,
      value: 'x',
    })
    expect(normalizeSignal({ entityId: 'a.b', kind: 'above' }).threshold).toBeNull()
  })
})
