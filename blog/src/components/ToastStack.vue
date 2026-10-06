<script setup>
import { toasts, dismissToast } from '../lib/store.js'
</script>

<template>
  <div class="toast-stack" role="status" aria-live="polite">
    <div
      v-for="item in toasts"
      :key="item.id"
      class="toast"
      :class="`toast--${item.type}`"
      @click="dismissToast(item.id)"
    >
      {{ item.message }}
    </div>
  </div>
</template>
