<script setup>
/**
 * Media.vue —— 媒体库
 *
 * 【三个「删除」必须说清区别，否则用户一定点错】
 *   1. 恢复直链（relink）：不重传，用已有的 UKEY 重新换一条直链。坏了先点这个。
 *   2. 释放图床文件（revoke）：把图床上的文件真正删掉、腾出空间，**不可逆**。
 *   3. 移除记录（delete）：只删本地这一行，图床上的文件留着不管。
 * 界面上按这个顺序排，并各自写明后果。
 *
 * 【引用计数是删除的闸门】ref_count > 0 的资产不允许释放/移除，
 * 否则线上文章会突然变成一堆裂图（SPEC §7.3）。
 */
import { ref, computed, onMounted, watch } from 'vue'
import { get, post } from '../lib/api.js'
import { toast, toastError, site } from '../lib/store.js'
import { formatDateTime, CHECK_LABEL } from '../lib/format.js'
import { prepareImage, formatSize } from '../lib/image.js'
import SmartImage from '../components/SmartImage.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const rows = ref([])
const meta = ref({ total: 0, page: 1, per_page: 24 })
const loading = ref(true)
const selected = ref(new Set())

const filters = ref({
  q: '',
  provider: '',
  check_status: '',
  orphan: false,
  no_thumb: false,
})

const uploading = ref(false)
const uploadProgress = ref('')
const isOver = ref(false)
const fileInput = ref(null)

const syncResult = ref(null)
const confirmState = ref({ open: false, title: '', message: '', variant: 'danger', onConfirm: null })

const allSelected = computed(() => rows.value.length > 0 && selected.value.size === rows.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(meta.value.total / (meta.value.per_page || 24))))

onMounted(load)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await get('admin.asset.list', {
      ...filters.value,
      orphan: filters.value.orphan ? 1 : '',
      no_thumb: filters.value.no_thumb ? 1 : '',
      page,
      per_page: 24,
    })
    rows.value = data.rows || []
    meta.value = { total: Number(data.total) || 0, page: Number(data.page) || page, per_page: Number(data.per_page) || 24 }
    selected.value = new Set()
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

watch(() => [filters.value.provider, filters.value.check_status, filters.value.orphan, filters.value.no_thumb], () => load(1))

