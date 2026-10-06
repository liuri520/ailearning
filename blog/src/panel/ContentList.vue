<script setup>
/**
 * ContentList.vue —— 内容管理
 *
 * 拖拽排序用手写 HTML5 DnD（SPEC 技术方案里的决定）：
 * 排序只发生在一个页面上、一次拖动改一批 sort_weight，
 * 引一个拖拽库（vuedraggable + Sortable）不值得。
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import { formatDateTime, STATUS_LABEL, TYPE_LABEL } from '../lib/format.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const route = useRoute()
const router = useRouter()

const rows = ref([])
const meta = ref({ total: 0, page: 1, total_pages: 1 })
const loading = ref(true)

const filters = ref({
  type: String(route.query.type || ''),
  status: String(route.query.status || ''),
  q: String(route.query.q || ''),
})

const selected = ref(new Set())
const dragIndex = ref(-1)
const overIndex = ref(-1)

const confirmState = ref({ open: false, title: '', message: '', variant: 'danger', onConfirm: null })

const allSelected = computed(() => rows.value.length > 0 && selected.value.size === rows.value.length)

async function load() {
  loading.value = true
  try {
    const result = await get('admin.content.list', {
      ...filters.value,
      page: route.query.page || 1,
      per_page: 20,
    })
    rows.value = result.data || []
    meta.value = result.meta || meta.value
    selected.value = new Set()
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

/** 筛选条件写进 URL —— 刷新后不丢，也方便把筛选结果直接发给别人 */
function applyFilters() {
  router.replace({
    name: 'panel-contents',
    query: {
      ...(filters.value.type ? { type: filters.value.type } : {}),
      ...(filters.value.status ? { status: filters.value.status } : {}),
      ...(filters.value.q ? { q: filters.value.q } : {}),
    },
  })
}

function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(rows.value.map((r) => r.id))
}

