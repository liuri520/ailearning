/**
 * api.js —— 与 api.php 的唯一通道
 *
 * 约定（SPEC §6.1）：
 *   成功 { ok:true,  data:…, meta:{…} }
 *   失败 { ok:false, error:{ code, message, field } }
 *
 * 【设计要点】
 * · 只走这一个入口，不散落 fetch —— 否则 CSRF 头、凭据、错误处理
 *   必然在某个角落被漏掉。
 * · 失败一律抛 ApiError，调用方 await 即可；不要返回 {ok:false} 让
 *   调用方自己判断，那会漏判。
 */

const API_URL = 'api.php'

/** CSRF token 保存在内存里：不落 localStorage，XSS 拿不到长效凭证 */
let csrfToken = ''

export function setCsrfToken(token) {
  csrfToken = token || ''
}

export function getCsrfToken() {
  return csrfToken
}

/** 带业务错误码的异常 */
export class ApiError extends Error {
  constructor(code, message, field, status) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.field = field
    this.status = status
  }

  /** 会话失效 —— 调用方据此跳登录页 */
  get isUnauthorized() {
    return this.code === 'UNAUTHORIZED' || this.status === 401
  }
}

/**
 * 发起一次 API 调用。
 *
 * @param {string} action  如 'content.list'
 * @param {object} params  读请求 → 查询串；写请求 → JSON body
 * @param {object} options { method, signal, formData }
 */
export async function api(action, params = {}, options = {}) {
  const method = (options.method || 'GET').toUpperCase()
  const isWrite = method !== 'GET' && method !== 'HEAD'

  let url = `${API_URL}?action=${encodeURIComponent(action)}`
  const init = {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    signal: options.signal,
  }

  if (isWrite) {
    // CSRF：写请求必须带（SPEC §9.3）。没有 token 时也照发，
    // 让服务端明确地回 403 —— 好过在客户端静默失败。
    init.headers['X-CSRF-Token'] = csrfToken

    if (options.formData) {
      // multipart：不要手动设 Content-Type，浏览器要自己加 boundary
      init.body = options.formData
    } else {
      init.headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(params)
    }
  } else {
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null || value === '') continue
      if (Array.isArray(value)) {
        value.forEach((v) => query.append(`${key}[]`, v))
      } else {
        query.append(key, String(value))
      }
    }
    const qs = query.toString()
    if (qs) url += `&${qs}`
  }

  let response
  try {
    response = await fetch(url, init)
  } catch (err) {
    if (err.name === 'AbortError') throw err
    throw new ApiError('NETWORK_ERROR', '网络连接失败，请检查网络后重试', null, 0)
  }

  // 服务端异常可能返回非 JSON（如 PHP 致命错误页面）。
  // 不能直接 response.json() —— 那会抛出难以理解的 SyntaxError。
  const text = await response.text()
  let payload
  try {
    payload = JSON.parse(text)
  } catch {
    throw new ApiError(
      'BAD_RESPONSE',
      `服务器返回了非预期的内容（HTTP ${response.status}）`,
      null,
      response.status
    )
  }

  if (payload && payload.ok === true) {
    return { data: payload.data, meta: payload.meta ?? null }
  }

  const error = (payload && payload.error) || {}
  const apiError = new ApiError(
    error.code || 'INTERNAL_ERROR',
    error.message || '请求失败',
    error.field || null,
    response.status
  )

  // 会话过期时广播出去，由各入口决定怎么处理（后台 → 跳登录页）。
  // api.js 不认识 router，这个依赖方向必须是单向的。
  if (apiError.isUnauthorized) {
    window.dispatchEvent(new CustomEvent('api:unauthorized'))
  }

  throw apiError
}

/** 便捷封装 */
export const get = (action, params, options) => api(action, params, { ...options, method: 'GET' })
export const post = (action, params, options) => api(action, params, { ...options, method: 'POST' })
