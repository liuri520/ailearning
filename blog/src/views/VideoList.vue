<script setup>
/**
 * VideoList.vue —— 视频列表
 *
 * 列表页**不加载播放器**，只展示封面（VideoEmbed 的懒加载在详情页生效）。
 * 一页十几个 iframe 会把第三方脚本拖成主要加载负担。
 */
import { useContentList } from '../lib/useContentList.js'
import PostCard from '../components/PostCard.vue'
import Pagination from '../components/Pagination.vue'

const { rows, meta, loading, page, totalPages, goPage } = useContentList({
  type: 'video',
  perPage: 12,
})
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">视频</h1>
    </div>

    <div v-if="loading" class="grid">
      <div v-for="n in 4" :key="n" class="skeleton" style="height: 14rem" />
    </div>

    <div v-else-if="!rows.length" class="empty">还没有视频</div>

    <template v-else>
      <div class="grid">
        <PostCard v-for="item in rows" :key="item.id" :item="item" />
      </div>
      <Pagination :page="page" :total-pages="totalPages" :total="meta.total" @change="goPage" />
    </template>
  </div>
</template>
