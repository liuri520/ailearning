<script setup>
/**
 * Gallery.vue —— 图集网格
 *
 * 一律用缩略版（thumb_url），并且保留纵横比 ——
 * 图片墙里最刺眼的就是高度不一的方块被强行拉平。
 */
import { useContentList } from '../lib/useContentList.js'
import SmartImage from '../components/SmartImage.vue'
import Pagination from '../components/Pagination.vue'

const { rows, meta, loading, page, totalPages, goPage } = useContentList({
  type: 'image',
  perPage: 24,
})
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">图集</h1>
    </div>

    <div v-if="loading" class="gallery-grid">
      <div v-for="n in 8" :key="n" class="skeleton" style="aspect-ratio: 1" />
    </div>

    <div v-else-if="!rows.length" class="empty">还没有图片</div>

    <template v-else>
      <div class="gallery-grid">
        <RouterLink
          v-for="item in rows"
          :key="item.id"
          class="gallery-item"
          :to="{ name: 'post', params: { slug: item.slug } }"
        >
          <SmartImage
            :src="item.image_url"
            :thumb-src="item.thumb_url"
            use-thumb
            :alt="item.alt_text || item.title"
            :width="item.width || 1"
            :height="item.height || 1"
            placeholder="图片暂不可用"
          />
          <span class="gallery-item__caption">{{ item.title }}</span>
        </RouterLink>
      </div>
      <Pagination :page="page" :total-pages="totalPages" :total="meta.total" @change="goPage" />
    </template>
  </div>
</template>
