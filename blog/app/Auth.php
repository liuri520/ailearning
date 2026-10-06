<?php
/**
 * Auth.php —— 会话、登录限流、CSRF（SPEC §9.1 / §9.2 / §9.3）
 *
 * 单管理员：账号存在 settings 表（admin_user / admin_pass_hash）。
 * 不提供「找回密码」，忘记密码需直接改数据库（SPEC §9.1）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Auth
{
    private const SESSION_USER = 'admin_user';
    private const SESSION_CSRF = 'csrf_token';

    /** 限流阈值（SPEC §9.2）：15 分钟内失败 ≥ 5 次即锁定 */
    private const RATE_WINDOW_MINUTES = 15;
    private const RATE_MAX_FAILURES  = 5;

    // ── 会话 ────────────────────────────────────────────────

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => defined('APP_BASE') && APP_BASE !== '' ? APP_BASE : '/',
            'httponly' => true,
            // SPEC §9.1 要求 Secure。生产为 HTTPS，此处即 true；
            // 本地 XAMPP 走 http://localhost，若写死 true 会导致 cookie 根本不下发、
            // 后台无法登录，故按实际协议判定。这不降低线上安全性。
            'secure'   => self::isHttps(),
            'samesite' => 'Lax',
        ]);
        session_name('blog_sid');
        session_start();
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // 前置反向代理的情况
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    // ── 登录 / 登出 ─────────────────────────────────────────

    /**
     * 校验密码并建立会话。
     * 成功返回 ['csrf_token' => ..., 'user' => ...]；
     * 失败或超限直接由 Response::fail 终止请求。
     */
    public static function login(string $password): array
    {
        $ipHash = self::ipHash();
        self::assertNotRateLimited($ipHash);

        // 固定 300ms 延时，拖慢暴力破解，成本可忽略（SPEC §9.2）
        usleep(300_000);

        $hash = (string) Settings::get('admin_pass_hash', '');
        $ok = $hash !== '' && password_verify($password, $hash);

        self::recordAttempt($ipHash, $ok);

        if (!$ok) {
            // 无论账号是否存在，文案一致（SPEC §9.2）
            Response::fail('UNAUTHORIZED', '密码错误', Response::UNAUTH, 'password');
        }

        // 防会话固定（SPEC §9.1）
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER] = (string) Settings::get('admin_user', 'admin');

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_CSRF] = $token;

        // 触发点 2：后台登录成功后补跑一轮伪 cron（SPEC §7.1）
        // 采用「响应后执行」而非阻塞式同步：登录是交互路径，不能等任务跑完。
        Scheduler::runOnLogin();

        return ['csrf_token' => $token, 'user' => $_SESSION[self::SESSION_USER]];
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'] ?? '/',
                $p['domain'] ?? '',
                (bool) ($p['secure'] ?? false),
                (bool) ($p['httponly'] ?? true)
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION[self::SESSION_USER]);
    }

    /** 后台接口统一前置守卫 */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            Response::fail('UNAUTHORIZED', '请先登录', Response::UNAUTH);
        }
    }

    public static function currentUser(): ?string
    {
        return self::isLoggedIn() ? (string) $_SESSION[self::SESSION_USER] : null;
    }

    /**
     * 修改密码（后台设置页使用）。同时轮换 CSRF token。
     */
    public static function changePassword(string $newPassword): void
    {
        Settings::set('admin_pass_hash', password_hash($newPassword, PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
    }

    // ── CSRF（SPEC §9.3）────────────────────────────────────

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_CSRF];
    }

    /**
     * 校验写操作携带的 CSRF token。
     * 缺失 / 不匹配 → 403（SPEC §9.3）。
     */
    public static function verifyCsrf(): void
    {
        $known = (string) ($_SESSION[self::SESSION_CSRF] ?? '');
        $sent  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');

        if ($known === '' || !is_string($sent) || !hash_equals($known, $sent)) {
            Response::fail('CSRF_INVALID', 'CSRF 校验失败，请刷新页面后重试', Response::FORBIDDEN);
        }
    }

    // ── 限流（SPEC §9.2）────────────────────────────────────

    private static function assertNotRateLimited(string $ipHash): void
    {
        try {
            $failures = (int) Db::val(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE ip_hash = ? AND success = 0
                    AND attempted_at > NOW() - INTERVAL ' . self::RATE_WINDOW_MINUTES . ' MINUTE',
                [$ipHash]
            );
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            return;   // 限流表不可用时放行，避免把整个后台锁死
        }

        if ($failures >= self::RATE_MAX_FAILURES) {
            Response::fail(
                'RATE_LIMITED',
                '登录尝试过于频繁，请 ' . self::RATE_WINDOW_MINUTES . ' 分钟后再试',
                Response::RATE_LIMIT
            );
        }
    }

    private static function recordAttempt(string $ipHash, bool $success): void
    {
        try {
            Db::insert('login_attempts', [
                'ip_hash'      => $ipHash,
                'attempted_at' => date('Y-m-d H:i:s'),
                'success'      => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            // 记录失败不应阻塞登录流程
            ErrorHandler::log($e);
        }
    }

    /** ip_hash = sha256(ip + 盐)，不存明文 IP（SPEC §5.5 / §9.2） */
    public static function ipHash(): string
    {
        return hash('sha256', self::clientIp() . (defined('IP_SALT') ? IP_SALT : ''));
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * 浏览去重用的 visitor_hash = sha256(ip + UA + 当日盐)（SPEC §7.5）
     */
    public static function visitorHash(string $dailySalt): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', self::clientIp() . $ua . $dailySalt);
    }
}
