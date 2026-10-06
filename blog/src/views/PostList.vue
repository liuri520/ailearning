<script setup>
/**
 * PostList.vue —— 文章列表（带关键词搜索）
 */
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useContentList } from '../lib/useContentList.js'
import PostCard from '../components/PostCard.vue'
import Pagination from '../components/Pagination.vue'

const route = useRoute()
const router = useRouter()

const { rows, meta, loading, page, totalPages, query, goPage } = useContentList({
  type: 'article',
  perPage: 10,
})

// 搜索框本地状态与 URL 同步：直接绑 route.query 会让每敲一个字就发一次请求
const keyword = ref(query.value)
watch(query, (value) => { keyword.value = value })

function submitSearch() {
  const value = keyword.value.trim()
  router.push({
    name: 'posts',
    query: value ? { q: value } : {},
  })
}

function clearSearch() {
  keyword.value = ''
  router.push({ name: 'posts' })
}
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">{{ query ? `搜索：${query}` : '文章' }}</h1>
      <RouterLink class="small muted" :to="{ name: 'archive' }">按时间浏览 →</RouterLink>
    </div>

    <form class="filters" @submit.prevent="submitSearch">
      <div class="filters__search">
        <input v-model="keyword" type="search" placeholder="搜索标题、摘要与正文…" aria-label="搜索文章">
      </div>
      <button class="btn btn-primary" type="submit">搜索</button>
      <button v-if="query" class="btn" type="button" @click="clearSearch">清除</button>
    </form>

    <div v-if="loading" class="grid grid--wide">
      <div v-for="n in 4" :key="n" class="skeleton" style="height: 15rem" />
    </div>

    <div v-else-if="!rows.length" class="empty">
      <template v-if="query">没有找到与「{{ query }}」相关的文章</template>
      <template v-else>还没有文章</template>
    </div>

    <template v-else>
      <div class="grid grid--wide">
        <PostCard v-for="item in rows" :key="item.id" :item="item" />
      </div>
      <Pagination :page="page" :total-pages="totalPages" :total="meta.total" @change="goPage" />
    </template>
  </div>
</template>
