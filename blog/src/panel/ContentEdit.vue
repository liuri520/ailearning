<script setup>
/**
 * ContentEdit.vue —— 内容编辑器（文章 / 视频 / 图片共用）
 *
 * 【为什么三种类型共用一个组件】它们在数据库里是同一张 contents 表的三种
 * 扩展行，公共字段（标题/摘要/标签/状态/发布时间）完全一致。拆成三个页面
 * 就等于把同一份表单校验和同一套保存逻辑抄三遍 —— 改一处必然漏两处。
 * 这里只把「类型专属区」按 type 分支渲染。
 *
 * 【草稿自动保存：不做】定时器 + 并发写会让「我明明改过」和「服务端到底是
 * 哪一版」变成说不清的问题。取而代之的是离开前提醒未保存（SPEC 未要求自动保存）。
 */
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import { renderMarkdown } from '../lib/markdown.js'
import { formatDuration, parseDate } from '../lib/format.js'
import TagInput from '../components/TagInput.vue'
import MediaPicker from '../components/MediaPicker.vue'
import SmartImage from '../components/SmartImage.vue'

const route = useRoute()
const router = useRouter()

const id = ref(route.params.id ? Number(route.params.id) : null)

const form = ref({
  type: 'article',
  title: '',
  slug: '',
  summary: '',
  cover_path: '',
  status: 'draft',
  published_at: '',
  is_featured: false,
  sort_weight: 0,
  tags: [],
  // article
  body_md: '',
  // video
  source_type: 'embed',
  provider: 'bilibili',
  source_key: '',
  poster_path: '',
  duration_seconds: '',
  aspect_ratio: '16:9',
  // image
  asset_id: null,
  asset_path: '',
  width: null,
  height: null,
  alt_text: '',
  camera_note: '',
})

const tagSuggestions = ref([])
const loading = ref(true)
const saving = ref(false)
const saved = ref(false)          // 保存成功后置位，供「未保存」提醒判断
const slugTouched = ref(false)    // 用户手改过 slug 就不再自动跟随标题
const pane = ref('write')         // write / preview
const pickerFor = ref('')         // cover / poster / asset

onMounted(async () => {
  await Promise.all([loadTags(), load()])
  loading.value = false
  window.addEventListener('beforeunload', warnUnsaved)
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved))

/** 关标签页 / 刷新前提醒。SPA 内部导航不拦（拦了反而让人以为点不动） */
function warnUnsaved(event) {
  if (saving.value || saved.value) return
  event.preventDefault()
  event.returnValue = ''
}

async function loadTags() {
  try {
    const { data } = await get('admin.tag.list')
    tagSuggestions.value = (data || []).map((t) => t.name)
  } catch {
    // 拿不到标签建议不影响写作，静默即可
  }
}

async function load() {
  if (!id.value) {
    // 新建：默认发布时间留空，发布时由服务端填 NOW()
    return
  }

  try {
    const { data } = await get('admin.content.get', { id: id.value })
    slugTouched.value = true   // 已有内容的 slug 不跟随标题改名（改名会写重定向）

    for (const key of Object.keys(form.value)) {
      if (key in data && data[key] !== null) {
        form.value[key] = data[key]
      }
    }
    form.value.tags = (data.tags || []).map((t) => t.name)

    // datetime-local 需要 'YYYY-MM-DDTHH:mm'
    form.value.published_at = toLocalInput(data.published_at)
  } catch (err) {
    toastError(err)
    router.replace({ name: 'panel-contents' })
  }
}

function toLocalInput(value) {
  if (!value) return ''
  const d = parseDate(value)
  if (!d) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

// 标题 → slug 的自动生成（只在用户没手动改过时生效）
watch(() => form.value.title, (title) => {
  if (slugTouched.value || id.value) return
  form.value.slug = slugify(title)
})

function slugify(text) {
  return String(text || '')
    .trim()
    .toLowerCase()
    .replace(/[\s_]+/g, '-')
    .replace(/[^\p{L}\p{N}-]+/gu, '')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 64)
}

const previewHtml = computed(() => (form.value.type === 'article' ? renderMarkdown(form.value.body_md) : ''))

const canSave = computed(() => form.value.title.trim() !== '' && !saving.value)

/** 定时发布 = 状态为已发布 + 发布时间在未来，服务端靠可见性条件自动生效 */
const isScheduled = computed(() => {
  if (form.value.status !== 'published' || !form.value.published_at) return false
  return new Date(form.value.published_at).getTime() > Date.now()
})

async function save(andPublish = false) {
  if (!canSave.value) return

  if (andPublish) {
    form.value.status = 'published'
  }

  // 视频封面必填 —— 服务端也会拦，但在这里先拦能省一次往返
  if (form.value.type === 'video' && !form.value.poster_path) {
    toast('视频封面是必填项（服务器无 FFmpeg，无法自动抽帧）', 'error')
    return
  }
  if (form.value.type === 'image' && !form.value.asset_id) {
    toast('请先选择一张图片', 'error')
    return
  }

  saving.value = true
  try {
    const payload = { ...form.value, id: id.value }
    const { data } = await post('admin.content.save', payload)

    saved.value = true
    if (!id.value) {
      id.value = data.id
      // 换成编辑页的地址：否则再点一次保存会新建出第二条
      router.replace({ name: 'panel-content-edit', params: { id: data.id } })
    }

    toast(isScheduled.value ? '已保存，到点自动发布' : '已保存', 'success')
  } catch (err) {
    toastError(err)
  } finally {
    saving.value = false
  }
}

