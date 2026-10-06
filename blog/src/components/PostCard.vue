<script setup>
/**
 * PostCard.vue —— 列表卡片（文章 / 视频 / 图片共用）
 *
 * 三种类型的封面来源不同，在这里统一：
 *   文章 → cover_path
 *   视频 → poster_url（无 FFmpeg，封面必须手填，SPEC §5.2）
 *   图片 → thumb_url（列表页用缩略版，别拉原图）
 */
import { computed } from 'vue'
import SmartImage from './SmartImage.vue'
import { formatDate, formatDuration, TYPE_LABEL } from '../lib/format.js'

const props = defineProps({
  item: { type: Object, required: true },
})

const to = computed(() => ({ name: 'post', params: { slug: props.item.slug } }))

const cover = computed(() => {
  const it = props.item
  if (it.type === 'image') return it.image_url || ''
  if (it.type === 'video') return it.poster_url || it.cover_path || ''
  return it.cover_path || ''
})

const coverThumb = computed(() => {
  const it = props.item
  if (it.type === 'image') return it.thumb_url || ''
  return ''
})

const hasCover = computed(() => !!cover.value || !!coverThumb.value)

const aspect = computed(() => {
  const it = props.item
  if (it.type === 'image' && it.width && it.height) {
    return `${it.width} / ${it.height}`
  }
  return '16 / 9'
})
</script>

<template>
  <article class="post-card">
    <RouterLink v-if="hasCover" class="post-card__cover" :to="to" :style="{ aspectRatio: aspect }">
      <SmartImage
        :src="cover"
        :thumb-src="coverThumb"
        use-thumb
        :width="item.width || 0"
        :height="item.height || 0"
        :alt="item.title"
        :placeholder="TYPE_LABEL[item.type] || '内容'"
      />
    </RouterLink>

    <div class="post-card__body">
      <h3 class="post-card__title">
        <RouterLink :to="to">{{ item.title }}</RouterLink>
      </h3>

      <p v-if="item.summary" class="post-card__summary">{{ item.summary }}</p>

      <div class="post-card__meta">
        <span>{{ formatDate(item.published_at || item.created_at) }}</span>
        <span v-if="item.type !== 'article'" class="badge">{{ TYPE_LABEL[item.type] }}</span>
        <span v-if="item.type === 'video' && item.duration_seconds">
          {{ formatDuration(item.duration_seconds) }}
        </span>
        <RouterLink
          v-for="tag in (item.tags || []).slice(0, 3)"
          :key="tag.id"
          class="faint"
          :to="{ name: 'tag', params: { slug: tag.slug } }"
        >
          #{{ tag.name }}
        </RouterLink>
      </div>
    </div>
  </article>
</template>
