/**
 * theme.js —— 明暗主题（跟随系统 + 手动覆盖）
 *
 * 首屏的实际切换在 index.html 的内联脚本里完成（避免白屏闪烁），
 * 本模块只负责后续的用户切换与持久化。
 */
import { ref } from 'vue'

const STORAGE_KEY = 'theme'

function initial() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'light' || saved === 'dark') return saved
  } catch { /* 隐私模式下 localStorage 可能不可用 */ }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export const theme = ref(initial())

export function applyTheme(value) {
  theme.value = value
  document.documentElement.setAttribute('data-theme', value)
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch { /* 存不下就算了，本次会话仍然有效 */ }
}

export function toggleTheme() {
  applyTheme(theme.value === 'dark' ? 'light' : 'dark')
}

/**
 * 监听系统主题变化。
 * 只在用户**没有**手动选择过时跟随 —— 手动选过就说明用户意愿优先于系统设置。
 */
export function watchSystemTheme() {
  const mq = window.matchMedia('(prefers-color-scheme: dark)')
  mq.addEventListener('change', (e) => {
    let saved = null
    try { saved = localStorage.getItem(STORAGE_KEY) } catch { /* 忽略 */ }
    if (saved) return
    applyTheme(e.matches ? 'dark' : 'light')
  })
}
