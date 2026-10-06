<script setup>
import { onMounted } from 'vue'

onMounted(() => {
  document.title = '页面不存在'
})
</script>

<template>
  <div class="empty">
    <h1 style="margin-top:0">页面不存在</h1>
    <p>链接可能已经失效，或者本来就不存在。</p>
    <RouterLink class="btn" :to="{ name: 'home' }">返回首页</RouterLink>
  </div>
</template>
