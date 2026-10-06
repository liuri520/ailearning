<script setup>
/**
 * Tags.vue —— 标签管理
 *
 * 【删除是两段式的】服务端 admin.tag.delete 在没有 confirm 标志时**不删**，
 * 只回报「会影响 N 篇内容」。前端拿到这个数字再弹确认框，用户看到的是
 * 具体影响面而不是一句干巴巴的「确定删除吗」。确认后带 confirm 重发。
 *
 * 【合并是用来纠错的】写久了必然出现「Vue」「vue」「Vue.js」这种近义标签。
 * 逐个改名不行（目标名已存在会撞唯一键），必须有一个「把这个并进那个」的动作。
 */
import { ref, computed, onMounted } from 'vue'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import { formatDate } from '../lib/format.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const rows = ref([])
const loading = ref(true)
const keyword = ref('')

const editing = ref(null)         // { id, name, slug } 或 null
const saving = ref(false)

const confirmState = ref({ open: false, title: '', message: '', variant: 'danger', onConfirm: null })
const mergeFrom = ref(null)

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) return rows.value
  return rows.value.filter((t) => t.name.toLowerCase().includes(kw) || t.slug.toLowerCase().includes(kw))
})

const sortMode = ref('name')   // name / count

const sorted = computed(() => {
  const list = [...filtered.value]
  if (sortMode.value === 'count') {
    list.sort((a, b) => Number(b.count) - Number(a.count) || a.name.localeCompare(b.name))
  } else {
    list.sort((a, b) => a.name.localeCompare(b.name, 'zh'))
  }
  return list
})

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.tag.list')
    // count 是 SQL 聚合出来的字符串，转成数字，否则排序会按字符串比
    rows.value = (data || []).map((t) => ({ ...t, count: Number(t.count) }))
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

function startCreate() {
  editing.value = { id: null, name: '', slug: '' }
}

function startEdit(row) {
  editing.value = { id: row.id, name: row.name, slug: row.slug }
}

async function submitEdit() {
  const draft = editing.value
  if (!draft || !draft.name.trim() || saving.value) return

  saving.value = true
  try {
    await post('admin.tag.save', {
      id: draft.id,
      name: draft.name.trim(),
      slug: draft.slug.trim(),
    })
    toast(draft.id ? '标签已更新' : '标签已创建', 'success')
    editing.value = null
    load()
  } catch (err) {
    toastError(err)
  } finally {
    saving.value = false
  }
}

async function askDelete(row) {
  // 先问服务端影响面，再决定这句话怎么说
  try {
    const { data } = await post('admin.tag.delete', { id: row.id })

    if (data.deleted) {
      // 极少数情况：本来就没人用，服务端直接删了
      toast('标签已删除', 'success')
      load()
      return
    }

    confirmState.value = {
      open: true,
      title: '删除标签',
      message: data.affected > 0
        ? `「${row.name}」正被 ${data.affected} 篇内容使用。删除只会解除关联，内容本身不受影响。`
        : `确定删除「${row.name}」？`,
      variant: 'danger',
      onConfirm: async () => {
        try {
          await post('admin.tag.delete', { id: row.id, confirm: true })
          toast('标签已删除', 'success')
          load()
        } catch (err) { toastError(err) }
      },
    }
  } catch (err) {
    toastError(err)
  }
}

function askMerge(row) {
  mergeFrom.value = row
}

async function runMerge(target) {
  const from = mergeFrom.value
  if (!from || from.id === target.id) return

  confirmState.value = {
    open: true,
    title: '合并标签',
    message: `「${from.name}」下的所有内容会改为挂在「${target.name}」下，「${from.name}」随后被删除。此操作不可撤销。`,
    variant: 'danger',
    onConfirm: async () => {
      try {
        const { data } = await post('admin.tag.merge', { from_id: from.id, into_id: target.id })
        toast(`已合并，影响 ${data.affected ?? 0} 篇内容`, 'success')
        mergeFrom.value = null
        load()
      } catch (err) { toastError(err) }
    },
  }
}

