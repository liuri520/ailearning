<script setup>
/**
 * SmartImage.vue —— 图片展示与降级
 *
 * 【为什么要有这一层】SPEC §7.2.5 的四层回退最后落到「宁可显示占位图，
 * 也不吐出半截 URL」。后端已在 url() 里返回 null，但前端仍会遇到
 * 直链可拼出来、请求却 404 的情况（图床上的文件被删）。
 * 把 <img> 的 onerror 收敛到一个组件里，比在每个视图里各写一遍可靠。
 */
import { ref, computed, watch } from 'vue'

const props = defineProps({
  src: { type: String, default: '' },
  /** 原图尺寸，用于占位防布局跳动（SPEC §5.2 width/height 必填的原因） */
  width: { type: Number, default: 0 },
  height: { type: Number, default: 0 },
  alt: { type: String, default: '' },
  /** 缩略版地址；有则列表页用它，省流量 */
  thumbSrc: { type: String, default: '' },
  useThumb: { type: Boolean, default: false },
  /** 无图时显示的占位文字 */
  placeholder: { type: String, default: '暂无图片' },
  eager: { type: Boolean, default: false },
})

const failed = ref(false)

// 地址换了要重置失败标记 —— 否则同一组件复用到新图上会一直显示占位
watch(() => [props.src, props.thumbSrc], () => {
  failed.value = false
})

const resolved = computed(() => {
  if (props.useThumb && props.thumbSrc) return props.thumbSrc
  return props.src || props.thumbSrc || ''
})

const showPlaceholder = computed(() => failed.value || !resolved.value)

/**
 * 占位框的宽高比。
 * 用 padding-top 百分比而非 aspect-ratio，是为了在老浏览器上也能撑开高度 ——
 * 图片没加载出来时它旁边的文字不会跳来跳去。
 */
const ratioStyle = computed(() => {
  if (!props.width || !props.height) return {}
  return { aspectRatio: `${props.width} / ${props.height}` }
})
</script>

<template>
  <div class="smart-image" :style="ratioStyle">
    <img
      v-if="!showPlaceholder"
      :src="resolved"
      :alt="alt"
      :loading="eager ? 'eager' : 'lazy'"
      :decoding="eager ? 'sync' : 'async'"
      @error="failed = true"
    >
    <div v-else class="smart-image__placeholder">
      <span>{{ placeholder }}</span>
    </div>
  </div>
</template>

<style scoped>
.smart-image {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 4rem;
  background: var(--bg-inset);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.smart-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.smart-image__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  min-height: 4rem;
  color: var(--text-faint);
  font-size: 0.78rem;
  /* 斜纹底：一眼就能看出是占位而非「图片就是这个颜色」 */
  background-image: repeating-linear-gradient(
    45deg,
    transparent,
    transparent 8px,
    var(--bg-subtle) 8px,
    var(--bg-subtle) 16px
  );
}
</style>
