<?php
/**
 * Scheduler.php —— 伪 cron 调度器（SPEC §7.1）
 *
 * SPEC 原文：「这是本项目最容易写崩的地方，务必按此实现。」
 *
 * 三条不可动的规则：
 *   1. 取锁用「UPDATE ... WHERE 影响行数」判断，**不用** SELECT ... FOR UPDATE SKIP LOCKED
 *      —— MySQL 5.6 不支持后者（SPEC §2.2 / §7.1）。
 *   2. 预算靠分批控制，不靠超时 —— PHP-FPM 的 request_terminate_timeout 无法从脚本内解除，
 *      故单次任务总墙钟硬性 < 20 秒（SPEC §7.1 步骤 0）。
 *   3. 任何异常只写日志，绝不向上抛 —— 不能因为定时任务导致 API 500（SPEC §7.1-3）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Scheduler
{
    /** 已注册「响应后执行」 */
    private static bool $pending = false;

    /** @var array<string, string> task_key => lock_owner，供兜底释放 */
    private static array $heldLocks = [];

    /** 单次执行墙钟预算（秒）—— 见 §7.1 步骤 0 */
    private const BUDGET_ONLINE = 12.0;   // fastcgi_finish_request 可用：先响应，后执行
    private const BUDGET_LOCAL  = 5.0;    // 本地 mod_php：用户要等，只能给很少预算（SPEC §11.1）

    /** 锁超时：超过 60 秒视为死锁，可被抢占（SPEC §7.1 步骤 1a） */
    private const LOCK_TIMEOUT = 60;

    /** 任意 task 的单条处理循环里，检查预算的粒度 */
    private const BUDGET_CHECK_EVERY = 1;

    // ── 触发点 ──────────────────────────────────────────────

    /** 触发点 1：前台概率触发（SPEC §7.1）。由 bootstrap 调用。 */
    public static function maybeTrigger(): void
    {
        try {
            $probability = (int) Settings::get('cron_probability', 2);
            if ($probability <= 0 || random_int(1, 100) > $probability) {
                return;
            }
            self::scheduleAfterResponse();
        } catch (Throwable $e) {
            // 引导阶段绝不能因调度失败中断请求（SPEC §7.1-3）
            ErrorHandler::log($e);
        }
    }

    /** 触发点 2：后台登录成功后补跑一轮（SPEC §7.1） */
    public static function runOnLogin(): void
    {
        try {
            self::scheduleAfterResponse();
        } catch (Throwable $e) {
            ErrorHandler::log($e);
        }
    }

    /**
     * 触发点 3：后台手动执行单个任务（admin.task.run）
     * @return array{result:string, duration_ms:int}
     */
    public static function runOne(string $taskKey): array
    {
        $started = microtime(true);
        $deadline = $started + self::budget();

        $task = Db::one('SELECT * FROM tasks WHERE task_key = ?', [$taskKey]);
        if ($task === null) {
            return ['result' => '任务不存在', 'duration_ms' => 0];
        }

        $result = self::runTask($task, $deadline, ignoreInterval: true);
        return [
            'result'      => $result ?? '未执行',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    // ── 响应后执行 ──────────────────────────────────────────

    private static function scheduleAfterResponse(): void
    {
        if (self::$pending) {
            return;
        }
        self::$pending = true;

        register_shutdown_function([self::class, 'onShutdown']);
        // 兜底释放锁，防止致命错误留下死锁（SPEC §7.1-2）
        register_shutdown_function([self::class, 'releaseAllLocks']);
    }

    /** 由 shutdown 回调触发：先把响应推给客户端，再执行任务 */
    public static function onShutdown(): void
    {
        if (!self::$pending) {
            return;
        }

        try {
            if (function_exists('fastcgi_finish_request')) {
                // 结束输出缓冲 → 关闭客户端连接，用户零感知（SPEC §7.1 步骤 0）
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @fastcgi_finish_request();

                ignore_user_abort(true);
                @set_time_limit(0);
                $budget = self::BUDGET_ONLINE;
            } else {
                // 本地 Apache + mod_php（SPEC §11.1）。
                // 没有 fastcgi_finish_request，用户会一直等到任务跑完，因此只给 5 秒。
                $budget = self::BUDGET_LOCAL;
            }

            self::runDue($budget);
        } catch (Throwable $e) {
            ErrorHandler::log($e);
        }
    }

    /**
     * 跑一轮到期任务。
     * @return array<string, string> task_key => 结果
     */
    public static function runDue(float $budget = self::BUDGET_ONLINE): array
    {
        $results = [];
        $deadline = microtime(true) + $budget;

        try {
            $tasks = Db::all('SELECT * FROM tasks ORDER BY id');
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            return $results;
        }

        foreach ($tasks as $task) {
            // 预算用尽 → 立即停止本轮（未跑的任务下轮继续，不影响正确性）
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $result = self::runTask($task, $deadline);
                if ($result !== null) {
                    $results[$task['task_key']] = $result;
                }
            } catch (Throwable $e) {
                // 单个任务失败不影响其他任务，更不影响主请求（SPEC §7.1-3）
                ErrorHandler::log($e);
            }
        }

        return $results;
    }

    // ── 单个任务 ────────────────────────────────────────────

    private static function runTask(array $task, float $deadline, bool $ignoreInterval = false): ?string
    {
        $key = (string) $task['task_key'];

        if (!$ignoreInterval && !self::isDue($task)) {
            return null;   // 周期未到（SPEC §7.1 步骤 1b）
        }

        $owner = Str::random(16);
        $affected = Db::exec(
            'UPDATE tasks
                SET locked_at = NOW(), lock_owner = ?
              WHERE task_key = ?
                AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL ' . self::LOCK_TIMEOUT . ' SECOND)',
            [$owner, $key]
        );

        if ($affected === 0) {
            return null;   // 别人在跑（SPEC §7.1 步骤 1a）
        }

        self::$heldLocks[$key] = $owner;
        $started = microtime(true);
        $result = '未实现';

        try {
            $method = self::handlerFor($key);
            if ($method !== null && method_exists(self::class, $method)) {
                $result = (string) self::{$method}($deadline);
            }
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            $result = '异常：' . Str::excerpt($e->getMessage(), 120);
        } finally {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            try {
                Db::q(
                    'UPDATE tasks
                        SET last_run_at = NOW(), duration_ms = ?, last_result = ?,
                            locked_at = NULL, lock_owner = NULL
                      WHERE task_key = ? AND lock_owner = ?',
                    [$durationMs, mb_substr($result, 0, 250), $key, $owner]
                );
            } catch (Throwable $e) {
                ErrorHandler::log($e);
            }
            unset(self::$heldLocks[$key]);
        }

        return $result;
    }

    /** 兜底释放锁（register_shutdown_function） */
    public static function releaseAllLocks(): void
    {
        foreach (self::$heldLocks as $key => $owner) {
            try {
                Db::q(
                    'UPDATE tasks SET locked_at = NULL, lock_owner = NULL WHERE task_key = ? AND lock_owner = ?',
                    [$key, $owner]
                );
            } catch (Throwable $e) {
                // 兜底路径，静默
            }
            unset(self::$heldLocks[$key]);
        }
    }

    // ── 辅助 ────────────────────────────────────────────────

    private static function budget(): float
    {
        return function_exists('fastcgi_finish_request') ? self::BUDGET_ONLINE : self::BUDGET_LOCAL;
    }

    /** 周期检查：last_run_at + 周期 > NOW() → 未到期 */
    private static function isDue(array $task): bool
    {
        if (empty($task['last_run_at'])) {
            return true;
        }
        $period = self::periodFor((string) $task['task_key']);
        if ($period <= 0) {
            return true;
        }
        return strtotime((string) $task['last_run_at']) + $period <= time();
    }

    /**
     * 任务注册表：task_key => [处理方法, 周期秒数]（SPEC §7.1 任务清单）
     * public 以便后台任务页展示「周期」—— 任务页据此渲染，避免周期数字两处维护。
     */
    public static function registry(): array
    {
        return [
            'asset_check'    => ['taskAssetCheck',    12 * 3600],   // 12 小时，单批 20 条
            'expires_watch'  => ['taskExpiresWatch',  24 * 3600],   // 1 天，单批 200 条
            'view_aggregate' => ['taskViewAggregate',     3600],    // 1 小时，单批 500 条
            'view_gc'        => ['taskViewGc',        24 * 3600],   // 1 天，单批 1000 条
            'cache_gc'       => ['taskCacheGc',       24 * 3600],
            'backup_remind'  => ['taskBackupRemind',  24 * 3600],
            'login_gc'       => ['taskLoginGc',       24 * 3600],
            'trash_gc'       => ['taskTrashGc',       24 * 3600],
        ];
    }

    private static function handlerFor(string $taskKey): ?string
    {
        $reg = self::registry();
        return $reg[$taskKey][0] ?? null;
    }

    private static function periodFor(string $taskKey): int
    {
        $reg = self::registry();
        return $reg[$taskKey][1] ?? 0;
    }

    /** 预算是否已用尽（供各任务的分批循环调用） */
    private static function outOfBudget(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    // ── 任务实现（SPEC §7.1 任务清单）────────────────────────

    /**
     * 巡检图片直链存活（单批 20 条）。
     * 优先走 list_of_direct 循环翻页做集合比对 —— 一次请求胜过 N 次探测；
     * 接口不可用时降级为逐条 HEAD（T3 已确认无防盗链，无需 Range 兼容层）。
     */
    private static function taskAssetCheck(float $deadline): string
    {
        return Asset::cronCheck($deadline, 20);
    }

    /**
     * 检查非永久模式资产是否过期（单批 200 条）。
     * 这是防止误用非永久模式导致图片集体消失的保险丝。
     */
    private static function taskExpiresWatch(float $deadline): string
    {
        return Asset::cronExpiresWatch($deadline, 200);
    }

    /** 把 view_seen 明细聚合进 view_daily（单批 500 条） */
    private static function taskViewAggregate(float $deadline): string
    {
        // 无 CTE / 窗口函数 → 普通 GROUP BY（SPEC §2.2）
        $rows = Db::all(
            'SELECT content_id, stat_date, COUNT(*) AS pv
               FROM view_seen
              GROUP BY content_id, stat_date
              ORDER BY stat_date
              LIMIT 500'
        );

        $count = 0;
        foreach ($rows as $row) {
            if (self::outOfBudget($deadline)) {
                break;
            }
            // UV 计算需要明细去重，此处 pv 直接累加；uv 由 visit 记数时的 visitor_hash 去重保证
            Db::q(
                'INSERT INTO view_daily (content_id, stat_date, pv, uv) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE pv = VALUES(pv), uv = VALUES(uv)',
                [
                    (int) $row['content_id'],
                    (string) $row['stat_date'],
                    (int) $row['pv'],
                    (int) $row['pv'],
                ]
            );
            $count++;
        }

        return "聚合 {$count} 组浏览明细";
    }

    /** 删除 7 天前的 view_seen 明细（单批 1000 条） */
    private static function taskViewGc(float $deadline): string
    {
        $deleted = Db::exec(
            'DELETE FROM view_seen WHERE stat_date < CURDATE() - INTERVAL 7 DAY LIMIT 1000'
        );
        return "清理 {$deleted} 条浏览明细";
    }

    /** 删除过期缓存文件 */
    private static function taskCacheGc(float $deadline): string
    {
        $deleted = Cache::gc();
        return "清理 {$deleted} 个缓存文件";
    }

    /** 计算距上次导出的天数，写进 settings 供后台首页展示（SPEC §11.4） */
    private static function taskBackupRemind(float $deadline): string
    {
        $last = (string) Settings::get('last_export_at', '');
        if ($last === '') {
            Settings::set('backup_remind_days', '-1');   // -1 表示从未导出
            return '从未导出，已提醒';
        }
        $days = (int) floor((time() - strtotime($last)) / 86400);
        Settings::set('backup_remind_days', (string) $days);
        return "距上次导出 {$days} 天";
    }

    /** 清理 7 天前的登录尝试记录 */
    private static function taskLoginGc(float $deadline): string
    {
        $deleted = Db::exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 7 DAY');
        return "清理 {$deleted} 条登录记录";
    }

    /** 把回收站满 30 天的内容标记为待彻底删除 —— 只提醒，不自动删（SPEC §7.1） */
    private static function taskTrashGc(float $deadline): string
    {
        $count = (int) Db::val(
            "SELECT COUNT(*) FROM contents
              WHERE status = 'trashed'
                AND deleted_at IS NOT NULL
                AND deleted_at < NOW() - INTERVAL 30 DAY"
        );
        Settings::set('trash_pending_count', (string) $count);
        return $count > 0 ? "{$count} 篇内容在回收站已满 30 天，待彻底删除" : '回收站无待清理内容';
    }
}
