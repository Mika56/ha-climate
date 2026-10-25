import { afterEach, describe, expect, it, vi } from 'vitest'
import { observeHaParent, readHaParent } from './haParent'

interface FakeParentOptions {
  hass?: unknown
  vars?: Record<string, string>
  noElement?: boolean
}

function fakeParent(options: FakeParentOptions = {}) {
  const element = options.noElement ? null : { hass: options.hass }
  const root = document.createElement('html')
  const parent = {
    document: { querySelector: () => element, documentElement: root },
    getComputedStyle: () => ({ getPropertyValue: (name: string) => options.vars?.[name] ?? '' }),
  }
  return { win: { parent: parent as unknown as Window }, root }
}

describe('readHaParent', () => {
  it('reads language, dark mode and theme variables from the parent frame', () => {
    const { win } = fakeParent({
      hass: { language: 'fr', themes: { darkMode: true } },
      vars: { '--primary-color': ' #03a9f4 ', '--divider-color': '#ccc' },
    })
    expect(readHaParent(win)).toEqual({
      language: 'fr',
      darkMode: true,
      themeVars: { '--primary-color': '#03a9f4', '--divider-color': '#ccc' },
    })
  })

  it('tolerates a missing or partial hass object', () => {
    expect(readHaParent(fakeParent({ hass: {} }).win)).toEqual({
      language: null,
      darkMode: null,
      themeVars: {},
    })
    expect(readHaParent(fakeParent({ noElement: true }).win)?.language).toBeNull()
  })

  it('returns null when not framed (parent is the window itself)', () => {
    const self = {} as { parent: Window }
    self.parent = self as unknown as Window
    expect(readHaParent(self)).toBeNull()
    expect(readHaParent()).toBeNull()
  })

  it('returns null when the parent cannot be read (cross-origin)', () => {
    const blocked = {
      get parent(): Window {
        throw new DOMException('Blocked a frame', 'SecurityError')
      },
    }
    expect(readHaParent(blocked)).toBeNull()
    expect(readHaParent({ parent: null as unknown as Window })).toBeNull()
  })
})

describe('observeHaParent', () => {
  afterEach(() => vi.restoreAllMocks())

  it('notifies on html attribute changes until disposed', async () => {
    const { win, root } = fakeParent()
    const onChange = vi.fn()
    const stop = observeHaParent(onChange, win)
    root.setAttribute('style', '--primary-color: red')
    await new Promise((r) => setTimeout(r, 0))
    expect(onChange).toHaveBeenCalledTimes(1)
    stop()
    root.setAttribute('lang', 'fr')
    await new Promise((r) => setTimeout(r, 0))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('is a no-op when not framed or blocked', () => {
    const self = {} as { parent: Window }
    self.parent = self as unknown as Window
    expect(observeHaParent(() => {}, self)()).toBeUndefined()
    const blocked = {
      get parent(): Window {
        throw new DOMException('Blocked', 'SecurityError')
      },
    }
    expect(observeHaParent(() => {}, blocked)()).toBeUndefined()
  })
})