function toggleOne(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

function askConfirm(title, message, action, variant = 'danger') {
  confirmState.value = { open: true, title, message, variant, onConfirm: action }
}

async function runConfirm() {
  const fn = confirmState.value.onConfirm
  confirmState.value.open = false
  if (fn) await fn()
}

// ── 单条操作 ────────────────────────────────────────────────
async function doTrash(id) {
  try {
    await post('admin.content.trash', { id })
    toast('已移入回收站', 'success')
    load()
  } catch (err) { toastError(err) }
}

async function doRestore(id) {
  try {
    await post('admin.content.restore', { id })
    toast('已恢复为草稿', 'success')
    load()
  } catch (err) { toastError(err) }
}

function doDestroy(row) {
  askConfirm(
    '彻底删除',
    `将永久删除「${row.title}」。若配图没有其他引用，图床上的文件也会一并删除以释放空间。此操作不可撤销。`,
    async () => {
      try {
        await post('admin.content.destroy', { id: row.id })
        toast('已彻底删除', 'success')
        load()
      } catch (err) { toastError(err) }
    }
  )
}

// ── 批量操作 ────────────────────────────────────────────────
async function batch(op, label) {
  const ids = [...selected.value]
  if (!ids.length) return

  if (op === 'destroy') {
    askConfirm(
      `批量${label}`,
      `将永久删除选中的 ${ids.length} 条内容，其独占的图片会从图床一并删除。此操作不可撤销。`,
      () => runBatch(ids, op, label)
    )
    return
  }
  await runBatch(ids, op, label)
}

async function runBatch(ids, op, label) {
  try {
    const { data } = await post('admin.content.batch', { ids, op })
    toast(`${label}完成，影响 ${data.affected} 条`, 'success')
    load()
  } catch (err) { toastError(err) }
}

// ── 拖拽排序 ────────────────────────────────────────────────
/**
 * 排序只在同一页内进行 —— 跨页拖拽需要先明确「插到哪一页」，
 * 而这个语义在分页列表里没有直观的表达方式。
 */
function onDragStart(index) {
  dragIndex.value = index
}

function onDragOver(index) {
  if (dragIndex.value === -1) return
  overIndex.value = index
}

async function onDrop() {
  const from = dragIndex.value
  const to = overIndex.value

  dragIndex.value = -1
  overIndex.value = -1

  if (from === -1 || to === -1 || from === to) return

  const next = [...rows.value]
  const [moved] = next.splice(from, 1)
  next.splice(to, 0, moved)
  rows.value = next

  try {
    await post('admin.content.reorder', { ids: next.map((r) => r.id) })
    toast('排序已保存', 'success')
  } catch (err) {
    toastError(err)
    load()   // 失败则回滚到服务端状态，别让界面停在一个假的顺序上
  }
}

watch(() => route.query, load)
onMounted(load)
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">内容管理</h1>
      <RouterLink class="btn btn-primary btn-sm" :to="{ name: 'panel-content-new' }" style="margin-left:auto">
        新建内容
      </RouterLink>
    </div>

    <form class="filters" @submit.prevent="applyFilters">
      <select v-model="filters.type" style="width:auto" @change="applyFilters">
        <option value="">全部类型</option>
        <option value="article">文章</option>
        <option value="video">视频</option>
        <option value="image">图片</option>
      </select>

      <select v-model="filters.status" style="width:auto" @change="applyFilters">
        <option value="">默认（不含回收站）</option>
        <option value="published">已发布</option>
        <option value="draft">草稿</option>
        <option value="trashed">回收站</option>
        <option value="all">全部</option>
      </select>

      <div class="filters__search">
        <input v-model="filters.q" type="search" placeholder="搜索标题或摘要…" aria-label="搜索内容">
      </div>

      <button class="btn btn-primary btn-sm" type="submit">筛选</button>
    </form>

    <!-- 批量操作条：只在有选中时出现，平时不占地方 -->
    <div v-if="selected.size" class="alert alert--info" style="margin-bottom:var(--space-3)">
      <span>已选择 {{ selected.size }} 条</span>
      <span class="alert__actions">
        <button class="btn btn-sm" type="button" @click="batch('publish', '发布')">发布</button>
        <button class="btn btn-sm" type="button" @click="batch('trash', '移入回收站')">移入回收站</button>
        <button class="btn btn-sm" type="button" @click="batch('restore', '恢复')">恢复</button>
        <button class="btn btn-sm btn-danger" type="button" @click="batch('destroy', '彻底删除')">彻底删除</button>
      </span>
    </div>

    <div v-if="loading" class="skeleton" style="height: 20rem" />

    <div v-else-if="!rows.length" class="empty">没有符合条件的内容</div>

    <div v-else class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:2.5rem">
              <input type="checkbox" :checked="allSelected" aria-label="全选" @change="toggleAll">
            </th>
            <th class="table__drag" title="拖动调整顺序">⋮⋮</th>
            <th>标题</th>
            <th style="width:5rem">类型</th>
            <th style="width:6rem">状态</th>
            <th style="width:9rem">发布时间</th>
            <th style="width:12rem">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, index) in rows"
            :key="row.id"
            :class="{ 'is-dragging': dragIndex === index, 'is-drop-target': overIndex === index && dragIndex !== index }"
            @dragover.prevent="onDragOver(index)"
            @drop.prevent="onDrop"
          >
            <td>
              <input
                type="checkbox"
                :checked="selected.has(row.id)"
                :aria-label="`选择 ${row.title}`"
                @change="toggleOne(row.id)"
              >
            </td>

            <td
              class="table__drag"
              draggable="true"
              title="拖动调整顺序"
              @dragstart="onDragStart(index)"
              @dragend="dragIndex = -1; overIndex = -1"
            >⋮⋮</td>

            <td>
              <RouterLink :to="{ name: 'panel-content-edit', params: { id: row.id } }">
                {{ row.title }}
              </RouterLink>
              <span v-if="row.is_featured" class="badge badge-accent" style="margin-left:var(--space-2)">精选</span>
              <br>
              <span class="tiny faint mono">{{ row.slug }}</span>
            </td>

            <td><span class="badge">{{ TYPE_LABEL[row.type] }}</span></td>

            <td>
              <span
                class="badge"
                :class="{
                  'badge-success': row.status === 'published',
                  'badge-warning': row.status === 'draft',
                  'badge-danger': row.status === 'trashed',
                }"
              >{{ STATUS_LABEL[row.status] }}</span>
            </td>

            <td class="faint tiny">{{ formatDateTime(row.published_at) || '—' }}</td>

            <td class="col-actions">
              <RouterLink class="btn btn-sm btn-ghost" :to="{ name: 'panel-content-edit', params: { id: row.id } }">
                编辑
              </RouterLink>
              <button
                v-if="row.status === 'trashed'"
                class="btn btn-sm btn-ghost"
                type="button"
                @click="doRestore(row.id)"
              >恢复</button>
              <button v-else class="btn btn-sm btn-ghost" type="button" @click="doTrash(row.id)">删除</button>
              <button
                v-if="row.status === 'trashed'"
                class="btn btn-sm btn-ghost"
                style="color:var(--danger)"
                type="button"
                @click="doDestroy(row)"
              >彻底删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta.total_pages > 1" class="pager">
      <button
        class="btn btn-sm"
        type="button"
        :disabled="meta.page <= 1"
        @click="router.push({ query: { ...route.query, page: meta.page - 1 } })"
      >上一页</button>
      <span class="pager__info">第 {{ meta.page }} / {{ meta.total_pages }} 页 · 共 {{ meta.total }} 条</span>
      <button
        class="btn btn-sm"
        type="button"
        :disabled="meta.page >= meta.total_pages"
        @click="router.push({ query: { ...route.query, page: meta.page + 1 } })"
      >下一页</button>
    </div>

    <ConfirmDialog
      :open="confirmState.open"
      :title="confirmState.title"
      :message="confirmState.message"
      :variant="confirmState.variant"
      confirm-text="确认删除"
      @confirm="runConfirm"
      @cancel="confirmState.open = false"
    />
  </div>
</template>