async function runConfirm() {
  const fn = confirmState.value.onConfirm
  confirmState.value.open = false
  if (fn) await fn()
}
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">标签</h1>
      <span class="faint tiny">共 {{ rows.length }} 个</span>
      <button class="btn btn-primary btn-sm" style="margin-left:auto" type="button" @click="startCreate">
        新建标签
      </button>
    </div>

    <form class="filters" @submit.prevent>
      <div class="filters__search">
        <input v-model="keyword" type="search" placeholder="搜索标签…" aria-label="搜索标签">
      </div>
      <select v-model="sortMode" style="width:auto">
        <option value="name">按名称</option>
        <option value="count">按使用量</option>
      </select>
    </form>

    <!-- 编辑/新建表单：内联在列表上方，避免「点新建弹出对话框再找字段」 -->
    <div v-if="editing" class="card" style="margin-bottom:var(--space-4)">
      <h2 style="margin-top:0;font-size:0.95rem">{{ editing.id ? '编辑标签' : '新建标签' }}</h2>
      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="t-name">名称</label>
          <input id="t-name" v-model="editing.name" type="text" maxlength="64" autofocus>
        </div>
        <div class="form__row">
          <label class="form__label" for="t-slug">slug（可空，自动生成）</label>
          <input id="t-slug" v-model="editing.slug" type="text" maxlength="64" placeholder="vue">
          <p class="form__hint">用于前台地址 /#/tag/vue，改名不影响已有链接</p>
        </div>
      </div>
      <div style="display:flex;gap:var(--space-2)">
        <button class="btn btn-primary btn-sm" type="button" :disabled="saving || !editing.name.trim()" @click="submitEdit">
          {{ saving ? '保存中…' : '保存' }}
        </button>
        <button class="btn btn-sm" type="button" @click="editing = null">取消</button>
      </div>
    </div>

    <div v-if="loading" class="skeleton" style="height: 18rem" />

    <div v-else-if="!sorted.length" class="empty">
      {{ keyword ? '没有匹配的标签' : '还没有标签。写内容时直接输入就会自动创建。' }}
    </div>

    <div v-else class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>名称</th>
            <th style="width:12rem">slug</th>
            <th style="width:6rem">内容数</th>
            <th style="width:10rem">创建时间</th>
            <th style="width:14rem">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sorted" :key="row.id">
            <td>
              <!-- 普通链接而不是 RouterLink：前台是另一个 SPA，后台路由里没有 tag 这条规则 -->
              <a :href="`./#/tag/${row.slug}`" target="_blank" rel="noopener">{{ row.name }}</a>
            </td>
            <td class="mono tiny">{{ row.slug }}</td>
            <td>
              <span class="badge" :class="row.count === 0 ? 'badge-warning' : ''">{{ row.count }}</span>
            </td>
            <td class="faint tiny">{{ formatDate(row.created_at) }}</td>
            <td class="col-actions">
              <button class="btn btn-sm btn-ghost" type="button" @click="startEdit(row)">编辑</button>
              <button class="btn btn-sm btn-ghost" type="button" @click="askMerge(row)">合并到…</button>
              <button class="btn btn-sm btn-ghost" style="color:var(--danger)" type="button" @click="askDelete(row)">
                删除
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 合并目标选择 -->
    <div v-if="mergeFrom" class="card" style="margin-top:var(--space-4)">
      <h2 style="margin-top:0;font-size:0.95rem">
        把「{{ mergeFrom.name }}」合并到：
      </h2>
      <div class="tag-merge-list">
        <button
          v-for="t in sorted.filter((t) => t.id !== mergeFrom.id)"
          :key="t.id"
          class="btn btn-sm"
          type="button"
          @click="runMerge(t)"
        >
          {{ t.name }} <span class="faint">（{{ t.count }}）</span>
        </button>
      </div>
      <button class="btn btn-sm btn-ghost" type="button" style="margin-top:var(--space-3)" @click="mergeFrom = null">
        取消
      </button>
    </div>

    <ConfirmDialog
      :open="confirmState.open"
      :title="confirmState.title"
      :message="confirmState.message"
      :variant="confirmState.variant"
      confirm-text="确认"
      @confirm="runConfirm"
      @cancel="confirmState.open = false"
    />
  </div>
</template>
