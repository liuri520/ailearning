<script setup>
/**
 * HomeLayout.vue —— 首页区块编排
 *
 * 【首页是可拼装的，不是写死的】首页长什么样完全由 home_blocks 决定：
 * 增删、改顺序、开关、每块的条数与文案。首页改版不必动代码、不必重新构建前端 ——
 * 这是把「我想在首页加个图集」从一次发版变成一个勾选的全部意义。
 *
 * 【排序是即时保存的】拖动结束就提交，没有「保存排序」按钮 ——
 * 让用户记得再点一次保存，等于给了他一个静默丢失的机会。
 */
import { ref, onMounted } from 'vue'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const blocks = ref([])
const blockTypes = ref([])
const loading = ref(true)

const editing = ref(null)
const saving = ref(false)
const dragIndex = ref(-1)
const overIndex = ref(-1)
const confirmState = ref({ open: false, title: '', message: '', onConfirm: null })

/** 每类区块的说明、默认标题、可配字段 —— 新增区块时前端要按它渲染表单 */
const META = {
  banner:          { label: '横幅',     defaults: '公告 / 站点标语', hint: '首页顶部的欢迎区，直接读站点配置里的站名与描述。', fields: ['title', 'subtitle'] },
  featured:        { label: '精选',     defaults: '精选内容',        hint: '挑出被标记为「精选」的内容。',                 fields: ['limit'] },
  recent_article:  { label: '最新文章', defaults: '最新文章',        hint: '按发布时间倒序。',                            fields: ['limit'] },
  recent_video:    { label: '最新视频', defaults: '最新视频',        hint: '按发布时间倒序。',                            fields: ['limit'] },
  gallery:         { label: '图集',     defaults: '随手拍',          hint: '最新的图片内容，横向铺开。',                   fields: ['limit'] },
  tag_cloud:       { label: '标签云',   defaults: '标签',            hint: '按使用量排序，字号随数量变化。',               fields: ['limit'] },
  about:           { label: '关于',     defaults: '关于本站',        hint: '纯文字区块，支持 Markdown。',                  fields: ['text'] },
}

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.home.get')
    blocks.value = data.blocks || []
    blockTypes.value = data.block_types || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

function metaOf(type) {
  return META[type] || { label: type, defaults: type, hint: '', fields: ['limit'] }
}

function startCreate() {
  const type = blockTypes.value[0] || 'recent_article'
  editing.value = blank(type)
}

function blank(type) {
  const m = metaOf(type)
  const config = {}
  if (m.fields.includes('limit')) config.limit = 6
  if (m.fields.includes('title')) config.title = ''
  if (m.fields.includes('subtitle')) config.subtitle = ''
  if (m.fields.includes('text')) config.text = ''

  return {
    id: null,
    block_type: type,
    title: m.defaults,
    config,
    sort_weight: 0,
    is_enabled: true,
  }
}

function startEdit(block) {
  // 深拷一层，取消编辑时不会污染列表里的原始对象
  editing.value = {
    ...block,
    config: { ...(block.config || {}) },
  }
}

/** 切换类型时重置 config，避免残留上一个类型的字段（比如 about 的 text 跑到 gallery 里） */
function onTypeChange(type) {
  const m = metaOf(type)
  editing.value = { ...blank(type), id: editing.value.id, title: editing.value.title || m.defaults }
}

async function submit() {
  const draft = editing.value
  if (!draft || saving.value) return

  saving.value = true
  try {
    await post('admin.home.block.save', {
      id: draft.id,
      block_type: draft.block_type,
      title: draft.title,
      config: draft.config,
      is_enabled: draft.is_enabled ? 1 : 0,
      sort_weight: draft.sort_weight,
    })
    toast(draft.id ? '区块已更新' : '区块已添加', 'success')
    editing.value = null
    load()
  } catch (err) {
    toastError(err)
  } finally {
    saving.value = false
  }
}

async function toggleEnabled(block) {
  try {
    await post('admin.home.block.save', {
      id: block.id,
      block_type: block.block_type,
      title: block.title,
      config: block.config || {},
      is_enabled: block.is_enabled ? 0 : 1,
      sort_weight: block.sort_weight,
    })
    block.is_enabled = !block.is_enabled
    toast(block.is_enabled ? '已显示' : '已隐藏', 'success')
  } catch (err) {
    toastError(err)
    load()
  }
}

function askDelete(block) {
  confirmState.value = {
    open: true,
    title: '删除区块',
    message: `确定删除「${block.title || metaOf(block.block_type).label}」？只影响首页展示，内容本身不受影响。`,
    onConfirm: async () => {
      try {
        await post('admin.home.block.delete', { id: block.id })
        toast('区块已删除', 'success')
        load()
      } catch (err) { toastError(err) }
    },
  }
}

