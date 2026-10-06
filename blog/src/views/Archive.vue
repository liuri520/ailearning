<script setup>
/**
 * Archive.vue —— 归档
 *
 * 两段式：先按月聚合（archive.list），再按选中的年月拉该月的列表。
 * 不一次性把全部内容拉下来 —— 那在文章多了以后会变成一次几 MB 的请求。
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get } from '../lib/api.js'
import { toastError } from '../lib/store.js'
import { formatDate } from '../lib/format.js'

const route = useRoute()
const router = useRouter()

const months = ref([])
const rows = ref([])
const loadingMonths = ref(true)
const loadingRows = ref(false)

const selected = computed(() => {
  const year = parseInt(route.query.year, 10)
  const month = parseInt(route.query.month, 10)
  if (!Number.isFinite(year)) return null
  return { year, month: Number.isFinite(month) ? month : undefined }
})

const grouped = computed(() => {
  const map = new Map()
  for (const item of rows.value) {
    const year = String(item.published_at || item.created_at || '').slice(0, 4)
    if (!map.has(year)) map.set(year, [])
    map.get(year).push(item)
  }
  return [...map.entries()].sort((a, b) => b[0].localeCompare(a[0]))
})

function selectMonth(entry) {
  router.push({
    name: 'archive',
    query: { year: String(entry.year), month: String(entry.month) },
  })
}

function clearFilter() {
  router.push({ name: 'archive' })
}

async function loadMonths() {
  loadingMonths.value = true
  try {
    const { data } = await get('archive.list')
    months.value = data || []
  } catch (err) {
    toastError(err)
  } finally {
    loadingMonths.value = false
  }
}

async function loadRows() {
  loadingRows.value = true
  try {
    const params = { per_page: 100 }
    if (selected.value) {
      params.year = selected.value.year
      if (selected.value.month) params.month = selected.value.month
    }
    const { data } = await get('content.list', params)
    rows.value = data || []
  } catch (err) {
    toastError(err)
  } finally {
    loadingRows.value = false
  }
}

onMounted(async () => {
  await loadMonths()
  await loadRows()
})

watch(selected, loadRows)
</script>

<template>
  <div>
    <div class="block__head">
      <h1 class="block__title">归档</h1>
      <button v-if="selected" class="btn btn-sm" type="button" @click="clearFilter">
        显示全部
      </button>
    </div>

    <div v-if="loadingMonths" class="tag-cloud">
      <div v-for="n in 6" :key="n" class="skeleton" style="height: 2rem;width: 5rem" />
    </div>

    <div v-else-if="!months.length" class="empty">还没有已发布的内容</div>

    <template v-else>
      <div class="tag-cloud" style="margin-bottom:var(--space-6)">
        <button
          v-for="entry in months"
          :key="`${entry.year}-${entry.month}`"
          class="tag-chip"
          :class="{ 'is-active': selected && selected.year === entry.year && selected.month === entry.month }"
          type="button"
          style="cursor:pointer"
          @click="selectMonth(entry)"
        >
          {{ entry.year }} 年 {{ entry.month }} 月
          <span class="tag-chip__count">{{ entry.count }}</span>
        </button>
      </div>

      <div v-if="loadingRows" class="skeleton" style="height: 12rem" />

      <div v-else-if="!rows.length" class="empty">这段时间没有内容</div>

      <!-- v-for 与 v-else 不能落在同一元素上，故用 template 包一层 -->
      <template v-else>
        <section v-for="[year, items] in grouped" :key="year" class="archive-year">
          <h2 class="archive-year__title">{{ year }} 年 · {{ items.length }} 篇</h2>
          <ul class="archive-list">
            <li v-for="item in items" :key="item.id">
              <span class="archive-list__date">{{ formatDate(item.published_at).slice(5) }}</span>
              <RouterLink :to="{ name: 'post', params: { slug: item.slug } }">
                {{ item.title }}
              </RouterLink>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </div>
</template>
