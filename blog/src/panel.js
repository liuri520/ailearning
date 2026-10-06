/**
 * panel.js —— 后台 SPA 入口
 */
import { createApp } from 'vue'
import PanelApp from './PanelApp.vue'
import router, { markUnauthorized } from './router/panel.js'
import { watchSystemTheme } from './lib/theme.js'
import { setCsrfToken } from './lib/api.js'
import './styles/base.css'
import './styles/panel.css'

watchSystemTheme()

/**
 * 全局兜底：任何一次 API 调用返回 401（会话过期）都立刻切回登录页。
 *
 * 【为什么放在 window 上】api.js 是纯函数模块，不该反向依赖 router。
 * 用自定义事件把「未授权」这个信号广播出来，由入口决定怎么处理，
 * 依赖方向就是单向的。
 */
window.addEventListener('api:unauthorized', () => {
  markUnauthorized()
  setCsrfToken('')
  if (router.currentRoute.value.name !== 'panel-login') {
    router.replace({ name: 'panel-login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})

const app = createApp(PanelApp)
app.use(router)
app.mount('#panel')
