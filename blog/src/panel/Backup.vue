<script setup>
/**
 * Backup.vue —— 导出备份
 *
 * 【为什么没有「一键恢复」】导入 SQL 意味着让网页执行任意删表语句。
 * 一个能删库的 HTTP 接口，比没有恢复功能危险得多。恢复请用 phpMyAdmin
 * 或命令行 —— 那里本来就有权限与确认步骤（SPEC D19）。
 *
 * 【导出为什么要看日期】图床上的文件不在 SQL 里，导出的只是「索引」。
 * 表结构加上一份最近的导出，才构成一次真正的备份。
 */
import { ref, computed, onMounted } from 'vue'
import { get, post } from '../lib/api.js'
import { toast, toastError } from '../lib/store.js'
import { formatDateTime, formatRelative } from '../lib/format.js'
import { formatSize } from '../lib/image.js'

const info = ref(null)
const loading = ref(true)
const exporting = ref(false)

const remindText = computed(() => {
  const days = info.value?.remind_days ?? 0
  if (days < 0) return '仅首次提醒'
  if (days === 0) return '不提醒'
  return `${days} 天`
})

/** 提醒阈值：-1 = 从未导出过，0 = 不提醒 */
const overdue = computed(() => {
  if (!info.value) return false
  if (info.value.remind_days === 0) return false
  if (info.value.days_since < 0) return true
  return info.value.remind_days > 0 && info.value.days_since >= info.value.remind_days
})

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await get('admin.export.list')
    info.value = data
  } catch (err) {
    toastError(err)
  } finally {
    loading.value = false
  }
}

async function createExport() {
  if (exporting.value) return
  exporting.value = true

  try {
    const { data } = await post('admin.export.create')

    // 服务端把整份 SQL 塞在响应里（几百 KB 到几 MB），这里转成 Blob 交给浏览器存盘。
    // 好处是 data/ 目录不需要开一个需要鉴权的下载入口（见 admin.php 里的说明）。
    const blob = new Blob([data.sql], { type: 'application/sql;charset=utf-8' })
    const url = URL.createObjectURL(blob)

    const link = document.createElement('a')
    link.href = url
    link.download = data.filename
    document.body.appendChild(link)
    link.click()
    link.remove()

    // 立刻回收：不撤销的话，这份 SQL 会一直留在内存里直到页面关闭
    URL.revokeObjectURL(url)

    toast(`已导出 ${data.filename}（${formatSize(data.size)}）`, 'success')
    await load()
  } catch (err) {
    toastError(err)
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4)">
      <h1 style="margin:0;font-size:1.125rem">备份</h1>
      <button
        class="btn btn-primary btn-sm"
        style="margin-left:auto"
        type="button"
        :disabled="exporting"
        @click="createExport"
      >{{ exporting ? '正在生成…' : '导出 SQL' }}</button>
    </div>

    <div v-if="loading" class="skeleton" style="height: 16rem" />

    <template v-else-if="info">
      <div class="alert" :class="overdue ? 'alert--warning' : 'alert--info'" style="margin-bottom:var(--space-4)">
        <span v-if="info.days_since < 0">
          还没有导出过。站点内容随时可能因为误操作或服务商问题丢失，建议现在就导一份。
        </span>
        <span v-else-if="overdue">
          距上次导出已 {{ info.days_since }} 天
          <template v-if="info.remind_days > 0">（超过设定的 {{ info.remind_days }} 天）</template>，建议更新一份。
        </span>
        <span v-else>
          上次导出在 {{ info.days_since }} 天前，一切正常。
        </span>
      </div>

      <div class="card">
        <h2 class="card__title">导出内容</h2>

        <table class="table" style="font-size:0.85rem">
          <tbody>
            <tr>
              <td class="faint" style="width:10rem">上次导出</td>
              <td>
                <template v-if="info.last_export_at">
                  {{ formatDateTime(info.last_export_at) }}
                  <span class="faint tiny"> · {{ formatRelative(info.last_export_at) }}</span>
                </template>
                <span v-else class="faint">从未导出</span>
              </td>
            </tr>
            <tr>
              <td class="faint">提醒间隔</td>
              <td>{{ remindText }}</td>
            </tr>
            <tr>
              <td class="faint">包含表</td>
              <td class="mono tiny">{{ info.tables.join('、') }}</td>
            </tr>
          </tbody>
        </table>

        <p class="form__hint" style="margin-top:var(--space-3)">
          导出的是纯粹的 SQL 文本，含建表语句与全部数据，可以直接用 phpMyAdmin 导入。
          图片文件本身在图床上，不在这份 SQL 里 —— 数据库丢了可以重建，
          图床上的图只有在被「释放」时才真的消失。
        </p>
      </div>

      <div class="card">
        <h2 class="card__title">恢复方式</h2>
        <ol class="small" style="margin:0;padding-left:1.25rem">
          <li>在 phpMyAdmin 里选中本库，导入导出的 <code>.sql</code> 文件。</li>
          <li>或命令行：<code class="mono">mysql -u 用户 -p 库名 &lt; 备份文件.sql</code></li>
          <li>如果换过域名或目录，导完后去「设置」核对图片域名白名单。</li>
        </ol>
        <p class="form__hint">
          没有做网页版的一键恢复是刻意的：那等于开一个能把整个库删掉的接口。
        </p>
      </div>
    </template>
  </div>
</template>
