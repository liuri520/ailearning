<script setup>
/**
 * MediaPicker.vue —— 媒体库选择器（弹窗）
 *
 * 内容编辑页选配图时用。三种来源在一处收口：
 *   上传（走双尺寸管线）→ 选已有 → 手动填外链。
 * 分开做三个入口，用户就得到三个地方找图。
 */
import { ref, watch } from 'vue'
import { get, post } from '../lib/api.js'
import { toastError } from '../lib/store.js'
import { prepareImage, formatSize } from '../lib/image.js'
import SmartImage from './SmartImage.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** 服务端下发的处理参数（来自 site.meta） */
  thumbEdge: { type: Number, default: 800 },
  fullEdge: { type: Number, default: 2560 },
  maxBytes: { type: Number, default: 104857600 },
})

const emit = defineEmits(['select', 'close'])

const tab = ref('library')
const rows = ref([])
const loading = ref(false)
const uploading = ref(false)
const uploadProgress = ref('')
const fileInput = ref(null)
const externalUrl = ref('')
const isOver = ref(false)

const selectedId = ref(null)

watch(() => props.open, (open) => {
  if (open) {
    tab.value = 'library'
    selectedId.value = null
    externalUrl.value = ''
    load()
  }
})

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.asset.list', { page: 1, per_page: 60 })
    rows.value = data.rows || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

