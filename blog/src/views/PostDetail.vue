<script setup>
/**
 * PostDetail.vue —— 内容详情（文章 / 视频 / 图片）
 *
 * 【slug 变更的处理】服务端命中 slug_redirects 时会在返回值里带 redirect_to。
 * 这里把浏览器地址换成新 slug，而不是只显示内容 —— 否则用户复制地址栏
 * 拿到的仍是旧链接，下次访问又要再兜一圈。
 */
import { ref, watch, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post } from '../lib/api.js'
import { toastError, site } from '../lib/store.js'
import { renderMarkdown } from '../lib/markdown.js'
import { formatDate, formatDuration, TYPE_LABEL } from '../lib/format.js'
import SmartImage from '../components/SmartImage.vue'
import VideoEmbed from '../components/VideoEmbed.vue'
import PostCard from '../components/PostCard.vue'

const props = defineProps({
  slug: { type: String, required: true },
})

const route = useRoute()
const router = useRouter()

const item = ref(null)
const related = ref([])
const loading = ref(true)
const notFound = ref(false)

const html = computed(() => (item.value ? renderMarkdown(item.value.body_md || '') : ''))

const shareUrl = computed(() => {
  const base = window.location.href.split('#')[0]
  return `${base}share.php?slug=${encodeURIComponent(props.slug)}`
})

async function load() {
  loading.value = true
  notFound.value = false
  item.value = null
  related.value = []

  try {
    const { data } = await get('content.detail', { slug: props.slug })

    // 旧地址 → 换成新的，保持地址栏与内容一致
    if (data.redirect_to && data.redirect_to !== props.slug) {
      router.replace({ name: 'post', params: { slug: data.redirect_to } })
      return
    }

    item.value = data
    document.title = `${data.title} - ${site.data.site_name || '博客'}`

    // 浏览上报：fire-and-forget。失败对用户毫无影响，
    // 所以既不 await 也不弹窗 —— 只吞掉异常。
    post('view.hit', { slug: data.slug }).catch(() => {})

    loadRelated()
  } catch (err) {
    if (err.code === 'NOT_FOUND') {
      notFound.value = true
    } else {
      toastError(err)
    }
  } finally {
    loading.value = false
  }
}

async function loadRelated() {
  try {
    const { data } = await get('content.related', { slug: props.slug, limit: 3 })
    related.value = data || []
  } catch {
    // 相关推荐拿不到就静默不显示，不值得打扰用户
  }
}

watch(() => [props.slug, route.name], load)
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="detail">
      <div class="skeleton" style="height: 2rem;width: 60%;margin-bottom:1rem" />
      <div class="skeleton" style="height: 1rem;width: 30%;margin-bottom:2rem" />
      <div class="skeleton" style="height: 20rem" />
    </div>

    <div v-else-if="notFound" class="empty">
      <h1>内容不存在</h1>
      <p>它可能已被删除，或者从未发布。</p>
      <RouterLink class="btn" :to="{ name: 'home' }">返回首页</RouterLink>
    </div>

    <article v-else-if="item" class="detail">
      <h1 class="detail__title">{{ item.title }}</h1>

      <div class="detail__meta">
        <span>{{ formatDate(item.published_at || item.created_at) }}</span>
        <span v-if="item.type !== 'article'" class="badge">{{ TYPE_LABEL[item.type] }}</span>
        <span v-if="item.word_count">{{ item.word_count }} 字</span>
        <span v-if="item.type === 'video' && item.duration_seconds">
          {{ formatDuration(item.duration_seconds) }}
        </span>

        <RouterLink
          v-for="tag in item.tags || []"
          :key="tag.id"
          class="tag-chip"
          :to="{ name: 'tag', params: { slug: tag.slug } }"
        >
          {{ tag.name }}
        </RouterLink>

        <a
          class="faint small"
          style="margin-left:auto"
          :href="shareUrl"
          target="_blank"
          rel="noopener"
          title="打开分享页（含 OG 卡片）"
        >分享 ↗</a>
      </div>

      <!-- 图集：大图直出，点开看原图 -->
      <template v-if="item.type === 'image'">
        <figure class="detail__hero">
          <a :href="item.image_url" target="_blank" rel="noopener">
            <SmartImage
              :src="item.image_url"
              :width="item.width || 0"
              :height="item.height || 0"
              :alt="item.alt_text || item.title"
              placeholder="图片暂不可用"
              eager
            />
          </a>
        </figure>
        <p v-if="item.camera_note" class="small muted" style="text-align:center">
          {{ item.camera_note }}
        </p>
      </template>

      <!-- 视频 -->
      <VideoEmbed
        v-else-if="item.type === 'video'"
        :source-type="item.source_type"
        :provider="item.provider"
        :source-key="item.source_key"
        :poster-url="item.poster_url"
        :title="item.title"
        :aspect-ratio="item.aspect_ratio"
      />

      <!-- 文章正文 -->
      <div v-if="item.type === 'article'" class="prose" v-html="html" />
      <p v-else-if="item.summary" class="prose">{{ item.summary }}</p>

      <!-- 相关推荐 -->
      <section v-if="related.length" class="block" style="margin-top:var(--space-7)">
        <h2 class="block__title" style="margin-bottom:var(--space-4)">相关推荐</h2>
        <div class="grid grid--wide">
          <PostCard v-for="rel in related" :key="rel.id" :item="rel" />
        </div>
      </section>
    </article>
  </div>
</template>
