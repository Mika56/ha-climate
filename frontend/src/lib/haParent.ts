/**
 * Defensive access to the Home Assistant frontend that hosts our ingress iframe.
 * The parent is same-origin behind ingress, so `window.parent.document` is readable there;
 * in standalone dev (no iframe) or if the browser denies access every function degrades to "unknown".
 */

/** Theme variables copied from Home Assistant onto our root element. */
export const THEME_VARS = [
  '--primary-color',
  '--card-background-color',
  '--primary-background-color',
  '--primary-text-color',
  '--secondary-text-color',
  '--divider-color',
] as const

export interface HaSnapshot {
  /** Language of the logged-in user's profile (`hass.language`). */
  language: string | null
  /** Effective dark mode (`hass.themes.darkMode`). */
  darkMode: boolean | null
  /** Theme CSS variables that are set in the parent (name -> value). */
  themeVars: Record<string, string>
}

interface HassLike {
  language?: unknown
  themes?: { darkMode?: unknown }
}

type FrameHost = { parent: Window }

export function readHaParent(win: FrameHost = window): HaSnapshot | null {
  try {
    const parent = win.parent
    if (!parent || (parent as unknown) === win) return null
    const doc = parent.document
    const host = doc.querySelector('home-assistant') as (Element & { hass?: HassLike }) | null
    const hass = host?.hass
    const computed = parent.getComputedStyle(doc.documentElement)
    const themeVars: Record<string, string> = {}
    for (const name of THEME_VARS) {
      const value = computed.getPropertyValue(name).trim()
      if (value !== '') themeVars[name] = value
    }
    return {
      language: typeof hass?.language === 'string' ? hass.language : null,
      darkMode: typeof hass?.themes?.darkMode === 'boolean' ? hass.themes.darkMode : null,
      themeVars,
    }
  } catch {
    return null
  }
}

/** Calls `onChange` when Home Assistant's `<html>` style, class or lang changes. Returns a disposer. */
export function observeHaParent(onChange: () => void, win: FrameHost = window): () => void {
  try {
    const parent = win.parent
    if (!parent || (parent as unknown) === win) return () => {}
    const observer = new MutationObserver(onChange)
    observer.observe(parent.document.documentElement, {
      attributes: true,
      attributeFilter: ['style', 'class', 'lang'],
    })
    return () => observer.disconnect()
  } catch {
    return () => {}
  }
}
