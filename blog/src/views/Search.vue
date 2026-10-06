<script setup>
/**
 * Search.vue —— 站内搜索
 *
 * 走 content.list 的 q 参数（服务端 LIKE %kw%），而不是另开一个接口 ——
 * 搜索与列表的差别只在筛选条件，没必要分成两条链路。
 *
 * 输入框带标题建议（search.suggest，走 idx_title 索引）。
 * 它是「前缀匹配」，与全文搜索的「包含匹配」语义不同：
 * 建议是帮你把标题打完，回车才是真正搜全文。
 */
import { ref, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useContentList } from '../lib/useContentList.js'
import { get } from '../lib/api.js'
import PostCard from '../components/PostCard.vue'
import Pagination from '../components/Pagination.vue'

const route = useRoute()
const router = useRouter()

const { rows, meta, loading, page, totalPages, query, goPage } = useContentList({ perPage: 10 })

const keyword = ref(query.value)
watch(query, (value) => { keyword.value = value })

const suggestions = ref([])
const showSuggest = ref(false)
const activeIndex = ref(-1)
let suggestTimer = null

/** 防抖 220ms：建议是每敲一个字都要发的请求，不防抖会把接口打爆 */
function onInput() {
  activeIndex.value = -1
  clearTimeout(suggestTimer)

  const value = keyword.value.trim()
  if (!value) {
    suggestions.value = []
    showSuggest.value = false
    return
  }

  suggestTimer = setTimeout(async () => {
    try {
      const { data } = await get('search.suggest', { q: value, limit: 8 })
      suggestions.value = data || []
      showSuggest.value = suggestions.value.length > 0
    } catch {
      // 建议失败无感 —— 用户照样能按回车搜
      suggestions.value = []
    }
  }, 220)
}

function submit() {
  const value = keyword.value.trim()
  if (!value) return
  showSuggest.value = false
  router.push({ name: 'search', query: { q: value } })
}

function goto(item) {
  showSuggest.value = false
  router.push({ name: 'post', params: { slug: item.slug } })
}

function onKeydown(event) {
  if (!showSuggest.value) return

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, suggestions.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, -1)
  } else if (event.key === 'Enter' && activeIndex.value >= 0) {
    event.preventDefault()
    goto(suggestions.value[activeIndex.value])
  } else if (event.key === 'Escape') {
    showSuggest.value = false
  }
}

function onBlur() {
  // 延迟收起，否则点击下拉项时 blur 先触发
  setTimeout(() => { showSuggest.value = false }, 160)
}

onUnmounted(() => clearTimeout(suggestTimer))
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">搜索</h1>
    </div>

    <form class="filters" @submit.prevent="submit">
      <div class="filters__search">
        <input
          v-model="keyword"
          type="search"
          placeholder="输入关键词…"
          aria-label="搜索关键词"
          autocomplete="off"
          @input="onInput"
          @keydown="onKeydown"
          @focus="showSuggest = suggestions.length > 0"
          @blur="onBlur"
        >

        <div v-if="showSuggest" class="suggest">
          <a
            v-for="(item, index) in suggestions"
            :key="item.slug"
            class="suggest__item"
            :class="{ 'is-active': index === activeIndex }"
            href="#"
            @mousedown.prevent="goto(item)"
          >
            {{ item.title }}
          </a>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">搜索</button>
    </form>

    <template v-if="query">
      <p class="muted small">
        共找到 {{ meta.total }} 条与「{{ query }}」相关的内容
      </p>

      <div v-if="loading" class="grid grid--wide">
        <div v-for="n in 3" :key="n" class="skeleton" style="height: 15rem" />
      </div>

      <div v-else-if="!rows.length" class="empty">
        没有找到相关内容。试试更短的关键词？
      </div>

      <template v-else>
        <div class="grid grid--wide">
          <PostCard v-for="item in rows" :key="item.id" :item="item" />
        </div>
        <Pagination :page="page" :total-pages="totalPages" :total="meta.total" @change="goPage" />
      </template>
    </template>

    <div v-else class="empty">输入关键词开始搜索</div>
  </div>
</template>
