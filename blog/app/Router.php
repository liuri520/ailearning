<?php
/**
 * Router.php —— action 分发
 *
 * 【安全设计】路由是**白名单**：只有显式注册过的 action 才能被调用，
 * 绝不做基于方法名的反射或动态调用。未注册一律 404。
 *
 * 处理器约定：
 *   - 返回 null            → 视为处理器已自行调用 Response::* 响应
 *   - 返回 ['data'=>..,'meta'=>..] 或其它值 → Router 统一包装成 §6.1 格式
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Router
{
    /*
     * handler 是**可调用对象**（actions/*.php 里一律传闭包），不是方法名字符串。
     *
     * 【为什么类型必须写 callable 而不是 string】
     * 这里曾经写成 string，而 45 个 Router::add() 调用点全都传闭包 ——
     * 于是每一次请求都在 Router::add() 的入口被 TypeError 拦下，
     * 整个站点只剩下一个 «must be of type string, Closure given»。
     *
     * 注意 PHP 不允许给**属性**声明 callable 类型，所以下面这个数组只能靠
     * 注解说明元素类型；也正因如此，dispatch() 里那句 is_callable() 不是多余的 ——
     * 它是这条类型链上唯一真正生效的检查。
     */
    /** @var array<string, array{handler: callable, needsLogin: bool}> */
    private static array $routes = [];

    private static bool $loaded = false;

    public static function add(string $action, callable $handler, bool $needsLogin = false): void
    {
        self::$routes[$action] = ['handler' => $handler, 'needsLogin' => $needsLogin];
    }

    public static function dispatch(): void
    {
        self::loadRoutes();

        $action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';

        // 直接访问 api.php 无 action（SPEC §10.4）：
        // 生产只回 400；开发期附上可用 action 列表便于调试
        if ($action === '') {
            $message = '缺少 action 参数';
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $public = array_keys(array_filter(self::$routes, static fn($r) => !$r['needsLogin']));
                $message .= '。可用 action：' . implode(', ', $public);
            }
            Response::fail('VALIDATION_FAILED', $message, Response::BAD_REQUEST, 'action');
        }

        if (!isset(self::$routes[$action])) {
            Response::fail('NOT_FOUND', '未知的 action', Response::NOT_FOUND, 'action');
        }

        $route = self::$routes[$action];

        // 后台域统一守卫：先验登录，写操作再验 CSRF（SPEC §9.1 / §9.3）
        if ($route['needsLogin']) {
            Auth::requireLogin();
            if (self::isWriteRequest()) {
                Auth::verifyCsrf();
            }
            Response::noCache();
        }

        $handler = $route['handler'];
        if (!is_callable($handler)) {
            ErrorHandler::warn('路由处理器不存在', ['action' => $action, 'handler' => $handler]);
            Response::fail('INTERNAL_ERROR', '服务器内部错误', Response::SERVER_ERR);
        }

        $result = $handler();

        if ($result === null) {
            // 处理器已自行响应
            return;
        }

        if (is_array($result) && array_key_exists('data', $result)) {
            Response::ok($result['data'], $result['meta'] ?? null);
        }

        Response::ok($result);
    }

    /** 写请求判定：除 GET 外的请求方法一律按写操作处理（SPEC §6.1：读 GET，写 POST） */
    private static function isWriteRequest(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return $method !== 'GET' && $method !== 'HEAD';
    }

    private static function loadRoutes(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        require __DIR__ . '/actions/public.php';
        require __DIR__ . '/actions/admin.php';
    }
}
