<script setup>
/**
 * TagDetail.vue —— 某个标签下的内容
 */
import { useContentList } from '../lib/useContentList.js'
import PostCard from '../components/PostCard.vue'
import Pagination from '../components/Pagination.vue'

const props = defineProps({
  slug: { type: String, required: true },
})

const { rows, meta, loading, page, totalPages, goPage } = useContentList({
  perPage: 12,
  extraParams: () => ({ tag: props.slug }),
})
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">#{{ slug }}</h1>
      <RouterLink class="small muted" :to="{ name: 'tags' }">全部标签 →</RouterLink>
    </div>

    <div v-if="loading" class="grid grid--wide">
      <div v-for="n in 3" :key="n" class="skeleton" style="height: 15rem" />
    </div>

    <div v-else-if="!rows.length" class="empty">这个标签下还没有内容</div>

    <template v-else>
      <div class="grid grid--wide">
        <PostCard v-for="item in rows" :key="item.id" :item="item" />
      </div>
      <Pagination :page="page" :total-pages="totalPages" :total="meta.total" @change="goPage" />
    </template>
  </div>
</template>
