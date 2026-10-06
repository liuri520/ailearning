<script setup>
/**
 * Pagination.vue —— 分页
 *
 * 只暴露上一页/下一页 + 页码信息，不做数字页码列表。
 * 理由：这个站的每页条数不多，用户真正用得到的是「往后翻」，
 * 一排数字按钮反而占地方、还要处理省略号逻辑。
 */
import { computed } from 'vue'

const props = defineProps({
  page: { type: Number, required: true },
  totalPages: { type: Number, default: 1 },
  total: { type: Number, default: 0 },
})

const emit = defineEmits(['change'])

const canPrev = computed(() => props.page > 1)
const canNext = computed(() => props.page < props.totalPages)

function go(next) {
  if (next < 1 || next > props.totalPages || next === props.page) return
  emit('change', next)
}
</script>

<template>
  <nav v-if="totalPages > 1" class="pager">
    <button class="btn btn-sm" type="button" :disabled="!canPrev" @click="go(page - 1)">
      上一页
    </button>

    <span class="pager__info">
      第 {{ page }} / {{ totalPages }} 页
      <template v-if="total">· 共 {{ total }} 条</template>
    </span>

    <button class="btn btn-sm" type="button" :disabled="!canNext" @click="go(page + 1)">
      下一页
    </button>
  </nav>
</template>
