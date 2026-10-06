<?php
/**
 * ErrorHandler.php —— 全局异常 / 错误 / 致命错误的统一入口（SPEC §9.7）
 *
 * 说明：SPEC §4.1 的目录清单未列出本文件，但 §9.7 要求的错误处理需要集中实现，
 * 故以独立类承载（见 TECHNICAL_PLAN §3.1 初始化顺序第 ⑤ 步）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class ErrorHandler
{
    /**
     * 未捕获异常 → 记录日志 + 返回中性 JSON。
     * 未登录用户绝不看到路径 / SQL / 堆栈（SPEC §9.7）。
     */
    public static function handle(Throwable $e): void
    {
        self::log($e);

        if (!headers_sent()) {
            // 已登录管理员或调试模式才附带详情
            $expose = (defined('APP_DEBUG') && APP_DEBUG) || Auth::isLoggedIn();
            $message = $expose ? $e->getMessage() : '服务器内部错误';
            Response::fail('INTERNAL_ERROR', $message, 500);
        }
        exit;
    }

    /**
     * PHP 运行时错误。
     *
     * 开发期：把警告转成异常，便于及早发现（SPEC §10.4「PHP 警告混入输出」视为缺陷）。
     * 生产期：只记录，不抛出 —— display_errors 已为 0，警告不会污染 JSON 输出，
     *         若在此处抛异常会把无害的警告升级成 500，反而更危险。
     *
     * @return bool true 表示已处理，交由 PHP 默认处理时返回 false
     */
    public static function handleError(int $no, string $str, string $file = '', int $line = 0): bool
    {
        // @ 抑制的错误（error_reporting 已排除）不处理
        if (!(error_reporting() & $no)) {
            return false;
        }

        // 修复提示：E_DEPRECATED 在生产也会记录，便于 8.x 升级时清理
        $e = new ErrorException($str, 0, $no, $file, $line);

        if (defined('APP_DEBUG') && APP_DEBUG) {
            throw $e;
        }

        self::log($e);
        return true;
    }

    /**
     * 致命错误兜底（E_ERROR 等无法被 set_error_handler 捕获）。
     * 由 bootstrap 注册的 register_shutdown_function 调用。
     */
    public static function handleFatal(): void
    {
        $last = error_get_last();
        if ($last === null) {
            return;
        }
        if (!in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $e = new ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
        self::log($e);

        // 此时可能有半截输出（如 HTML），若响应头未发则补一个中性 JSON
        if (!headers_sent()) {
            Response::fail('INTERNAL_ERROR', (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : '服务器内部错误', 500);
        }
    }

    /** 记录一条普通日志（非异常场景，如图床响应、任务结果） */
    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    /** 记录 Throwable 到 data/logs/error-YYYY-MM.log.php */
    public static function log(Throwable $e): void
    {
        self::write(
            get_class($e),
            $e->getMessage(),
            ['file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $e->getTraceAsString()]
        );
    }

    /** 底层写日志。文件首行为 PHP 守卫头，直接访问不会泄露内容（SPEC §9.4-1）。 */
    private static function write(string $level, string $message, array $context = []): void
    {
        $dir = APP_ROOT . '/data/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/error-' . date('Y-m') . '.log.php';
        $isNew = !is_file($file);

        $line = sprintf(
            "[%s] %s %s\n%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // 守卫头只在创建文件时写入一次
        @file_put_contents($file, ($isNew ? "<?php exit; ?>\n" : '') . $line, FILE_APPEND | LOCK_EX);
    }
}
