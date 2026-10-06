/**
 * theme-boot.js —— 主题预置（普通脚本，非模块）
 *
 * 【为什么是独立文件而不是内联】页面的 CSP 是 `script-src 'self'`，
 * 内联脚本会被浏览器直接拦下 —— 于是这段「防深色模式白屏闪烁」的代码
 * 一行都不会执行，控制台还会每次都报一条 CSP 违规。
 *
 * 【为什么不用 type="module"】模块脚本是延迟执行的（defer），
 * 等它跑起来 HTML 已经画完一帧了，闪烁照样发生。
 * 普通脚本同步执行，才能赶在首次绘制之前把 data-theme 写上去。
 *
 * 本文件位于 src/public/，构建时由 Vite 原样复制到站点根目录 —— 没有内容哈希，
 * 因为 <script src> 里引用的是固定文件名，改内容后靠 Cache-Control 兜住。
 */
(function () {
  try {
    var saved = localStorage.getItem('theme')
    var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    document.documentElement.setAttribute('data-theme', theme)
  } catch (e) {
    document.documentElement.setAttribute('data-theme', 'light')
  }
})()
