<script setup>
/**
 * Settings.vue —— 站点设置 / 图床 / 安全
 *
 * 【图床 Key 不在这里，也不该在这里】TTTTT_API_KEY 只存在于 config.php，
 * settings 表里的任何值都会被 admin.setting.get 吐给浏览器（SPEC §7.2.2 / R7）。
 * 所以这一页对 Key 只显示「已配置 / 未配置」，输入框压根不存在 ——
 * 一个不存在的字段比一条「请不要填在这里」的提示可靠得多。
 *
 * 【CSP 白名单与图床域名是同一件事】cdn_whitelist 会直接进 Content-Security-Policy
 * 的 img-src，frame_whitelist 进 frame-src。前端这里只说清后果：
 * 漏了域名，图就显示不出来 / 视频变成一个白框。
 */
import { ref, computed, onMounted } from 'vue'
import { get, post, setCsrfToken } from '../lib/api.js'
import { toast, toastError, loadSite } from '../lib/store.js'
import { formatDate } from '../lib/format.js'

const form = ref({
  site_name: '',
  site_desc: '',
  site_icp: '',
  cdn_base: '',
  cdn_whitelist: '',
  frame_whitelist: '',
  ttttt_direct_template: '',
  cache_enabled: '0',
  cache_ttl: '3600',
  cron_probability: '10',
  storage_driver: 'ttttt',
  ttttt_key_param: 'key',
  ttttt_max_bytes: '104857600',
  image_thumb_edge: '800',
  image_full_edge: '2560',
  page_size: '10',
  backup_remind_days: '30',
})

const keyConfigured = ref(false)
const adminEntry = ref('')
const loading = ref(true)
const saving = ref(false)

// 改密码
const pwd = ref({ next: '', confirm: '' })
const changing = ref(false)

const templateOk = computed(() => {
  const t = form.value.ttttt_direct_template
  return t === '' || (t.includes('{dkey}') && t.includes('{name}'))
})

/*
 * 与服务端 admin_normalize_domains() 保持同一套字符集。
 *
 * 为什么要在这里重复一遍：服务端遇到不合法的项是**静默丢弃**的
 * （它没法把「保存成功」变成失败，用户填十个域名错一个也不该整批不存）。
 * 但静默正是最难查的失败 —— 用户看着设置页里明明写着那个域名，
 * 图却还是出不来。所以把将被丢弃的项提前摆到眼前。
 */
const DOMAIN_RE = /^[a-z0-9.\-:*/?_~%=+@[\]]+$/i
function droppedDomains(text) {
  return String(text || '')
    .split(/[\s,]+/)
    .filter((t) => t && !(t.length <= 253 && DOMAIN_RE.test(t)))
}

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.setting.get')
    for (const key of Object.keys(form.value)) {
      if (key in data && data[key] !== null && typeof data[key] !== 'boolean') {
        form.value[key] = String(data[key])
      }
    }
    keyConfigured.value = !!data.ttttt_key_configured
    adminEntry.value = data.admin_entry || ''
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

