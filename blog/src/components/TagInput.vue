<script setup>
/**
 * TagInput.vue —— 标签输入（支持回车/逗号新增、退格删除、自动补全）
 *
 * 提交的是**名称数组**而不是 id：服务端 Tag::findOrCreate() 会按名称
 * 找或建。这样用户在写文章时可以直接打一个新标签，不必先跑去标签页建好。
 */
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  /** 已有标签，用于自动补全 */
  suggestions: { type: Array, default: () => [] },
  max: { type: Number, default: 10 },
  placeholder: { type: String, default: '输入标签后回车' },
})

const emit = defineEmits(['update:modelValue'])

const draft = ref('')
const focused = ref(false)
const activeIndex = ref(-1)

const selectedNames = computed(() => props.modelValue.map((t) => (typeof t === 'string' ? t : t.name)))

const filtered = computed(() => {
  const keyword = draft.value.trim().toLowerCase()
  return props.suggestions
    .map((t) => (typeof t === 'string' ? t : t.name))
    .filter((name) => !selectedNames.value.includes(name))
    .filter((name) => (keyword === '' ? true : name.toLowerCase().includes(keyword)))
    .slice(0, 8)
})

watch(filtered, () => {
  activeIndex.value = -1
})

function add(name) {
  const value = String(name || '').trim()
  if (!value) return
  if (selectedNames.value.includes(value)) {
    draft.value = ''
    return
  }
  if (selectedNames.value.length >= props.max) return

  emit('update:modelValue', [...selectedNames.value, value])
  draft.value = ''
  activeIndex.value = -1
}

function remove(index) {
  const next = [...selectedNames.value]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

/** 回车或逗号提交；中英文逗号都认（中文输入法下很容易打出「，」） */
function onKeydown(event) {
  const value = draft.value.trim()

  if (event.key === 'Enter' || event.key === ',' || event.key === '，') {
    event.preventDefault()

    if (activeIndex.value >= 0 && filtered.value[activeIndex.value]) {
      add(filtered.value[activeIndex.value])
    } else {
      add(value)
    }
    return
  }

  if (event.key === 'Backspace' && value === '' && selectedNames.value.length > 0) {
    remove(selectedNames.value.length - 1)
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1)
    return
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, -1)
    return
  }

  if (event.key === 'Escape') {
    focused.value = false
  }
}

/** 失焦时把没提交的草稿也收进来，避免用户以为已经加上了 */
function onBlur() {
  // 延迟关闭，否则点击下拉项时 blur 先于 click 触发，选择落空
  setTimeout(() => {
    focused.value = false
    if (draft.value.trim()) add(draft.value)
  }, 160)
}
</script>

<template>
  <div class="tag-input-wrap">
    <div class="tag-input">
      <span v-for="(name, index) in selectedNames" :key="name" class="tag-input__chip">
        {{ name }}
        <button
          class="tag-input__remove"
          type="button"
          :aria-label="`移除标签 ${name}`"
          @click="remove(index)"
        >×</button>
      </span>

      <input
        v-model="draft"
        type="text"
        :placeholder="selectedNames.length >= max ? `最多 ${max} 个标签` : placeholder"
        :disabled="selectedNames.length >= max"
        @keydown="onKeydown"
        @focus="focused = true"
        @blur="onBlur"
      >
    </div>

    <ul v-if="focused && filtered.length" class="tag-suggest">
      <li
        v-for="(name, index) in filtered"
        :key="name"
        :class="['tag-suggest__item', { 'is-active': index === activeIndex }]"
        @mousedown.prevent="add(name)"
      >
        {{ name }}
      </li>
    </ul>

    <p class="form__hint">
      回车或逗号分隔，最多 {{ max }} 个。不存在的新标签会自动创建。
    </p>
  </div>
</template>

<style scoped>
.tag-input-wrap {
  position: relative;
}

.tag-suggest {
  position: absolute;
  top: calc(100% - 1.6rem);
  left: 0;
  right: 0;
  z-index: 30;
  margin: 0;
  padding: var(--space-1) 0;
  list-style: none;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  max-height: 14rem;
  overflow-y: auto;
}

.tag-suggest__item {
  padding: var(--space-2) var(--space-3);
  font-size: 0.85rem;
  cursor: pointer;
}

.tag-suggest__item:hover,
.tag-suggest__item.is-active {
  background: var(--bg-subtle);
}
</style>
