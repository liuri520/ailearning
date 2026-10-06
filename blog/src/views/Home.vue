<script setup>
/**
 * Home.vue —— 首页
 *
 * 布局完全由后台的 home_blocks 决定：这里只负责把每种 block_type
 * 渲染成对应形态。新增区块类型时，只需要在 switch 里加一个分支。
 */
import { ref, onMounted } from 'vue'
import { get } from '../lib/api.js'
import { setSiteData, toastError } from '../lib/store.js'
import PostCard from '../components/PostCard.vue'
import SmartImage from '../components/SmartImage.vue'

const loading = ref(true)
const blocks = ref([])

onMounted(async () => {
  try {
    const { data } = await get('site.home')
    // 首页顺带把站点信息带回来了，省一次往返
    if (data.site) setSiteData(data.site)
    blocks.value = data.blocks || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
})

function blockTitle(block) {
  if (block.title) return block.title
  const defaults = {
    banner: '',
    featured: '精选',
    recent_article: '最新文章',
    recent_video: '最新视频',
    gallery: '图集',
    tag_cloud: '标签',
    about: '关于',
  }
  return defaults[block.block_type] || ''
}

/** 每种区块跳「查看全部」的目标 */
function blockMore(block) {
  const map = {
    featured: { name: 'posts' },
    recent_article: { name: 'posts' },
    recent_video: { name: 'videos' },
    gallery: { name: 'gallery' },
    tag_cloud: { name: 'tags' },
  }
  return map[block.block_type] || null
}
</script>

<template>
  <div>
    <div v-if="loading" class="grid grid--wide">
      <div v-for="n in 3" :key="n" class="skeleton" style="height: 16rem" />
    </div>

    <template v-else>
      <!-- 没有配置任何区块时的兜底：至少别让首页全白 -->
      <div v-if="!blocks.length" class="empty">
        首页还没有配置任何区块。到后台「首页布局」里添加即可。
      </div>

      <section v-for="(block, index) in blocks" :key="index" class="block">
        <!-- 横幅 -->
        <div v-if="block.block_type === 'banner'" class="banner">
          <h1 class="banner__title">{{ block.config.title || '你好' }}</h1>
          <p v-if="block.config.subtitle" class="banner__desc">{{ block.config.subtitle }}</p>
        </div>

        <!-- 关于 -->
        <div v-else-if="block.block_type === 'about'" class="card">
          <h2 class="block__title">{{ blockTitle(block) }}</h2>
          <p style="margin:0;white-space:pre-wrap">{{ block.config.text }}</p>
        </div>

        <!-- 标签云 -->
        <div v-else-if="block.block_type === 'tag_cloud'">
          <div class="block__head">
            <h2 class="block__title">{{ blockTitle(block) }}</h2>
            <RouterLink class="small muted" :to="blockMore(block)">全部标签 →</RouterLink>
          </div>
          <div class="tag-cloud">
            <RouterLink
              v-for="tag in block.items"
              :key="tag.id"
              class="tag-chip"
              :to="{ name: 'tag', params: { slug: tag.slug } }"
            >
              {{ tag.name }}
              <span class="tag-chip__count">{{ tag.count ?? 0 }}</span>
            </RouterLink>
          </div>
        </div>

        <!-- 图集：用方形网格而不是卡片，图片本身才是主体 -->
        <div v-else-if="block.block_type === 'gallery'">
          <div class="block__head">
            <h2 class="block__title">{{ blockTitle(block) }}</h2>
            <RouterLink class="small muted" :to="blockMore(block)">查看全部 →</RouterLink>
          </div>
          <div class="gallery-grid">
            <RouterLink
              v-for="item in block.items"
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
              />
              <span v-if="item.title" class="gallery-item__caption">{{ item.title }}</span>
            </RouterLink>
          </div>
        </div>

        <!-- 其余（featured / recent_article / recent_video）统一走卡片网格 -->
        <div v-else>
          <div class="block__head">
            <h2 class="block__title">{{ blockTitle(block) }}</h2>
            <RouterLink v-if="blockMore(block)" class="small muted" :to="blockMore(block)">
              查看全部 →
            </RouterLink>
          </div>

          <div v-if="!block.items.length" class="empty">这里还没有内容</div>
          <div v-else class="grid grid--wide">
            <PostCard v-for="item in block.items" :key="item.id" :item="item" />
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
