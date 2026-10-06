<script setup>
/**
 * Dashboard.vue —— 后台概览
 *
 * 【告警区是这一页的主要价值】内容数量随处可查，但「有几张图挂了」
 * 「有没有非永久模式的资产」这类问题，只有主动巡检才会发现（SPEC §10.2）。
 * 把它们放在最显眼的位置，是让这些静默故障有机会被看见。
 */
import { ref, computed, onMounted } from 'vue'
import { get } from '../lib/api.js'
import { toastError } from '../lib/store.js'
import { formatDateTime } from '../lib/format.js'

const info = ref(null)
const tasks = ref([])
const loading = ref(true)

const alerts = computed(() => {
  if (!info.value) return []
  const a = info.value.alerts
  const out = []

  if (a.non_permanent > 0) {
    out.push({
      level: 'danger',
      text: `有 ${a.non_permanent} 个资产处于非永久存储模式，图片可能已经消失`,
      to: { name: 'panel-media' },
      action: '去检查',
    })
  }
  if (a.broken_assets > 0) {
    out.push({
      level: 'danger',
      text: `有 ${a.broken_assets} 个图片链接已失效`,
      to: { name: 'panel-media' },
      action: '去修复',
    })
  }
  if (a.orphan_assets > 0) {
    out.push({
      level: 'warning',
      text: `有 ${a.orphan_assets} 个孤立资源占用着图床空间，可以清理`,
      to: { name: 'panel-media' },
      action: '去清理',
    })
  }
  if (a.no_thumb > 0) {
    out.push({
      level: 'warning',
      text: `有 ${a.no_thumb} 张图没有缩略版，列表页会直接拉原图（费流量）`,
      to: { name: 'panel-media' },
      action: '查看',
    })
  }
  if (a.trash_pending > 0) {
    out.push({
      level: 'info',
      text: `回收站有 ${a.trash_pending} 条内容，30 天后会被自动清理`,
      to: { name: 'panel-contents', query: { status: 'trashed' } },
      action: '去处理',
    })
  }
  return out
})

onMounted(async () => {
  try {
    const [sysRes, taskRes] = await Promise.all([get('admin.sysinfo'), get('admin.task.list')])
    info.value = sysRes.data
    tasks.value = taskRes.data || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
})

const stats = computed(() => {
  if (!info.value) return []
  const c = info.value.counts
  return [
    { label: '文章', value: c.article, to: { name: 'panel-contents', query: { type: 'article' } } },
    { label: '视频', value: c.video, to: { name: 'panel-contents', query: { type: 'video' } } },
    { label: '图片', value: c.image, to: { name: 'panel-contents', query: { type: 'image' } } },
    { label: '草稿', value: c.draft, to: { name: 'panel-contents', query: { status: 'draft' } } },
    { label: '标签', value: c.tags, to: { name: 'panel-tags' } },
    { label: '媒体', value: c.assets, to: { name: 'panel-media' } },
    { label: '总浏览', value: info.value.views.pv, to: null },
  ]
})
</script>

<template>
  <div>
    <div v-if="loading" class="stats">
      <div v-for="n in 7" :key="n" class="skeleton" style="height: 5rem" />
    </div>

    <template v-else-if="info">
      <!-- 告警优先，排在最上面 -->
      <div v-if="alerts.length" class="alerts">
        <div v-for="(alert, index) in alerts" :key="index" class="alert" :class="`alert--${alert.level}`">
          <span>{{ alert.text }}</span>
          <span class="alert__actions">
            <RouterLink v-if="alert.to" class="btn btn-sm" :to="alert.to">{{ alert.action }}</RouterLink>
          </span>
        </div>
      </div>

      <div class="stats">
        <RouterLink
          v-for="stat in stats"
          :key="stat.label"
          class="stat"
          :to="stat.to || undefined"
          :style="stat.to ? 'text-decoration:none;color:inherit' : ''"
        >
          <div class="stat__value">{{ stat.value }}</div>
          <div class="stat__label">{{ stat.label }}</div>
        </RouterLink>
      </div>

      <div class="form__grid-2">
        <div class="card">
          <h2 style="margin-top:0;font-size:0.95rem">最近任务</h2>
          <table class="table" style="font-size:0.8rem">
            <tbody>
              <tr v-for="task in tasks" :key="task.task_key">
                <td style="width:9rem" class="mono">{{ task.task_key }}</td>
                <td class="faint">{{ task.last_run_at ? formatDateTime(task.last_run_at) : '尚未运行' }}</td>
                <td class="muted">{{ task.last_result || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h2 style="margin-top:0;font-size:0.95rem">运行环境</h2>
          <table class="table" style="font-size:0.8rem">
            <tbody>
              <tr><td class="faint">PHP</td><td class="mono">{{ info.env.php_version }}</td></tr>
              <tr><td class="faint">MySQL</td><td class="mono">{{ info.env.mysql_version }}</td></tr>
              <tr><td class="faint">上传上限</td><td class="mono">{{ info.env.upload_limit }}</td></tr>
              <tr><td class="faint">脚本超时</td><td class="mono">{{ info.env.time_limit }}s</td></tr>
              <tr>
                <td class="faint">伪 cron</td>
                <td>
                  <span class="badge" :class="info.env.has_fastcgi ? 'badge-success' : 'badge-warning'">
                    {{ info.env.has_fastcgi ? '响应后执行（12s 预算）' : '同步执行（5s 预算）' }}
                  </span>
                </td>
              </tr>
              <tr>
                <td class="faint">调试模式</td>
                <td>
                  <span class="badge" :class="info.env.debug ? 'badge-danger' : 'badge-success'">
                    {{ info.env.debug ? '已开启（生产必须关闭）' : '已关闭' }}
                  </span>
                </td>
              </tr>
              <tr><td class="faint">服务器时间</td><td class="mono">{{ info.env.server_time }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
