<script setup>
import { theme, toggleTheme } from '../lib/theme.js'
</script>

<template>
  <button
    class="theme-toggle"
    type="button"
    :title="theme === 'dark' ? '切换到明亮主题' : '切换到暗色主题'"
    :aria-label="theme === 'dark' ? '切换到明亮主题' : '切换到暗色主题'"
    @click="toggleTheme"
  >
    <svg v-if="theme === 'dark'" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
    <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
    </svg>
  </button>
</template>