// ── 拖拽排序 ────────────────────────────────────────────────
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

  const next = [...blocks.value]
  const [moved] = next.splice(from, 1)
  next.splice(to, 0, moved)
  blocks.value = next

  try {
    await post('admin.home.reorder', { ids: next.map((b) => b.id) })
  } catch (err) {
    toastError(err)
    load()   // 失败就回到服务端顺序，别留一个假顺序
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
      <h1 style="margin:0;font-size:1.125rem">首页布局</h1>
      <a href="./" target="_blank" rel="noopener" class="btn btn-sm">看首页</a>
      <button class="btn btn-primary btn-sm" style="margin-left:auto" type="button" @click="startCreate">
        添加区块
      </button>
    </div>

    <p class="form__hint" style="margin-bottom:var(--space-4)">
      从上到下就是首页的实际顺序。隐藏的区块不显示但保留配置，改版时不用重新配一遍。
    </p>

    <div v-if="loading" class="skeleton" style="height: 20rem" />

    <div v-else-if="!blocks.length" class="empty">
      首页还是空的。添加一个「最新文章」区块就能立刻有内容。
    </div>

    <div v-else class="block-list">
      <div
        v-for="(block, index) in blocks"
        :key="block.id"
        class="block-row"
        :class="{
          'is-disabled': !block.is_enabled,
          'is-dragging': dragIndex === index,
          'is-drop-target': overIndex === index && dragIndex !== index,
        }"
        @dragover.prevent="onDragOver(index)"
        @drop.prevent="onDrop"
      >
        <span
          class="block-row__handle"
          draggable="true"
          title="拖动调整顺序"
          @dragstart="onDragStart(index)"
          @dragend="dragIndex = -1; overIndex = -1"
        >⋮⋮</span>

        <div class="block-row__title">
          <div>
            <span class="badge badge-accent">{{ metaOf(block.block_type).label }}</span>
            <strong style="margin-left:var(--space-2)">{{ block.title || metaOf(block.block_type).defaults }}</strong>
            <span v-if="!block.is_enabled" class="badge" style="margin-left:var(--space-2)">已隐藏</span>
          </div>

          <p class="tiny faint" style="margin:var(--space-1) 0 0">
            {{ metaOf(block.block_type).hint }}
            <template v-if="block.config.limit">每页 {{ block.config.limit }} 条。</template>
            <template v-if="block.config.subtitle">副标题「{{ block.config.subtitle }}」。</template>
          </p>
        </div>

        <div class="block-row__actions">
          <button class="btn btn-sm btn-ghost" type="button" @click="toggleEnabled(block)">
            {{ block.is_enabled ? '隐藏' : '显示' }}
          </button>
          <button class="btn btn-sm btn-ghost" type="button" @click="startEdit(block)">编辑</button>
          <button class="btn btn-sm btn-ghost" style="color:var(--danger)" type="button" @click="askDelete(block)">
            删除
          </button>
        </div>
      </div>
    </div>

    <!-- 编辑面板 -->
    <div v-if="editing" class="card" style="margin-top:var(--space-4)">
      <h2 style="margin-top:0;font-size:0.95rem">
        {{ editing.id ? '编辑区块' : '添加区块' }}
      </h2>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="b-type">类型</label>
          <select
            id="b-type"
            :value="editing.block_type"
            :disabled="!!editing.id"
            @change="onTypeChange($event.target.value)"
          >
            <option v-for="type in blockTypes" :key="type" :value="type">
              {{ metaOf(type).label }}
            </option>
          </select>
          <p v-if="editing.id" class="form__hint">已有区块不能改类型（改类型等于换一个区块，直接新建更清楚）</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="b-title">标题</label>
          <input id="b-title" v-model="editing.title" type="text" maxlength="128" :placeholder="metaOf(editing.block_type).defaults">
        </div>
      </div>

      <p class="form__hint">{{ metaOf(editing.block_type).hint }}</p>

      <!-- 按类型渲染可配字段 -->
      <div v-if="metaOf(editing.block_type).fields.includes('limit')" class="form__row">
        <label class="form__label" for="b-limit">显示条数</label>
        <input id="b-limit" v-model.number="editing.config.limit" type="number" min="1" max="24" style="width:8rem">
      </div>

      <template v-if="metaOf(editing.block_type).fields.includes('title')">
        <div class="form__row">
          <label class="form__label" for="b-banner-title">横幅主标题</label>
          <input id="b-banner-title" v-model="editing.config.title" type="text" maxlength="128" placeholder="留空则用站点名称">
        </div>
        <div class="form__row">
          <label class="form__label" for="b-banner-sub">横幅副标题</label>
          <input id="b-banner-sub" v-model="editing.config.subtitle" type="text" maxlength="255" placeholder="一句话介绍，可留空">
        </div>
      </template>

      <div v-if="metaOf(editing.block_type).fields.includes('text')" class="form__row">
        <label class="form__label" for="b-text">正文（支持 Markdown）</label>
        <textarea id="b-text" v-model="editing.config.text" class="mono" rows="8" />
      </div>

      <label class="checkbox" style="margin-bottom:var(--space-4)">
        <input v-model="editing.is_enabled" type="checkbox">
        <span>在首页显示</span>
      </label>

      <div style="display:flex;gap:var(--space-2)">
        <button class="btn btn-primary btn-sm" type="button" :disabled="saving" @click="submit">
          {{ saving ? '保存中…' : '保存' }}
        </button>
        <button class="btn btn-sm" type="button" @click="editing = null">取消</button>
      </div>
    </div>

    <ConfirmDialog
      :open="confirmState.open"
      :title="confirmState.title"
      :message="confirmState.message"
      variant="danger"
      confirm-text="确认删除"
      @confirm="runConfirm"
      @cancel="confirmState.open = false"
    />
  </div>
</template>
