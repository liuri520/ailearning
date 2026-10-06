<?php
/**
 * bootstrap.php —— 应用引导
 *
 * 【重要】下面的初始化顺序是固定的，不要调换理由见 TECHNICAL_PLAN §3.1。
 * 直接请求本文件 → 404（SPEC §9.4-3）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

// ── ① 时区 ───────────────────────────────────────────────────
// SPEC D22：全部时间按 Asia/Shanghai 处理，数据库存本地时间，不做 UTC 转换。
date_default_timezone_set('Asia/Shanghai');

// ── ② 错误处理 ───────────────────────────────────────────────
// 生产静默输出，但全部记录（SPEC §9.7）。
ini_set('display_errors', '0');
ini_set('log_errors', '0');   // 由 ErrorHandler 自己写 data/logs/
error_reporting(E_ALL);

// ── ③ 常量（唯一含密钥的文件）────────────────────────────────
define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => '站点尚未安装：缺少 config.php，请先运行 install.php']],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
require $configFile;

// ── ④ 手写 autoload（零 Composer，SPEC §2.1）─────────────────
spl_autoload_register(static function (string $class): void {
    // ① 常规映射：'Storage\TttttAdapter' → app/Storage/TttttAdapter.php
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = __DIR__ . '/' . $relative;
    if (is_file($path)) {
        require $path;
        return;
    }

    // ② 兜底：类文件按职责放在子目录、但类名不声明命名空间（app/Storage/ 就是这样）。
    //
    // 【为什么需要这一层】本项目整体是无命名空间的扁平风格，类名一律是裸名，
    // 连 17 处调用点写的都是 `new TttttAdapter()`。只有 Storage/ 按职责分了目录，
    // 于是 ① 去找 app/TttttAdapter.php 必然落空，抛
    // «Class "TttttAdapter" not found» —— 而且是在 shutdown 的伪 cron 里抛，
    // 页面照样 200，报错只进日志（见 DEBUG.md D-07）。
    //
    // 与其为迁就加载器给那 4 个类加命名空间、再改 17 处调用点，
    // 不如让加载器认识子目录。新增子目录时把名字加进下面这个列表即可。
    $leaf = basename($relative);
    foreach (['Storage'] as $sub) {
        $alt = __DIR__ . '/' . $sub . '/' . $leaf;
        if (is_file($alt)) {
            require $alt;
            return;
        }
    }
});

// ── ⑤ 全局异常 / 错误 → 统一处理（SPEC §9.7）─────────────────
set_exception_handler([ErrorHandler::class, 'handle']);
set_error_handler([ErrorHandler::class, 'handleError']);
register_shutdown_function([ErrorHandler::class, 'handleFatal']);

// ── ⑥ 会话（SPEC §9.1）───────────────────────────────────────
Auth::startSession();

// ── ⑦ 安全响应头（SPEC §9.8）─────────────────────────────────
Response::securityHeaders();

// ── ⑧ 概率触发伪 cron（SPEC §7.1 触发点 1）───────────────────
// 必须放在最后：它内部注册 shutdown 回调，任何前置失败都不应触发调度。
Scheduler::maybeTrigger();
