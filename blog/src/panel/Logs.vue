<script setup>
/**
 * Logs.vue —— 错误日志
 *
 * 【日志内容一律不做 HTML 渲染】消息里可能嵌着用户提交的字符串。
 * 这里是后台、是站长自己看的页面，但「登录后」不等于「输入可信」——
 * 用 v-text 而不是 v-html，成本为零，省掉一个 XSS 面。
 *
 * 【日志文件带 .php 后缀】data/ 目录下的文件都得能被当作 PHP 执行，
 * 靠开头的守卫头直接退出，这样即使有人猜到了路径也读不到内容（SPEC §9.4-1）。
 */
import { ref, computed, onMounted } from 'vue'
import { get } from '../lib/api.js'
import { toastError } from '../lib/store.js'

const entries = ref([])
const months = ref([])
const loading = ref(true)
const month = ref('')
const limit = ref('100')
const expanded = ref(new Set())
const levelFilter = ref('')

const LEVEL_CLASS = {
  Error: 'badge-danger',
  RuntimeError: 'badge-danger',
  Warning: 'badge-warning',
  Warn: 'badge-warning',
  Info: 'badge',
  Notice: 'badge',
}

const counts = computed(() => {
  const out = {}
  for (const e of entries.value) {
    out[e.level] = (out[e.level] || 0) + 1
  }
  return out
})

const visible = computed(() => (
  levelFilter.value ? entries.value.filter((e) => e.level === levelFilter.value) : entries.value
))

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.log.list', {
      month: month.value || undefined,
      limit: Number(limit.value) || 100,
    })
    entries.value = data.entries || []
    months.value = data.months || []
    expanded.value = new Set()
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

function toggle(index) {
  const next = new Set(expanded.value)
  next.has(index) ? next.delete(index) : next.add(index)
  expanded.value = next
}

/** 上下文里可能塞着堆栈，压成一行短预览，展开再看全部 */
function preview(entry) {
  if (!entry.context) return ''
  const value = entry.context.trace || entry.context.file || JSON.stringify(entry.context)
  return String(value).split('\n')[0].slice(0, 120)
}

function full(entry) {
  return JSON.stringify(entry.context, null, 2)
}
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">错误日志</h1>
      <span class="faint tiny">最近 {{ visible.length }} 条</span>
      <button class="btn btn-sm" style="margin-left:auto" type="button" @click="load">刷新</button>
    </div>

    <form class="filters" @submit.prevent="load">
      <select v-model="month" style="width:auto" @change="load">
        <option value="">全部月份</option>
        <option v-for="m in months" :key="m" :value="m">{{ m }}</option>
      </select>

      <select v-model="levelFilter" style="width:auto">
        <option value="">全部级别</option>
        <option v-for="(n, level) in counts" :key="level" :value="level">{{ level }}（{{ n }}）</option>
      </select>

      <select v-model="limit" style="width:auto" @change="load">
        <option value="50">最近 50 条</option>
        <option value="100">最近 100 条</option>
        <option value="300">最近 300 条</option>
        <option value="500">最近 500 条</option>
      </select>

      <button class="btn btn-primary btn-sm" type="submit">查询</button>
    </form>

    <div v-if="loading" class="skeleton" style="height: 24rem" />

    <div v-else-if="!visible.length" class="empty">
      没有日志。这是好消息 —— 说明没出过错。
    </div>

    <div v-else class="log-list">
      <div v-for="(entry, index) in visible" :key="index" class="log-entry">
        <div class="log-entry__head" @click="toggle(index)">
          <span class="badge" :class="LEVEL_CLASS[entry.level] || 'badge'">{{ entry.level }}</span>
          <span class="log-entry__time">{{ entry.time }}</span>
          <span class="log-entry__message" v-text="entry.message" />
          <button
            v-if="entry.context"
            class="btn btn-sm btn-ghost"
            style="margin-left:auto"
            type="button"
            @click.stop="toggle(index)"
          >{{ expanded.has(index) ? '收起' : '详情' }}</button>
        </div>

        <div v-if="entry.context && !expanded.has(index)" class="log-entry__preview mono tiny faint">
          <span v-text="preview(entry)" />
        </div>

        <pre v-if="entry.context && expanded.has(index)" class="log-entry__context mono">{{ full(entry) }}</pre>

        <div class="log-entry__file tiny faint" v-text="entry.file" />
      </div>
    </div>
  </div>
</template>
