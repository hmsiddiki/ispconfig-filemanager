// Light / dark / auto theme, and "am I embedded in another page" detection.
//
// Who decides the theme, strongest first:
//   1. the embedding page: ?theme=light|dark in the URL, or window.postMessage({ type: 'filemanager-theme', theme: 'dark' }, '*')
//      (then the in-app toggle is hidden, so the two cannot disagree)
//   2. the user's own choice from the toggle, remembered in this browser
//   3. 'auto': follow the operating system
import Vue from 'vue'

const KEY = 'fg-theme'
const ORDER = ['auto', 'light', 'dark']

export const theme = Vue.observable({ mode: 'auto', locked: false, embedded: false })

const valid = mode => ORDER.indexOf(mode) !== -1

function apply() {
  const root = document.documentElement
  if (theme.mode === 'auto') root.removeAttribute('data-theme')
  else root.setAttribute('data-theme', theme.mode)
  root.classList.toggle('is-embedded', theme.embedded)
}

export function initTheme() {
  let params = new URLSearchParams('')
  try {
    params = new URLSearchParams(window.location.search)
  } catch (e) {
    /* very old browser: no URL parameters */
  }

  let saved = null
  try {
    saved = window.localStorage.getItem(KEY)
  } catch (e) {
    /* storage unavailable: the choice just is not remembered */
  }

  let framed = false
  try {
    framed = window.self !== window.top
  } catch (e) {
    framed = true // cross-origin parent: access throws, which means we are framed
  }
  theme.embedded = framed || params.get('embed') === '1'

  if (valid(params.get('theme'))) {
    theme.mode = params.get('theme')
    theme.locked = true
  } else if (valid(saved)) {
    theme.mode = saved
  }
  apply()

  // lets the parent page (e.g. the panel's own light/dark switch) change the theme live
  window.addEventListener('message', event => {
    const data = event.data
    if (data && data.type === 'filemanager-theme' && valid(data.theme)) {
      theme.mode = data.theme
      theme.locked = true
      apply()
    }
  })
}

export function cycleTheme() {
  if (theme.locked) return
  theme.mode = ORDER[(ORDER.indexOf(theme.mode) + 1) % ORDER.length]
  try {
    window.localStorage.setItem(KEY, theme.mode)
  } catch (e) {
    /* not remembered */
  }
  apply()
}

export default theme
