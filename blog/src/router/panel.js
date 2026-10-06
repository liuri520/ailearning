/**
 * router/panel.js —— 后台路由（Hash 模式）
 *
 * 同样用 Hash：后台入口本身已经是随机化的 panel-xxxx.php（SPEC §9.1），
 * 再叠一层 rewrite 依赖只会让「换个主机就 404」的风险变高。
 *
 * 【统一守卫】所有非登录页都要求已登录。守卫只做「有没有会话」的判断，
 * 真正的权限校验在服务端（Auth::requireLogin），前端守卫的作用是
 * 让未登录用户看到登录页而不是一堆报错弹窗 —— 它是体验措施，不是安全措施。
 */
import { createRouter, createWebHashHistory } from 'vue-router'
import { get, setCsrfToken, ApiError } from '../lib/api.js'

const routes = [
  {
    path: '/login',
    name: 'panel-login',
    component: () => import('../panel/Login.vue'),
    meta: { public: true, title: '登录' },
  },
  {
    path: '/',
    name: 'panel-dashboard',
    component: () => import('../panel/Dashboard.vue'),
    meta: { title: '概览' },
  },
  {
    path: '/contents',
    name: 'panel-contents',
    component: () => import('../panel/ContentList.vue'),
    meta: { title: '内容管理' },
  },
  {
    path: '/contents/new',
    name: 'panel-content-new',
    component: () => import('../panel/ContentEdit.vue'),
    meta: { title: '新建内容' },
  },
  {
    path: '/contents/:id/edit',
    name: 'panel-content-edit',
    component: () => import('../panel/ContentEdit.vue'),
    props: true,
    meta: { title: '编辑内容' },
  },
  {
    path: '/tags',
    name: 'panel-tags',
    component: () => import('../panel/Tags.vue'),
    meta: { title: '标签' },
  },
  {
    path: '/media',
    name: 'panel-media',
    component: () => import('../panel/Media.vue'),
    meta: { title: '媒体库' },
  },
  {
    path: '/home',
    name: 'panel-home',
    component: () => import('../panel/HomeLayout.vue'),
    meta: { title: '首页布局' },
  },
  {
    path: '/settings',
    name: 'panel-settings',
    component: () => import('../panel/Settings.vue'),
    meta: { title: '设置' },
  },
  {
    path: '/tasks',
    name: 'panel-tasks',
    component: () => import('../panel/Tasks.vue'),
    meta: { title: '任务' },
  },
  {
    path: '/backup',
    name: 'panel-backup',
    component: () => import('../panel/Backup.vue'),
    meta: { title: '备份' },
  },
  {
    path: '/logs',
    name: 'panel-logs',
    component: () => import('../panel/Logs.vue'),
    meta: { title: '日志' },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

/**
 * 会话状态缓存。
 * 【为什么缓存】每次导航都打一次 auth.me，会让「后台点一下卡一下」；
 * 但完全缓存又会在服务端会话过期后继续放行。折中是：
 * 登录态在内存里记着，一旦任何请求返回 401 就立即清空（见 markUnauthorized）。
 */
let sessionChecked = false
let sessionValid = false

export function markUnauthorized() {
  sessionChecked = true
  sessionValid = false
  setCsrfToken('')
}

export async function ensureSession(force = false) {
  if (sessionChecked && sessionValid && !force) return true
  try {
    const { data } = await get('auth.me')
    sessionValid = !!data.logged_in
    sessionChecked = true
    if (sessionValid && data.csrf_token) {
      setCsrfToken(data.csrf_token)
    }
    return sessionValid
  } catch (err) {
    // 网络问题不等于未登录，但此时也没法继续，按未登录处理并让守卫放行到登录页
    if (!(err instanceof ApiError)) throw err
    sessionChecked = true
    sessionValid = false
    return false
  }
}

router.beforeEach(async (to) => {
  if (to.meta.public) return true

  const ok = await ensureSession()
  if (!ok) {
    // 记住原目标，登录后跳回来（刷新页面时不必重新点一遍）
    return { name: 'panel-login', query: { redirect: to.fullPath } }
  }
  return true
})

router.afterEach((to) => {
  const base = '管理后台'
  document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
})

export default router