function search() {
  load(1)
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

// ── 上传 ────────────────────────────────────────────────────
async function handleFiles(fileList) {
  const files = [...(fileList || [])]
  if (!files.length || uploading.value) return

  const file = files[0]
  const maxBytes = site.data.max_upload_bytes || 104857600

  if (file.size > maxBytes) {
    toastError(new Error(`文件超过上限 ${formatSize(maxBytes)}，请先压缩`))
    return
  }

  uploading.value = true
  try {
    uploadProgress.value = '正在压缩并清理元数据…'

    const prepared = await prepareImage(file, {
      thumbEdge: site.data.image_thumb_edge,
      fullEdge: site.data.image_full_edge,
    })

    uploadProgress.value = prepared.gifPassthrough
      ? 'GIF 原样上传中…'
      : `上传中…（${formatSize(prepared.compressedSize)}）`

    const form = new FormData()
    form.append('full', prepared.full)
    form.append('thumb', prepared.thumb)
    form.append('width', String(prepared.width))
    form.append('height', String(prepared.height))
    form.append('thumb_width', String(prepared.thumbWidth))
    form.append('thumb_height', String(prepared.thumbHeight))

    await post('admin.asset.upload', null, { formData: form })

    toast('上传完成', 'success')
    load(1)
  } catch (err) {
    toastError(err)
  } finally {
    uploading.value = false
    uploadProgress.value = ''
    if (fileInput.value) fileInput.value.value = ''
  }
}

function onDrop(event) {
  isOver.value = false
  handleFiles(event.dataTransfer?.files)
}

// ── 单条操作 ────────────────────────────────────────────────
async function recheck(row) {
  try {
    const { data } = await post('admin.asset.recheck', { id: row.id })
    toast(
      data.status === 'ok' ? '链接正常' : `复检结果：${data.status}${data.error ? ' · ' + data.error : ''}`,
      data.status === 'ok' ? 'success' : 'error'
    )
    load(meta.value.page)
  } catch (err) { toastError(err) }
}

/**
 * 单条重建直链。走的是和批量同一个接口 —— 服务端按 ids 处理，
 * 传一个 id 也一样，没必要为「只修一条」单开一个 action。
 */
async function relinkOne(row) {
  try {
    const { data } = await post('admin.asset.relink', { ids: [row.id] })
    if ((data.failed || []).length) {
      toast('重建失败：该资产没有可用的 UKEY，或图床拒绝了请求', 'error')
    } else {
      toast('直链已重建', 'success')
    }
    load(meta.value.page)
  } catch (err) { toastError(err) }
}

async function revoke(row) {
  askConfirm(
    '释放图床文件',
    `将把图床上的文件真正删除以释放空间，本机记录同时移除。此操作不可逆，外链若已流出去会立刻失效。`,
    async () => {
      try {
        await post('admin.asset.revoke', { id: row.id })
        toast('已释放图床空间', 'success')
        load(meta.value.page)
      } catch (err) { toastError(err) }
    }
  )
}

async function removeRow(row) {
  askConfirm(
    '仅移除本地记录',
    '图床上的文件会保留（继续占空间），只是这里不再管理它。之后想找回来只能去钛盘后台翻。',
    async () => {
      try {
        await post('admin.asset.delete', { id: row.id })
        toast('已移除记录', 'success')
        load(meta.value.page)
      } catch (err) { toastError(err) }
    },
    'warning'
  )
}

// ── 批量操作 ────────────────────────────────────────────────
async function batchRelink() {
  const ids = [...selected.value]
  if (!ids.length) return

  try {
    const { data } = await post('admin.asset.relink', { ids })
    const failed = (data.failed || []).length
    toast(
      failed ? `重建完成：成功 ${data.ok} 条，失败 ${failed} 条` : `已重建 ${data.ok} 条直链`,
      failed ? 'error' : 'success'
    )
    load(meta.value.page)
  } catch (err) { toastError(err) }
}

async function batchRevoke() {
  const ids = [...selected.value]
  if (!ids.length) return

  askConfirm(
    '批量释放图床空间',
    `将真正删除图床上的 ${ids.length} 个文件。被内容引用的会被服务端拒绝，不会被误删。此操作不可逆。`,
    async () => {
      let ok = 0
      const failed = []
      for (const id of ids) {
        try {
          await post('admin.asset.revoke', { id })
          ok++
        } catch (err) {
          failed.push(err.message)
        }
      }
      toast(
        failed.length ? `成功 ${ok} 条，失败 ${failed.length} 条：${failed[0]}` : `已释放 ${ok} 条`,
        failed.length ? 'error' : 'success'
      )
      load(meta.value.page)
    }
  )
}

// ── 与图床对照 ──────────────────────────────────────────────
async function runSync() {
  loading.value = true
  try {
    const { data } = await post('admin.asset.sync')
    syncResult.value = data
    toast(
      (data.missing_local?.length || 0) > 0
        ? `发现 ${data.missing_local.length} 个直链在本地没有记录`
        : '本地记录与图床一致',
      (data.missing_local?.length || 0) > 0 ? 'error' : 'success'
    )
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

const CHECK_CLASS = {
  ok: 'badge-success',
  missing: 'badge-danger',
  expired: 'badge-danger',
  unknown: 'badge-warning',
}
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">媒体库</h1>
      <span class="faint tiny">共 {{ meta.total }} 个</span>

      <span style="margin-left:auto;display:flex;gap:var(--space-2)">
        <button class="btn btn-sm" type="button" @click="runSync">与图床对照</button>
        <button class="btn btn-primary btn-sm" type="button" @click="fileInput?.click()">上传图片</button>
      </span>
    </div>

    <input ref="fileInput" type="file" accept="image/*" class="sr-only" @change="handleFiles($event.target.files)">

    <!-- 拖放上传区：平时收成一条窄带，不抢列表的位置 -->
    <div
      class="dropzone dropzone--slim"
      :class="{ 'is-over': isOver }"
      style="margin-bottom:var(--space-4)"
      @click="fileInput?.click()"
      @dragover.prevent="isOver = true"
      @dragleave.prevent="isOver = false"
      @drop.prevent="onDrop"
    >
      <template v-if="uploading">{{ uploadProgress || '上传中…' }}</template>
      <template v-else>
        拖图片到这里上传 —— 自动生成缩略图与原图两个版本，并清除拍摄位置等元数据
      </template>
    </div>

    <div v-if="syncResult" class="alert" :class="(syncResult.missing_local?.length || syncResult.orphan_remote?.length) ? 'alert--warning' : 'alert--info'" style="margin-bottom:var(--space-4)">
      <div>
        <p style="margin:0">
          图床共 {{ syncResult.remote_total }} 个直链；
          本地缺失 {{ syncResult.missing_local?.length || 0 }} 个；
          图床孤立 {{ syncResult.orphan_remote?.length || 0 }} 个。
        </p>
        <p class="tiny" style="margin:var(--space-1) 0 0">
          本地缺失 = <b>库里记着、图床上已经没了</b>（直链被撤或过期）。这类条目如果还留着重传凭据，
          可以在列表里点「重新关联」补回，不必重传。
          图床孤立 = <b>图床上有、这边没有任何记录</b>（多半是你在别处上传的文件，或数据库回滚过）。
          这里只做展示，<b>不会自动清理</b> —— 删了就真没了。
        </p>
      </div>
      <span class="alert__actions">
        <button class="btn btn-sm btn-ghost" type="button" @click="syncResult = null">关闭</button>
      </span>
    </div>

    <form class="filters" @submit.prevent="search">
      <div class="filters__search">
        <input v-model="filters.q" type="search" placeholder="搜索文件名或路径…" aria-label="搜索媒体">
      </div>

      <select v-model="filters.provider" style="width:auto">
        <option value="">全部来源</option>
        <option value="ttttt">钛盘</option>
        <option value="manual">外部链接</option>
      </select>

      <select v-model="filters.check_status" style="width:auto">
        <option value="">全部状态</option>
        <option value="ok">正常</option>
        <option value="unknown">未知</option>
        <option value="missing">失效</option>
        <option value="expired">已过期</option>
      </select>

      <label class="checkbox">
        <input v-model="filters.orphan" type="checkbox">
        <span>只看孤立资源</span>
      </label>

      <label class="checkbox">
        <input v-model="filters.no_thumb" type="checkbox">
        <span>只看无缩略图</span>
      </label>

      <button class="btn btn-primary btn-sm" type="submit">筛选</button>
    </form>

    <!-- 批量条 -->
    <div v-if="selected.size" class="alert alert--info" style="margin-bottom:var(--space-3)">
      <span>已选择 {{ selected.size }} 个</span>
      <span class="alert__actions">
        <button class="btn btn-sm" type="button" @click="batchRelink">重建直链</button>
        <button class="btn btn-sm btn-danger" type="button" @click="batchRevoke">批量释放图床空间</button>
      </span>
    </div>

    <div v-if="loading" class="media-grid">
      <div v-for="n in 8" :key="n" class="skeleton" style="aspect-ratio:4/3" />
    </div>

    <div v-else-if="!rows.length" class="empty">没有符合条件的图片</div>

    <template v-else>
      <div class="media-grid">
        <div
          v-for="asset in rows"
          :key="asset.id"
          class="media-card"
          :class="{ 'is-selected': selected.has(asset.id) }"
        >
          <div class="media-card__thumb" @click="toggleOne(asset.id)">
            <SmartImage
              :src="asset.url"
              :thumb-src="asset.thumb_url"
              use-thumb
              placeholder="链接不可用"
              :width="4"
              :height="3"
            />
            <span class="media-card__status badge" :class="CHECK_CLASS[asset.check_status] || 'badge-warning'">
              {{ CHECK_LABEL[asset.check_status] || asset.check_status }}
            </span>
            <span v-if="selected.has(asset.id)" class="media-card__check">✓</span>
          </div>

          <div class="media-card__body">
            <span class="media-card__name" :title="asset.remote_name || asset.rel_path">
              {{ asset.remote_name || asset.rel_path }}
            </span>

            <span class="faint tiny">
              {{ asset.width && asset.height ? `${asset.width}×${asset.height}` : '尺寸未知' }}
              <template v-if="asset.file_size"> · {{ formatSize(asset.file_size) }}</template>
              <template v-if="!asset.has_thumb"> · 无缩略版</template>
            </span>

            <span class="tiny">
              <span class="badge" :class="asset.ref_count > 0 ? 'badge-success' : 'badge-warning'">
                {{ asset.ref_count > 0 ? `被引用 ${asset.ref_count} 次` : '孤立' }}
              </span>
              <span v-if="asset.storage_model !== 99" class="badge badge-danger" style="margin-left:4px" title="非永久模式，图可能已消失">
                {{ asset.storage_model }}
              </span>
            </span>

            <span class="faint tiny">{{ formatDateTime(asset.created_at) }}</span>

            <span class="media-card__actions">
              <button class="btn btn-sm btn-ghost" type="button" @click="recheck(asset)">复检</button>
              <button class="btn btn-sm btn-ghost" type="button" @click="relinkOne(asset)">重建直链</button>
              <button class="btn btn-sm btn-ghost" type="button" @click="revoke(asset)">释放</button>
              <button class="btn btn-sm btn-ghost" type="button" @click="removeRow(asset)">移除</button>
            </span>
          </div>
        </div>
      </div>

      <div v-if="totalPages > 1" class="pager">
        <button class="btn btn-sm" type="button" :disabled="meta.page <= 1" @click="load(meta.page - 1)">上一页</button>
        <span class="pager__info">第 {{ meta.page }} / {{ totalPages }} 页</span>
        <button class="btn btn-sm" type="button" :disabled="meta.page >= totalPages" @click="load(meta.page + 1)">下一页</button>
      </div>
    </template>

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
