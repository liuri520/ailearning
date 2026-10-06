import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

/**
 * 多页构建：前台 index.html + 后台 panel.html。
 *
 * 【为什么 base 必须是 './'】站点部署在子目录（SPEC §2.1 实测环境），
 * 绝对路径 /assets/xxx.js 会指到域名根目录而 404。相对路径则无论
 * 放在 /blog/ 还是 /blog/v2/ 都能正确加载。
 *
 * 【产物去哪】outDir 是 ../dist，与源码分离。部署时把 dist/ 的内容
 * 复制到站点根目录（与 api.php 同级），panel-template.php 才找得到 panel.html。
 */
export default defineConfig({
  root: 'src',
  base: './',
  plugins: [vue()],

  build: {
    outDir: '../dist',
    emptyOutDir: true,
    // 个人博客不追求极致拆包，但把 Vue 单独分出能让改动频繁的业务代码
    // 在重新部署后复用浏览器缓存
    rollupOptions: {
      input: {
        main: fileURLToPath(new URL('./src/index.html', import.meta.url)),
        panel: fileURLToPath(new URL('./src/panel.html', import.meta.url)),
      },
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router'],
          markdown: ['marked', 'dompurify'],
        },
      },
    },
  },

  server: {
    /*
     * 本地开发：Vite 跑在 5173，API 仍由 XAMPP 的 Apache 提供。
     * 代理掉就不必在本地另配一套 HTTPS 与跨域头。
     *
     * 【target 必须带 /blog 子目录】前端请求的是相对地址 `api.php`
     * （src/lib/api.js），在 dev server 上解析成 http://localhost:5173/api.php。
     * 而文件实际位于 XAMPP 的 htdocs/blog/ 下 —— 代理到根目录会 404，
     * 报错还只是「接口不存在」，很容易误以为是自己代码写错了。
     * 项目目录名不叫 blog 的话，覆盖 DEV_API_TARGET 即可：
     *     DEV_API_TARGET=http://localhost/别的名字 npm run dev
     */
    proxy: {
      '/api.php': {
        target: process.env.DEV_API_TARGET || 'http://localhost/blog',
        changeOrigin: true,
      },
    },
  },
})
