<script setup>
/**
 * App.vue —— 前台外壳：页头导航 + 路由出口 + 页脚
 */
import { computed } from 'vue'
import ThemeToggle from './components/ThemeToggle.vue'
import ToastStack from './components/ToastStack.vue'
import { site } from './lib/store.js'

const siteName = computed(() => site.data.site_name || '博客')
const siteDesc = computed(() => site.data.site_desc || '')
const icp = computed(() => site.data.site_icp || '')

const navItems = [
  { name: 'home', label: '首页' },
  { name: 'posts', label: '文章' },
  { name: 'videos', label: '视频' },
  { name: 'gallery', label: '图集' },
  { name: 'archive', label: '归档' },
  { name: 'tags', label: '标签' },
]
</script>

<template>
  <div class="site">
    <header class="site-header">
      <div class="site-header__inner">
        <RouterLink class="brand" :to="{ name: 'home' }">{{ siteName }}</RouterLink>

        <nav class="nav" aria-label="主导航">
          <RouterLink
            v-for="item in navItems"
            :key="item.name"
            class="nav__link"
            :to="{ name: item.name }"
          >
            {{ item.label }}
          </RouterLink>
        </nav>

        <ThemeToggle />
      </div>
    </header>

    <main class="site-main">
      <RouterView v-slot="{ Component }">
        <!--
          用 slug 做 key：从一篇文章跳到另一篇时强制重建组件。
          不加的话 Vue 会复用实例，滚动位置和内部状态（如视频是否已激活）会串篇。
        -->
        <component :is="Component" :key="$route.fullPath" />
      </RouterView>
    </main>

    <footer class="site-footer">
      <p style="margin:0 0 var(--space-2)">
        {{ siteName }}<template v-if="siteDesc"> · {{ siteDesc }}</template>
      </p>
      <p style="margin:0">
        <a href="./feed.php">RSS</a>
        ·
        <a href="./feed.php?format=json">JSON Feed</a>
        <template v-if="icp">
          <br>{{ icp }}
        </template>
      </p>
    </footer>

    <ToastStack />
  </div>
</template>
