/**
 * main.js —— 前台 SPA 入口
 */
import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'
import { watchSystemTheme } from './lib/theme.js'
import { loadSite } from './lib/store.js'
import './styles/base.css'
import './styles/public.css'

watchSystemTheme()

const app = createApp(App)
app.use(router)

// 站点信息先于首屏拉取：标题、页脚版权、页大小都依赖它。
// 失败也不阻塞挂载（loadSite 内部已吞掉异常）。
loadSite()

app.mount('#app')
