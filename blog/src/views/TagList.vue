<script setup>
/**
 * TagList.vue —— 标签总览
 *
 * 按内容条数降序，把字号随条数递增 —— 这就是「标签云」的全部含义，
 * 不需要额外配置权重字段。
 */
import { ref, computed, onMounted } from 'vue'
import { get } from '../lib/api.js'
import { toastError } from '../lib/store.js'

const tags = ref([])
const loading = ref(true)

const ranked = computed(() => [...tags.value].sort((a, b) => (b.count || 0) - (a.count || 0)))

const maxCount = computed(() => Math.max(1, ...ranked.value.map((t) => t.count || 0)))

/** 条数 → 字号。用平方根而不是线性，否则一个爆款标签会把其他都压成小字 */
function sizeFor(count) {
  const ratio = Math.sqrt((count || 0) / maxCount.value)
  return `${(0.8 + ratio * 0.6).toFixed(2)}rem`
}

onMounted(async () => {
  try {
    const { data } = await get('tag.list')
    tags.value = data || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">标签</h1>
    </div>

    <div v-if="loading" class="tag-cloud">
      <div v-for="n in 10" :key="n" class="skeleton" style="height: 2rem;width: 5rem" />
    </div>

    <div v-else-if="!ranked.length" class="empty">还没有标签</div>

    <div v-else class="tag-cloud">
      <RouterLink
        v-for="tag in ranked"
        :key="tag.id"
        class="tag-chip"
        :style="{ fontSize: sizeFor(tag.count) }"
        :to="{ name: 'tag', params: { slug: tag.slug } }"
      >
        {{ tag.name }}
        <span class="tag-chip__count">{{ tag.count || 0 }}</span>
      </RouterLink>
    </div>
  </div>
</template>
