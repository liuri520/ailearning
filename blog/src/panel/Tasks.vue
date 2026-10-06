<script setup>
/**
 * Tasks.vue —— 定时任务
 *
 * 【没有系统 cron，任务靠访问触发】每次页面请求按概率顺带跑一个任务，
 * 单次执行有硬预算（响应后执行 12 秒，同步执行 5 秒），到点就停、下轮接着做。
 * 所以「上次运行」有可能是几小时前 —— 流量小的站点本来就该这样，不是故障。
 *
 * 「立即执行」不受周期间隔限制：用户点它就是要它现在跑（SPEC §6.3 admin.task.run）。
 * 执行是同步的，页面上会有几秒没反应，按钮要给出这个预期。
 */
import { ref, computed, onMounted } from 'vue'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import { formatDateTime, formatRelative } from '../lib/format.js'

const rows = ref([])
const loading = ref(true)
const runningKey = ref('')
const results = ref({})   // task_key → 本次手动执行的结果

/** 任务说明：光看 task_key 没人知道 view_gc 是干嘛的 */
const LABEL = {
  asset_check:    ['图床链接巡检', '比对图床上的直链与本机记录，标出失效的图。'],
  expires_watch:  ['到期资产巡查', '找出非永久存储模式的资产 —— 这类图到点会自己消失。'],
  view_aggregate: ['浏览数据汇总', '把明细里的浏览数并进按天统计表。'],
  view_gc:        ['浏览明细清理', '删掉过期的浏览明细，避免表无限增长。'],
  cache_gc:       ['缓存清理', '删除过期的文件缓存。'],
  backup_remind:  ['备份提醒', '距上次导出太久时写一条提醒。'],
  login_gc:       ['登录记录清理', '清理过期的登录失败记录，顺带解除锁定。'],
  trash_gc:       ['回收站清理', '彻底删除超过 30 天的回收站内容（含其独占的图床文件）。'],
}

const list = computed(() => rows.value.map((task) => {
  const info = LABEL[task.task_key] || [task.task_key, '']
  return {
    ...task,
    label: info[0],
    desc: info[1],
    periodText: formatPeriod(task.period),
  }
}))

function formatPeriod(seconds) {
  const s = Number(seconds) || 0
  if (s <= 0) return '不限'
  if (s < 3600) return `${Math.round(s / 60)} 分钟`
  if (s < 86400) return `${Math.round(s / 3600)} 小时`
  return `${Math.round(s / 86400)} 天`
}

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.task.list')
    rows.value = data || []
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

async function run(task) {
  if (runningKey.value) return

  runningKey.value = task.task_key
  try {
    const { data } = await post('admin.task.run', { task_key: task.task_key })
    results.value = { ...results.value, [task.task_key]: data }
    toast(`${task.label}：${data.result || '已执行'}（${data.duration_ms} ms）`, 'success')
    await load()
  } catch (err) {
    toastError(err)
  } finally {
    runningKey.value = ''
  }
}
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">定时任务</h1>
      <button class="btn btn-sm" style="margin-left:auto" type="button" @click="load">刷新</button>
    </div>

    <div class="alert alert--info" style="margin-bottom:var(--space-4)">
      <span>
        本站没有系统级 cron，任务由访问顺带触发。单次执行有时间预算，跑不完的部分留给下一轮 ——
        这是设计如此，不是卡住了。
      </span>
    </div>

    <div v-if="loading" class="skeleton" style="height: 22rem" />

    <div v-else-if="!list.length" class="empty">
      任务表是空的。重新执行一次安装脚本（install.php）就会把任务写进去。
    </div>

    <div v-else class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>任务</th>
            <th style="width:7rem">间隔</th>
            <th style="width:11rem">上次运行</th>
            <th style="width:6rem">耗时</th>
            <th>结果</th>
            <th style="width:7rem">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="task in list" :key="task.task_key">
            <td>
              <div>{{ task.label }}</div>
              <div class="tiny faint mono">{{ task.task_key }}</div>
              <div class="tiny faint" style="max-width:26rem">{{ task.desc }}</div>
            </td>

            <td class="tiny">{{ task.periodText }}</td>

            <td class="tiny">
              <template v-if="task.last_run_at">
                <div>{{ formatDateTime(task.last_run_at) }}</div>
                <div class="faint">{{ formatRelative(task.last_run_at) }}</div>
              </template>
              <span v-else class="faint">尚未运行</span>
            </td>

            <td class="tiny mono">{{ task.duration_ms != null ? task.duration_ms + ' ms' : '—' }}</td>

            <td class="tiny">
              <span v-if="task.running" class="badge badge-warning">执行中</span>
              <template v-else>
                {{ task.last_result || '—' }}
                <div v-if="results[task.task_key]" class="badge badge-success" style="margin-top:var(--space-1)">
                  本次：{{ results[task.task_key].result }}
                </div>
              </template>
            </td>

            <td>
              <button
                class="btn btn-sm"
                type="button"
                :disabled="!!runningKey || task.running"
                @click="run(task)"
              >{{ runningKey === task.task_key ? '执行中…' : '立即执行' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
