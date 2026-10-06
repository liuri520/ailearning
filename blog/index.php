<?php
/**
 * index.php —— 前台 SPA 入口
 *
 * 【为什么不让 Apache 直接吐 index.html】
 * 因为安全响应头（尤其 CSP）必须下发，而静态文件不经过 PHP。
 * 走 bootstrap 还有一个好处：CSP 里的 `img-src` 白名单来自
 * settings.cdn_whitelist，规则只在 Response::securityHeaders() 里维护一份，
 * 不会出现「API 的白名单更新了、页面壳的没更新」这种偏差。
 *
 * 【顺带触发了伪 cron】bootstrap 末尾会按概率跑一轮到期任务（SPEC §7.1 触发点 1），
 * 页面访问正是最自然的触发时机 —— 用户访问量本身就是任务执行的节拍器。
 *
 * 【为什么读 dist 产物而不是写死资源路径】Vite 输出的文件名带内容 hash，
 * 手写不可能跟上；这里只负责把构建好的 index.html 原样送出。
 */
define('APP_BOOT', true);
require __DIR__ . '/app/bootstrap.php';

$htmlFile = __DIR__ . '/index.html';

if (!is_file($htmlFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("前台前端尚未构建：缺少 index.html。\n请在本地执行 npm run build，并把 dist/ 的内容复制到站点根目录。");
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    Response::securityHeaders();
    // 外壳可以缓存，但必须允许重新验证 —— 否则部署新版后用户会长时间停在旧外壳
    header('Cache-Control: no-cache');
}

readfile($htmlFile);
