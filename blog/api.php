<?php
/**
 * api.php —— 唯一 API 入口（SPEC D5 / §4.1）
 *
 * 动词走查询参数：./api.php?action=<域>.<方法>
 * 不使用任何 URL 重写（SPEC §2.1：线上 .htaccess 重写不可用）。
 */
define('APP_BOOT', true);

require __DIR__ . '/app/bootstrap.php';

Router::dispatch();