async function save() {
  if (saving.value) return

  if (!templateOk.value) {
    toast('直链模板必须同时包含 {dkey} 与 {name} 两个占位符', 'error')
    return
  }

  saving.value = true
  try {
    const { data } = await post('admin.setting.save', { settings: { ...form.value } })
    toast(`已保存 ${data.saved.length} 项`, 'success')
    // 站名/分页数等会影响前台渲染，强制重取一次
    await loadSite(true)
  } catch (err) {
    toastError(err)
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  if (changing.value) return

  if (pwd.value.next.length < 8) {
    toast('新密码至少 8 位', 'error')
    return
  }
  if (pwd.value.next !== pwd.value.confirm) {
    toast('两次输入的密码不一致', 'error')
    return
  }

  changing.value = true
  try {
    const { data } = await post('auth.password', {
      new_password: pwd.value.next,
      confirm_password: pwd.value.confirm,
    })
    // 改密会重建会话，旧 token 立刻失效 —— 不换新的，下一个写请求必 403
    setCsrfToken(data.csrf_token)
    pwd.value = { next: '', confirm: '' }
    toast('密码已更新', 'success')
  } catch (err) {
    toastError(err)
  } finally {
    changing.value = false
  }
}

const maxUploadMb = computed(() => Math.round(Number(form.value.ttttt_max_bytes || 0) / 1048576))
</script>

<template>
  <div v-if="loading" class="skeleton" style="height: 30rem" />

  <div v-else>
  <form @submit.prevent="save">
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">设置</h1>
      <button class="btn btn-primary btn-sm" style="margin-left:auto" type="submit" :disabled="saving">
        {{ saving ? '保存中…' : '保存设置' }}
      </button>
    </div>

    <!-- ── 站点 ── -->
    <section class="card">
      <h2 class="card__title">站点</h2>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-name">站点名称</label>
          <input id="s-name" v-model="form.site_name" type="text" maxlength="64">
        </div>
        <div class="form__row">
          <label class="form__label" for="s-icp">备案号（可空）</label>
          <input id="s-icp" v-model="form.site_icp" type="text" maxlength="64" placeholder="例如 京ICP备00000000号">
        </div>
      </div>

      <div class="form__row">
        <label class="form__label" for="s-desc">站点描述</label>
        <input id="s-desc" v-model="form.site_desc" type="text" maxlength="255">
        <p class="form__hint">用在首页横幅、RSS 与分享卡片的默认描述。</p>
      </div>

      <div class="form__row">
        <label class="form__label" for="s-page">每页条数</label>
        <input id="s-page" v-model="form.page_size" type="number" min="1" max="100" style="width:8rem">
      </div>
    </section>

    <!-- ── 图床 ── -->
    <section class="card">
      <h2 class="card__title">图床</h2>

      <div class="alert" :class="keyConfigured ? 'alert--info' : 'alert--danger'" style="margin-bottom:var(--space-4)">
        <span>
          API Key：
          <b>{{ keyConfigured ? '已在 config.php 中配置' : '未配置' }}</b>
          —— Key 只能写在 config.php，本站的 settings 表会把内容回传给浏览器，放这里等于公开。
        </span>
      </div>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-driver">存储驱动</label>
          <select id="s-driver" v-model="form.storage_driver">
            <option value="ttttt">钛盘（自动上传）</option>
            <option value="manual">手动（只登记外链）</option>
          </select>
          <p class="form__hint">切到「手动」后，上传入口仍然会用钛盘配置，但巡检与释放不会执行。</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="s-max">单文件上限（字节）</label>
          <input id="s-max" v-model="form.ttttt_max_bytes" type="number" min="1048576" max="104857600">
          <p class="form__hint">约 {{ maxUploadMb }} MB。超过 php.ini 的 upload_max_filesize 时以更小者为准。</p>
        </div>
      </div>

      <div class="form__row">
        <label class="form__label" for="s-template">直链模板</label>
        <input id="s-template" v-model="form.ttttt_direct_template" type="text" class="mono" placeholder="https://download.example.com/files/{dkey}/{name}">
        <p class="form__hint" :style="templateOk ? '' : 'color:var(--danger)'">
          必须同时包含 <code>{dkey}</code> 与 <code>{name}</code>，注意不是 <code>{filename}</code>。
          改模板后，去媒体库点「重建直链」即可让存量图片套用新模板 —— 不需要重新上传。
        </p>
      </div>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-keyparam">API Key 参数名</label>
          <input id="s-keyparam" v-model="form.ttttt_key_param" type="text" class="mono" maxlength="32" placeholder="key">
          <p class="form__hint">接口约定的参数名，非必要不要改。</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="s-thumb">缩略图边长</label>
          <input id="s-thumb" v-model="form.image_thumb_edge" type="number" min="100" max="2000" style="width:8rem">
        </div>
      </div>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-full">原图最长边</label>
          <input id="s-full" v-model="form.image_full_edge" type="number" min="400" max="6000" style="width:8rem">
          <p class="form__hint">超过这个尺寸会在浏览器里等比缩小，顺带完成元数据清理。</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="s-cdn">图床域名</label>
          <input id="s-cdn" v-model="form.cdn_base" type="text" class="mono" placeholder="https://download.example.com">
          <p class="form__hint">只用来判断「这个地址是不是自己的图床」，不做改址用。</p>
        </div>
      </div>

      <div class="form__row">
        <label class="form__label" for="s-whitelist">图片域名白名单</label>
        <textarea id="s-whitelist" v-model="form.cdn_whitelist" class="mono" rows="3" placeholder="https://download.example.com&#10;https://i.example.net" />
        <p class="form__hint">
          一行一个，会直接写进页面的 CSP <code>img-src</code>。
          <b>漏掉的域名会被浏览器拦下</b>，图片显示为空 —— 加外链图前先来这里加一条。
        </p>
        <p v-if="droppedDomains(form.cdn_whitelist).length" class="form__error">
          这几项写法不合法，保存时会被忽略：<code>{{ droppedDomains(form.cdn_whitelist).join('  ') }}</code>
        </p>
      </div>

      <div class="form__row">
        <label class="form__label" for="s-frame-whitelist">视频嵌入域名白名单</label>
        <textarea id="s-frame-whitelist" v-model="form.frame_whitelist" class="mono" rows="3" placeholder="https://player.example.com" />
        <p class="form__hint">
          哔哩哔哩和 YouTube 已经内置，<b>其余播放器都要在这里登记</b>，否则 iframe
          会被 CSP 拦下 —— 表现是页面上一个白框，没有任何报错，只在控制台里留一条违规记录。
          填的是 iframe 地址所在的域名。
        </p>
        <p v-if="droppedDomains(form.frame_whitelist).length" class="form__error">
          这几项写法不合法，保存时会被忽略：<code>{{ droppedDomains(form.frame_whitelist).join('  ') }}</code>
        </p>
      </div>
    </section>

    <!-- ── 性能与任务 ── -->
    <section class="card">
      <h2 class="card__title">缓存与任务</h2>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-cache">页面缓存</label>
          <select id="s-cache" v-model="form.cache_enabled">
            <option value="0">关闭（内容少时没必要开）</option>
            <option value="1">开启</option>
          </select>
        </div>

        <div class="form__row">
          <label class="form__label" for="s-ttl">缓存时长（秒）</label>
          <input id="s-ttl" v-model="form.cache_ttl" type="number" min="60" max="86400" style="width:8rem">
        </div>
      </div>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="s-cron">伪 cron 触发概率（%）</label>
          <input id="s-cron" v-model="form.cron_probability" type="number" min="0" max="100" style="width:8rem">
          <p class="form__hint">
            每次访问按这个概率顺带跑一次任务。站点流量很低时调高一点，
            否则任务可能很久才轮到一次。0 表示完全不自动跑。
          </p>
        </div>

        <div class="form__row">
          <label class="form__label" for="s-backup">备份提醒间隔（天）</label>
          <input id="s-backup" v-model="form.backup_remind_days" type="number" min="-1" max="365" style="width:8rem">
          <p class="form__hint">超过这个天数没导出就提醒。-1 表示只在从未导出过时提醒。</p>
        </div>
      </div>
    </section>

    <!-- ── 后台入口 ── -->
    <section class="card">
      <h2 class="card__title">后台入口</h2>
      <p class="small" style="margin:0">
        当前入口文件名：<code>{{ adminEntry || '（未知）' }}</code>
      </p>
      <p class="form__hint">
        安装时随机生成，扫描器猜不到。把它存进密码管理器，改掉这个文件的名字就等于换了一把门锁。
      </p>
    </section>

    <div style="margin-bottom:var(--space-6)">
      <button class="btn btn-primary" type="submit" :disabled="saving">
        {{ saving ? '保存中…' : '保存设置' }}
      </button>
    </div>

  </form>

    <!-- ── 改密码（**不在上面的 form 里**：在密码框按回车会触发表单提交，
         那样「确认新密码」按下回车就变成保存设置了） ── -->
    <section class="card">
      <h2 class="card__title">修改密码</h2>

      <div class="form__grid-2">
        <div class="form__row">
          <label class="form__label" for="p-new">新密码</label>
          <input id="p-new" v-model="pwd.next" type="password" autocomplete="new-password" minlength="8">
        </div>
        <div class="form__row">
          <label class="form__label" for="p-confirm">再输一次</label>
          <input id="p-confirm" v-model="pwd.confirm" type="password" autocomplete="new-password">
        </div>
      </div>

      <p class="form__hint">至少 8 位。改完之后其它设备上的登录会立刻失效。</p>

      <button
        class="btn"
        type="button"
        :disabled="changing || !pwd.next || !pwd.confirm"
        @click="changePassword"
      >{{ changing ? '提交中…' : '修改密码' }}</button>
    </section>
  </div>
</template>