/** 上传：一条文件 → 两个 blob → 一个 multipart 请求 */
async function handleFiles(fileList) {
  const files = [...(fileList || [])]
  if (!files.length || uploading.value) return

  const file = files[0]

  if (file.size > props.maxBytes) {
    toastError(new Error(`文件超过上限 ${formatSize(props.maxBytes)}，请先压缩`))
    return
  }

  uploading.value = true
  uploadProgress.value = '正在处理图片…'

  try {
    const prepared = await prepareImage(file, {
      thumbEdge: props.thumbEdge,
      fullEdge: props.fullEdge,
    })

    if (prepared.gifPassthrough) {
      uploadProgress.value = 'GIF 将原样上传（不会清理元数据）…'
    } else {
      const saved = prepared.originalSize - prepared.compressedSize
      uploadProgress.value = `压缩 ${formatSize(prepared.originalSize)} → ${formatSize(prepared.compressedSize)}`
        + (saved > 0 ? `（省 ${formatSize(saved)}）` : '')
        + '，正在上传…'
    }

    const form = new FormData()
    form.append('full', prepared.full)
    form.append('thumb', prepared.thumb)
    form.append('width', String(prepared.width))
    form.append('height', String(prepared.height))
    form.append('thumb_width', String(prepared.thumbWidth))
    form.append('thumb_height', String(prepared.thumbHeight))

    const { data } = await post('admin.asset.upload', null, { formData: form })

    emit('select', data)
    emit('close')
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

async function addExternal() {
  const url = externalUrl.value.trim()
  if (!url) return

  try {
    const { data } = await post('admin.asset.add', { url })
    // 登记完再取一次详情以便回传完整记录
    const list = await get('admin.asset.list', { q: url, per_page: 5 })
    const found = (list.data.rows || []).find((a) => a.id === data.id)
    emit('select', found || { id: data.id, url, thumb_url: url })
    emit('close')
  } catch (err) {
    toastError(err)
  }
}

function confirmSelection() {
  const picked = rows.value.find((a) => a.id === selectedId.value)
  if (picked) {
    emit('select', picked)
    emit('close')
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click.self="emit('close')">
      <div class="modal modal--wide" role="dialog" aria-modal="true" aria-label="选择图片">
        <div class="modal__head">
          <span>选择图片</span>
          <span style="margin-left:auto;display:flex;gap:var(--space-2)">
            <button
              class="btn btn-sm"
              :class="{ 'btn-primary': tab === 'library' }"
              type="button"
              @click="tab = 'library'"
            >媒体库</button>
            <button
              class="btn btn-sm"
              :class="{ 'btn-primary': tab === 'upload' }"
              type="button"
              @click="tab = 'upload'"
            >上传</button>
            <button
              class="btn btn-sm"
              :class="{ 'btn-primary': tab === 'external' }"
              type="button"
              @click="tab = 'external'"
            >外链</button>
          </span>
        </div>

        <div class="modal__body">
          <!-- 媒体库 -->
          <template v-if="tab === 'library'">
            <div v-if="loading" class="media-grid">
              <div v-for="n in 8" :key="n" class="skeleton" style="aspect-ratio:4/3" />
            </div>

            <div v-else-if="!rows.length" class="empty">媒体库还是空的，先上传一张吧</div>

            <div v-else class="media-grid">
              <div
                v-for="asset in rows"
                :key="asset.id"
                class="media-card"
                :class="{ 'is-selected': selectedId === asset.id }"
              >
                <div class="media-card__thumb" @click="selectedId = asset.id">
                  <SmartImage
                    :src="asset.url"
                    :thumb-src="asset.thumb_url"
                    use-thumb
                    placeholder="不可用"
                    :width="4"
                    :height="3"
                  />
                  <span
                    v-if="asset.check_status !== 'ok'"
                    class="media-card__status badge"
                    :class="asset.check_status === 'unknown' ? 'badge-warning' : 'badge-danger'"
                  >{{ asset.check_status === 'unknown' ? '未知' : '失效' }}</span>
                </div>
                <div class="media-card__body">
                  <span class="media-card__name">{{ asset.remote_name || asset.rel_path }}</span>
                  <span class="faint">
                    {{ asset.width && asset.height ? `${asset.width}×${asset.height}` : '尺寸未知' }}
                    <template v-if="!asset.has_thumb"> · 无缩略版</template>
                  </span>
                </div>
              </div>
            </div>
          </template>

          <!-- 上传 -->
          <template v-else-if="tab === 'upload'">
            <div
              class="dropzone"
              :class="{ 'is-over': isOver }"
              @click="fileInput?.click()"
              @dragover.prevent="isOver = true"
              @dragleave.prevent="isOver = false"
              @drop.prevent="onDrop"
            >
              <template v-if="uploading">
                {{ uploadProgress || '上传中…' }}
              </template>
              <template v-else>
                <p style="margin:0 0 var(--space-2)">点击选择，或把图片拖到这里</p>
                <p class="tiny faint" style="margin:0">
                  会自动生成 {{ thumbEdge }}px 缩略图与 {{ fullEdge }}px 原图，并清除拍摄位置等元数据
                </p>
                <p class="tiny faint" style="margin:var(--space-2) 0 0">
                  上限 {{ formatSize(maxBytes) }}；GIF 会原样上传、不清理元数据
                </p>
              </template>
            </div>

            <input
              ref="fileInput"
              type="file"
              accept="image/*"
              class="sr-only"
              @change="handleFiles($event.target.files)"
            >
          </template>

          <!-- 外链 -->
          <template v-else>
            <div class="form__row">
              <label class="form__label" for="ext-url">图片地址（必须 https）</label>
              <input id="ext-url" v-model="externalUrl" type="url" placeholder="https://…">
              <p class="form__hint">
                外链图片<b>不会被清理元数据</b>，也不会生成缩略图。
                如果是别人拍的照片，请先自行确认其中不含位置信息。
              </p>
              <p class="form__hint">
                该域名需要出现在「设置 → 图床域名白名单」里，否则会被 CSP 拦下、显示不出来。
              </p>
            </div>
            <button class="btn btn-primary" type="button" :disabled="!externalUrl.trim()" @click="addExternal">
              登记并选用
            </button>
          </template>
        </div>

        <div class="modal__foot">
          <button class="btn" type="button" @click="emit('close')">取消</button>
          <button
            v-if="tab === 'library'"
            class="btn btn-primary"
            type="button"
            :disabled="!selectedId"
            @click="confirmSelection"
          >选用</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
