/**
 * store.js —— 轻量全局状态
 *
 * 【为什么不用 Pinia】SPEC 未指定状态管理，而这个站点的全局状态只有
 * 「站点信息」和「提示条」两样。引入一个状态库解决两个 reactive 对象，
 * 收益抵不上多一层概念和一份依赖 —— 个人项目里，能少一个抽象就少一个。
 */
import { reactive, readonly } from 'vue'
import { get } from './api.js'

// ── 站点信息 ────────────────────────────────────────────────
const siteState = reactive({
  loaded: false,
  loading: false,
  error: null,
  data: {
    site_name: '博客',
    site_desc: '',
    site_icp: '',
    page_size: 10,
    comments_enabled: false,
    image_thumb_edge: 800,
    image_full_edge: 2560,
    max_upload_bytes: 104857600,
  },
})

export const site = readonly(siteState)

/** 拉取站点信息。已加载过就直接返回，避免每个组件都发一次请求。 */
export async function loadSite(force = false) {
  if (siteState.loaded && !force) return siteState.data
  if (siteState.loading) return siteState.data

  siteState.loading = true
  try {
    const { data } = await get('site.meta')
    Object.assign(siteState.data, data || {})
    siteState.loaded = true
    siteState.error = null

    if (data && data.site_name) {
      document.title = data.site_name
    }
  } catch (err) {
    // 站点信息拿不到不该让整个页面崩掉 —— 用默认值继续渲染
    siteState.error = err.message
  } finally {
    siteState.loading = false
  }

  return siteState.data
}

/** 供「首页一次性返回 site」的场景直接注入，省掉一次往返 */
export function setSiteData(data) {
  if (!data) return
  Object.assign(siteState.data, data)
  siteState.loaded = true
  if (data.site_name) document.title = data.site_name
}

// ── 提示条 ──────────────────────────────────────────────────
let toastId = 0
export const toasts = reactive([])

/**
 * @param {string} message
 * @param {'info'|'success'|'error'} type
 */
export function toast(message, type = 'info', timeout = 3200) {
  const id = ++toastId
  toasts.push({ id, message, type })

  if (timeout > 0) {
    setTimeout(() => dismissToast(id), timeout)
  }
  return id
}

export function dismissToast(id) {
  const index = toasts.findIndex((t) => t.id === id)
  if (index !== -1) toasts.splice(index, 1)
}

/** 统一从 ApiError 取文案（同名的 field 可用于表单定位） */
export function toastError(err) {
  toast(err && err.message ? err.message : '操作失败', 'error')
}
