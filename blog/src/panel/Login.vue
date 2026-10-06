<script setup>
/**
 * Login.vue —— 后台登录
 *
 * 服务端已做了限流（15 分钟内 5 次失败）与固定 300ms 延时（SPEC §9.2），
 * 前端**不要**再加自己的限流或延时 —— 两层节流只会让正常用户误以为卡死。
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { post, setCsrfToken } from '../lib/api.js'
import { toast } from '../lib/store.js'
import { ensureSession } from '../router/panel.js'

const route = useRoute()
const router = useRouter()

const password = ref('')
const loading = ref(false)

onMounted(async () => {
  // 已经登着就直接进去（刷新页面时不必重输）
  if (await ensureSession(true)) {
    router.replace(route.query.redirect || { name: 'panel-dashboard' })
  }
})

async function submit() {
  if (!password.value || loading.value) return

  loading.value = true
  try {
    const { data } = await post('auth.login', { password: password.value })
    setCsrfToken(data.csrf_token)

    // 登录后强制重新确认会话，避免缓存里还留着「未登录」的判断
    await ensureSession(true)

    toast('登录成功', 'success')
    router.replace(String(route.query.redirect || '/'))
  } catch (err) {
    // 具体原因由服务端决定（限流 / 密码错误），原样展示即可
    toast(err.message, 'error')
    password.value = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="login__card" @submit.prevent="submit">
    <h1 class="login__title">管理后台</h1>

    <div class="form__row">
      <label class="form__label" for="panel-password">密码</label>
      <input
        id="panel-password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        :disabled="loading"
        autofocus
      >
    </div>

    <button class="btn btn-primary btn-block" type="submit" :disabled="loading || !password">
      {{ loading ? '登录中…' : '登录' }}
    </button>

    <p class="form__hint" style="text-align:center;margin-top:var(--space-4)">
      连续 5 次失败会锁定 15 分钟
    </p>
  </form>
</template>
