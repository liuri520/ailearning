<script setup>
/**
 * PanelApp.vue —— 后台外壳：侧栏导航 + 内容区
 *
 * 【登录页不套外壳】登录时还没通过任何鉴权，把「日志」「备份」这些入口
 * 摆在旁边只是多给扫描器一点信息。用 route.meta.public 判断，登录页单独渲染。
 *
 * 【侧栏不收起】后台的导航项不到十个，收起/展开多一个状态就多一处
 * 「我上次是不是收起来了」的困惑，换不来任何实际空间。
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { post } from './lib/api.js'
import { toast, toastError, site } from './lib/store.js'
import { markUnauthorized } from './router/panel.js'
import ThemeToggle from './components/ThemeToggle.vue'
import ToastStack from './components/ToastStack.vue'

const route = useRoute()
const router = useRouter()

const loggingOut = ref(false)

const navGroups = [
  {
    label: '内容',
    items: [
      { name: 'panel-dashboard', label: '概览' },
      { name: 'panel-contents', label: '内容管理' },
      { name: 'panel-tags', label: '标签' },
      { name: 'panel-media', label: '媒体库' },
    ],
  },
  {
    label: '站点',
    items: [
      { name: 'panel-home', label: '首页布局' },
      { name: 'panel-settings', label: '设置' },
    ],
  },
  {
    label: '维护',
    items: [
      { name: 'panel-tasks', label: '任务' },
      { name: 'panel-backup', label: '备份' },
      { name: 'panel-logs', label: '日志' },
    ],
  },
]

const isPublic = computed(() => !!route.meta.public)
const title = computed(() => route.meta.title || '管理后台')
const siteName = computed(() => site.data.site_name || '博客')

/** 内容的新建/编辑页要归到「内容管理」这个导航项下高亮 */
function isActive(name) {
  if (route.name === name) return true
  if (name === 'panel-contents') {
    return String(route.name || '').startsWith('panel-content')
  }
  return false
}

async function logout() {
  if (loggingOut.value) return
  loggingOut.value = true

  try {
    await post('auth.logout')
  } catch (err) {
    // 会话可能本来就过期了 —— 登出失败也不该把人卡在后台
    toastError(err)
  } finally {
    markUnauthorized()
    loggingOut.value = false
    toast('已退出登录', 'success')
    router.replace({ name: 'panel-login' })
  }
}
</script>

<template>
  <!-- 登录页：不套外壳 -->
  <div v-if="isPublic" class="login">
    <RouterView />
    <ToastStack />
  </div>

  <div v-else class="panel">
    <aside class="panel-side">
      <div class="panel-side__brand">
        <span>{{ siteName }}</span>
        <span class="faint tiny">管理后台</span>
      </div>

      <nav class="panel-nav" aria-label="后台导航">
        <template v-for="group in navGroups" :key="group.label">
          <div class="panel-nav__group">{{ group.label }}</div>
          <RouterLink
            v-for="item in group.items"
            :key="item.name"
            class="panel-nav__link"
            :class="{ 'is-active': isActive(item.name) }"
            :to="{ name: item.name }"
          >
            {{ item.label }}
          </RouterLink>
        </template>
      </nav>

      <div class="panel-side__foot">
        <a href="./" target="_blank" rel="noopener" class="panel-nav__link">查看前台 ↗</a>
        <button
          class="panel-nav__link panel-nav__link--button"
          type="button"
          :disabled="loggingOut"
          @click="logout"
        >{{ loggingOut ? '退出中…' : '退出登录' }}</button>
      </div>
    </aside>

    <div class="panel-main">
      <header class="panel-topbar">
        <h1 class="panel-topbar__title">{{ title }}</h1>
        <div class="panel-topbar__actions">
          <ThemeToggle />
        </div>
      </header>

      <div class="panel-body">
        <RouterView />
      </div>
    </div>

    <ToastStack />
  </div>
</template>
