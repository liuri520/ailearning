<?php
/**
 * panel-template.php —— 后台 SPA 入口模板
 *
 * install.php 会把它**重命名**为 panel-<8位随机串>.php（SPEC §9.1）：
 * 访问 panel.php / admin.php 一律 404 —— 因为文件本身不存在。
 * 随机串同时写入 config.php 的 ADMIN_ENTRY 常量（不入库，避免设置页泄露）。
 *
 * 本文件是合法的 Web 入口，故**不**加 APP_BOOT 守卫。
 */
define('APP_BOOT', true);
require __DIR__ . '/app/bootstrap.php';

$htmlFile = __DIR__ . '/panel.html';

if (!is_file($htmlFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("后台前端尚未构建：缺少 panel.html。\n请在本地执行 npm run build，并把 dist/ 的内容复制到站点根目录。");
}

// 走 bootstrap 是为了让 CSP 与前台共用同一份定义（含 cdn_whitelist），
// 而不是在这里另抄一遍白名单 —— 抄一遍就一定会有一天忘记同步。
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    Response::securityHeaders();
    // 后台外壳绝不缓存：入口是随机化的，缓存只会让「入口已更换」这类
    // 情况表现得像页面打不开（SPEC §9.1）
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

readfile($htmlFile);
