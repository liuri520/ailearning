/**
 * format.js —— 展示层格式化工具
 */

/** 把 MySQL DATETIME（'2026-10-06 12:30:00'）转成 Date。
 *  Safari 不接受带空格的写法，必须换成 ISO 的 'T'，否则 iOS 上会得到 Invalid Date。 */
export function parseDate(value) {
  if (!value) return null
  if (value instanceof Date) return value
  const normalized = String(value).replace(' ', 'T')
  const d = new Date(normalized)
  return Number.isNaN(d.getTime()) ? null : d
}

/** 2026-10-06 */
export function formatDate(value) {
  const d = parseDate(value)
  if (!d) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** 2026-10-06 12:30 */
export function formatDateTime(value) {
  const d = parseDate(value)
  if (!d) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${formatDate(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** 相对时间：刚刚 / 5 分钟前 / 3 天前 / 2026-01-01 */
export function formatRelative(value) {
  const d = parseDate(value)
  if (!d) return ''

  const diff = Date.now() - d.getTime()
  if (diff < 0) return formatDate(d)          // 定时发布的未来时间
  const minute = 60000
  const hour = 60 * minute
  const day = 24 * hour

  if (diff < minute) return '刚刚'
  if (diff < hour) return `${Math.floor(diff / minute)} 分钟前`
  if (diff < day) return `${Math.floor(diff / hour)} 小时前`
  if (diff < 30 * day) return `${Math.floor(diff / day)} 天前`
  return formatDate(d)
}

/** 秒 → 12:34 / 1:02:03 */
export function formatDuration(seconds) {
  if (!seconds && seconds !== 0) return ''
  const s = Math.max(0, Math.floor(seconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`
}

/** 发布时间是否在未来（定时发布） */
export function isScheduled(value) {
  const d = parseDate(value)
  return d !== null && d.getTime() > Date.now()
}

/** 截断文本 */
export function truncate(text, len = 80) {
  const t = String(text || '').trim()
  return t.length <= len ? t : `${t.slice(0, len)}…`
}

/** 状态 → 中文标签 */
export const STATUS_LABEL = {
  draft: '草稿',
  published: '已发布',
  trashed: '回收站',
}

export const TYPE_LABEL = {
  article: '文章',
  video: '视频',
  image: '图片',
}

/** 资产巡检状态 → 中文 + 配色等级 */
export const CHECK_LABEL = {
  ok: { text: '正常', level: 'success' },
  missing: { text: '已失效', level: 'danger' },
  expired: { text: '已过期', level: 'danger' },
  unknown: { text: '未知', level: 'muted' },
}
