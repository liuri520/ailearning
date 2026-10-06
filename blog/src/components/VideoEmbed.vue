<script setup>
/**
 * VideoEmbed.vue —— 混合视频播放（SPEC D10 / §5.2）
 *
 * 两种承载方式：
 *   embed → 第三方 iframe（bilibili / YouTube）。省流量、省图床配额。
 *   mp4   → 直链 <video>。自己托管时用。
 *
 * 【懒加载是必须的】iframe 一旦进 DOM 就会开始加载播放器脚本，
 * 列表页有几个视频就几份第三方脚本。默认先显示封面，
 * 用户点击后才挂 iframe —— 这是最省流量也最快的做法。
 */
import { ref, computed } from 'vue'
import SmartImage from './SmartImage.vue'

const props = defineProps({
  sourceType: { type: String, default: 'embed' },
  provider: { type: String, default: '' },
  sourceKey: { type: String, default: '' },
  posterUrl: { type: String, default: '' },
  title: { type: String, default: '' },
  aspectRatio: { type: String, default: '16:9' },
  /** 详情页可以自动播放，列表页绝不 */
  autoload: { type: Boolean, default: false },
})

const activated = ref(props.autoload)

/**
 * 把各种来源归一成可嵌入的 URL。
 *
 * bilibili 的 BV 号不能直接进 iframe，必须转成 player.bilibili.com 的地址；
 * YouTube 同理。写成函数而不是模板里的三元表达式，是因为
 * 这里出错的表现是「白框」——没有任何报错，只有一片空白，很难查。
 */
const embedUrl = computed(() => {
  const key = (props.sourceKey || '').trim()
  if (!key) return ''

  const provider = (props.provider || '').toLowerCase()

  if (provider === 'bilibili' || /^BV[0-9A-Za-z]{8,}$/.test(key)) {
    const bvid = key.replace(/^.*(BV[0-9A-Za-z]+).*$/, '$1')
    return `https://player.bilibili.com/player.html?bvid=${encodeURIComponent(bvid)}&autoplay=0&high_quality=1`
  }

  if (provider === 'youtube') {
    return `https://www.youtube.com/embed/${encodeURIComponent(key)}`
  }

  // custom / 未知来源：若本身就是完整 URL，原样使用
  if (/^https?:\/\//i.test(key)) return key

  return ''
})

const ratio = computed(() => (props.aspectRatio || '16:9').replace(':', ' / '))

const isMp4 = computed(() => props.sourceType === 'mp4' && /^https?:\/\//i.test(props.sourceKey))
</script>

<template>
  <div class="video-frame" :style="{ aspectRatio: ratio }">
    <!-- mp4 直链：用原生 <video>，preload=none 避免一进页面就拉整个文件 -->
    <video
      v-if="isMp4"
      :src="sourceKey"
      :poster="posterUrl || undefined"
      controls
      preload="none"
      playsinline
    />

    <!-- embed 且已激活 -->
    <iframe
      v-else-if="activated && embedUrl"
      :src="embedUrl"
      :title="title || '视频'"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
      allowfullscreen
      referrerpolicy="no-referrer"
      loading="lazy"
    />

    <!-- embed 未激活：先给封面，点击才加载播放器 -->
    <button
      v-else
      class="video-poster"
      type="button"
      :aria-label="`播放：${title || '视频'}`"
      @click="activated = true"
    >
      <SmartImage
        :src="posterUrl"
        :alt="title"
        placeholder="视频封面缺失"
        :width="16"
        :height="9"
      />
      <span class="video-poster__play" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor">
          <path d="M8 5v14l11-7z" />
        </svg>
      </span>
      <span class="video-poster__hint">点击播放</span>
    </button>
  </div>
</template>

<style scoped>
.video-poster {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;
  display: block;
}

.video-poster__play {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 3.5rem;
  height: 3.5rem;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.62);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  padding-left: 3px;
}

.video-poster:hover .video-poster__play {
  background: rgba(0, 0, 0, 0.8);
}

.video-poster__hint {
  position: absolute;
  bottom: 0.75rem;
  right: 0.75rem;
  font-size: 0.72rem;
  color: #fff;
  background: rgba(0, 0, 0, 0.6);
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
}
</style>
