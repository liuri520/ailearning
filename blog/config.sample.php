<?php
/**
 * config.sample.php —— 配置模板
 *
 * 用法：复制为 config.php 并填写。config.php 是唯一含密钥的文件，永不覆盖（SPEC §4.1）。
 *
 * 【安全】首行守卫：直接通过 Web 访问本文件时立即 404。
 * 这一行不得删除或移到文件末尾（SPEC §9.4-2）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

// ── 数据库（SPEC §2.1：MySQL 5.6.51）─────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'blog_dev');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── 站点 ────────────────────────────────────────────────────
// 子目录前缀。安装时由 install.php 自动探测写入（SPEC §4.2）。
// 站点部署在域名根时留空字符串 ''。
define('APP_BASE', '/blog');

// 生产环境必须为 false（SPEC §9.7）。true 时错误详情对所有人可见。
define('APP_DEBUG', true);

// ── 密钥：不得写入 settings 表（SPEC §7.2.2 / R7）────────────
// 后台入口随机名（8 位字符串）。由 install.php 生成。
define('ADMIN_ENTRY', '');

// 钛盘 API Key。放在这里而不是 settings 表 —— 那张表会返回给前端。
define('TTTTT_API_KEY', '');

// 浏览去重与登录限流的 IP 哈希盐。install.php 会自动生成。
define('IP_SALT', '');