// ── 媒体选择 ────────────────────────────────────────────────
function onPick(asset) {
  if (pickerFor.value === 'cover') {
    form.value.cover_path = asset.url || ''
  } else if (pickerFor.value === 'poster') {
    form.value.poster_path = asset.url || ''
  } else if (pickerFor.value === 'asset') {
    form.value.asset_id = asset.id
    form.value.asset_path = asset.rel_path || asset.url || ''
    // 宽高来自资产记录：图片详情页要靠它占位，避免布局跳动
    form.value.width = asset.width || form.value.width
    form.value.height = asset.height || form.value.height
  }
  pickerFor.value = ''
}
</script>

<template>
  <div v-if="loading" class="skeleton" style="height: 30rem" />

  <div v-else>
    <div class="editor__head">
      <h1 style="margin:0;font-size:1.125rem">
        {{ id ? '编辑内容' : '新建内容' }}
      </h1>
      <span class="tiny faint" v-if="id">#{{ id }}</span>

      <span style="margin-left:auto;display:flex;gap:var(--space-2);align-items:center">
        <span v-if="isScheduled" class="badge badge-warning">定时发布</span>
        <span v-else-if="form.status === 'published'" class="badge badge-success">已发布</span>
        <span v-else class="badge">草稿</span>

        <RouterLink class="btn btn-sm" :to="{ name: 'panel-contents' }">返回列表</RouterLink>
        <button class="btn btn-sm" type="button" :disabled="!canSave" @click="save(false)">保存</button>
        <button
          v-if="form.status !== 'published'"
          class="btn btn-sm btn-primary"
          type="button"
          :disabled="!canSave"
          @click="save(true)"
        >发布</button>
      </span>
    </div>

    <div class="editor">
      <!-- 左：主体 -->
      <div class="editor__main">
        <div class="form__row">
          <label class="form__label" for="f-title">标题</label>
          <input id="f-title" v-model="form.title" type="text" maxlength="255" placeholder="不超过 255 字">
        </div>

        <!-- 类型：只在新建时可选，已有内容不允许改类型（服务端也会拒绝） -->
        <div class="form__row">
          <label class="form__label">类型</label>
          <div class="form__inline">
            <label v-for="t in [['article','文章'],['video','视频'],['image','图片']]" :key="t[0]" class="radio">
              <input v-model="form.type" type="radio" :value="t[0]" :disabled="!!id">
              <span>{{ t[1] }}</span>
            </label>
          </div>
          <p v-if="id" class="form__hint">已有内容的类型不可更改</p>
        </div>

        <!-- ── 文章正文 ── -->
        <template v-if="form.type === 'article'">
          <div class="form__row">
            <div class="editor__tabs">
              <button
                class="btn btn-sm"
                :class="{ 'btn-primary': pane === 'write' }"
                type="button"
                @click="pane = 'write'"
              >Markdown</button>
              <button
                class="btn btn-sm"
                :class="{ 'btn-primary': pane === 'preview' }"
                type="button"
                @click="pane = 'preview'"
              >预览</button>
            </div>

            <textarea
              v-if="pane === 'write'"
              v-model="form.body_md"
              class="editor__body mono"
              rows="22"
              placeholder="正文用 Markdown 书写…"
            />

            <!-- 预览用的是前台同一套 marked + DOMPurify，所见即所得 -->
            <div v-else class="editor__preview prose" v-html="previewHtml" />
          </div>
        </template>

        <!-- ── 视频 ── -->
        <template v-else-if="form.type === 'video'">
          <div class="form__row">
            <label class="form__label">来源</label>
            <div class="form__inline">
              <label class="radio">
                <input v-model="form.source_type" type="radio" value="embed">
                <span>第三方嵌入</span>
              </label>
              <label class="radio">
                <input v-model="form.source_type" type="radio" value="mp4">
                <span>直链 mp4</span>
              </label>
            </div>
          </div>

          <div v-if="form.source_type === 'embed'" class="form__row">
            <label class="form__label" for="f-provider">平台</label>
            <select id="f-provider" v-model="form.provider" style="width:auto">
              <option value="bilibili">哔哩哔哩</option>
              <option value="youtube">YouTube</option>
              <option value="custom">其它（自定义嵌入地址）</option>
            </select>
          </div>

          <div class="form__row">
            <label class="form__label" for="f-source">
              {{ form.source_type === 'embed' ? '视频地址或 BV 号' : 'mp4 直链' }}
            </label>
            <input
              id="f-source"
              v-model="form.source_key"
              type="text"
              :placeholder="form.source_type === 'embed' ? '直接粘贴完整链接也可以，会自动提取 BV 号' : 'https://… .mp4'"
            >
            <p class="form__hint">留空视为未完成，保存会被拒绝</p>
          </div>

          <div class="form__grid-2">
            <div class="form__row">
              <label class="form__label" for="f-aspect">画面比例</label>
              <input id="f-aspect" v-model="form.aspect_ratio" type="text" placeholder="16:9">
            </div>
            <div class="form__row">
              <label class="form__label" for="f-duration">时长（秒，可空）</label>
              <input id="f-duration" v-model="form.duration_seconds" type="number" min="0" placeholder="300">
              <p class="form__hint" v-if="form.duration_seconds">
                显示为 {{ formatDuration(Number(form.duration_seconds)) }}
              </p>
            </div>
          </div>
        </template>

        <!-- ── 图片 ── -->
        <template v-else>
          <div class="form__row">
            <label class="form__label">图片</label>

            <div v-if="form.asset_id" class="editor__asset">
              <SmartImage
                :src="form.asset_path"
                use-thumb
                :width="form.width || 4"
                :height="form.height || 3"
                placeholder="预览不可用"
              />
              <div class="editor__asset-meta">
                <span class="mono tiny">{{ form.asset_path }}</span>
                <span class="tiny faint" v-if="form.width">
                  {{ form.width }} × {{ form.height }}
                </span>
              </div>
              <button class="btn btn-sm" type="button" @click="pickerFor = 'asset'">更换</button>
            </div>

            <button v-else class="btn btn-primary" type="button" @click="pickerFor = 'asset'">
              从媒体库选择
            </button>

            <p class="form__hint">
              宽高会自动带入。图片详情页靠这两个值占位，缺了会拒保存（防布局跳动）。
            </p>
          </div>

          <div class="form__grid-2">
            <div class="form__row">
              <label class="form__label" for="f-alt">替代文字</label>
              <input id="f-alt" v-model="form.alt_text" type="text" maxlength="255" placeholder="给读屏与搜索用">
            </div>
            <div class="form__row">
              <label class="form__label" for="f-camera">拍摄信息（可空）</label>
              <input id="f-camera" v-model="form.camera_note" type="text" maxlength="255" placeholder="例如 35mm f/2.8">
            </div>
          </div>
        </template>
      </div>

      <!-- 右：公共属性 -->
      <aside class="editor__side">
        <div class="form__row">
          <label class="form__label" for="f-status">状态</label>
          <select id="f-status" v-model="form.status">
            <option value="draft">草稿</option>
            <option value="published">已发布</option>
            <option value="trashed">回收站</option>
          </select>
        </div>

        <div class="form__row">
          <label class="form__label" for="f-published">发布时间</label>
          <input id="f-published" v-model="form.published_at" type="datetime-local">
          <p class="form__hint">
            填未来时间即为<b>定时发布</b>：到点自动可见，不依赖任何任务。
            留空则保存时取当前时间。
          </p>
        </div>

        <div class="form__row">
          <label class="form__label" for="f-slug">固定链接</label>
          <input
            id="f-slug"
            v-model="form.slug"
            type="text"
            placeholder="留空自动生成"
            @input="slugTouched = true"
          >
          <p class="form__hint">改掉之后旧地址会自动 301 到新地址，不用担心外链失效。</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="f-summary">摘要</label>
          <textarea id="f-summary" v-model="form.summary" rows="3" maxlength="500" placeholder="留空自动截取正文前 120 字" />
        </div>

        <div class="form__row">
          <label class="form__label">标签</label>
          <TagInput v-model="form.tags" :suggestions="tagSuggestions" :max="10" />
        </div>

        <div class="form__row">
          <label class="form__label">
            {{ form.type === 'video' ? '视频封面（必填）' : '封面' }}
          </label>

          <div v-if="form.type === 'video' ? form.poster_path : form.cover_path" class="editor__cover">
            <SmartImage
              :src="form.type === 'video' ? form.poster_path : form.cover_path"
              use-thumb
              :width="16"
              :height="9"
              placeholder="封面不可用"
            />
            <button class="btn btn-sm" type="button" @click="pickerFor = form.type === 'video' ? 'poster' : 'cover'">
              更换
            </button>
            <button
              class="btn btn-sm btn-ghost"
              type="button"
              @click="form.type === 'video' ? (form.poster_path = '') : (form.cover_path = '')"
            >移除</button>
          </div>

          <button
            v-else
            class="btn"
            type="button"
            @click="pickerFor = form.type === 'video' ? 'poster' : 'cover'"
          >选择封面</button>

          <p v-if="form.type !== 'video'" class="form__hint">留空则列表页用纯色占位块</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="f-sort">排序权重</label>
          <input id="f-sort" v-model.number="form.sort_weight" type="number">
          <p class="form__hint">越大越靠前。</p>
        </div>

        <label class="checkbox">
          <input v-model="form.is_featured" type="checkbox">
          <span>设为精选（出现在首页精选区块）</span>
        </label>
      </aside>
    </div>

    <MediaPicker
      :open="!!pickerFor"
      @select="onPick"
      @close="pickerFor = ''"
    />
  </div>
</template>
