/**
 * useContentList.js —— 列表页的公共逻辑
 *
 * 文章列表、视频列表、图集、标签详情、搜索结果这五个页面的差别只有
 * 「固定筛选条件」和「卡片长什么样」，分页、URL 同步、竞态处理完全一样。
 * 抽出来是为了让这五处只写一次 —— 尤其是竞态处理，复制五遍必然漏几处。
 */
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get } from './api.js'
import { toastError } from './store.js'

/**
 * @param {object} options
 * @param {string} options.type        固定类型（article/video/image），留空则不限
 * @param {number} options.perPage     每页条数，0 表示用服务端 page_size
 * @param {Function} [options.extraParams] 额外查询参数（如 tag）
 */
export function useContentList(options = {}) {
  const route = useRoute()
  const router = useRouter()

  const rows = ref([])
  const meta = ref({ total: 0, page: 1, per_page: options.perPage || 10, total_pages: 1 })
  const loading = ref(true)
  const error = ref(null)

  /**
   * 竞态保护：用户快速翻页时，先发的请求可能后到，
   * 结果就是页码显示第 3 页、内容是第 2 页的。用一个自增序号丢弃过期响应。
   */
  let requestId = 0

  const page = computed(() => {
    const n = parseInt(route.query.page, 10)
    return Number.isFinite(n) && n > 0 ? n : 1
  })

  const query = computed(() => (route.query.q ? String(route.query.q) : ''))

  const totalPages = computed(() => meta.value.total_pages || 1)

  async function load() {
    const current = ++requestId
    loading.value = true
    error.value = null

    try {
      const params = {
        page: page.value,
        q: query.value || undefined,
        type: options.type || undefined,
        ...(options.extraParams ? options.extraParams(route) : {}),
      }
      if (options.perPage) params.per_page = options.perPage

      const result = await get('content.list', params)

      // 过期响应直接丢弃
      if (current !== requestId) return

      rows.value = result.data || []
      meta.value = result.meta || meta.value
    } catch (err) {
      if (current !== requestId) return
      error.value = err.message
      toastError(err)
    } finally {
      if (current === requestId) loading.value = false
    }
  }

  /** 翻页：改 URL 而不是直接改状态，这样浏览器前进/后退也能正常工作 */
  function goPage(next) {
    router.push({ query: { ...route.query, page: next > 1 ? String(next) : undefined } })
  }

  watch(
    () => [route.query.page, route.query.q, route.name, route.params.slug],
    load,
    { immediate: true }
  )

  return { rows, meta, loading, error, page, query, totalPages, load, goPage }
}
