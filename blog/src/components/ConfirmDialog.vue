<script setup>
/**
 * ConfirmDialog.vue —— 确认弹窗
 *
 * 【为什么不用 window.confirm】原生确认框在移动端样式不可控，
 * 而且删内容、删图床直链这类操作需要一个能说清楚后果的说明区
 * （比如「将同时删除图床文件，释放空间」）。
 */
import { watch, onUnmounted } from 'vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '确认操作' },
  message: { type: String, default: '' },
  confirmText: { type: String, default: '确定' },
  cancelText: { type: String, default: '取消' },
  /** danger 时确认按钮变红，用于不可逆操作 */
  variant: { type: String, default: 'default' },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel'])

function onKeydown(event) {
  if (!props.open) return
  if (event.key === 'Escape') emit('cancel')
}

watch(() => props.open, (open) => {
  if (open) {
    window.addEventListener('keydown', onKeydown)
  } else {
    window.removeEventListener('keydown', onKeydown)
  }
})

onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click.self="emit('cancel')">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="title">
        <div class="modal__head">{{ title }}</div>

        <div class="modal__body">
          <p v-if="message" style="margin:0">{{ message }}</p>
          <slot />
        </div>

        <div class="modal__foot">
          <button class="btn" type="button" :disabled="loading" @click="emit('cancel')">
            {{ cancelText }}
          </button>
          <button
            class="btn"
            :class="variant === 'danger' ? 'btn-danger' : 'btn-primary'"
            type="button"
            :disabled="loading"
            @click="emit('confirm')"
          >
            {{ loading ? '处理中…' : confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
