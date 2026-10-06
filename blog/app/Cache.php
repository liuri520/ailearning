<?php
/**
 * Cache.php —— 文件缓存（默认关闭，SPEC D20 / §7.4）
 *
 * 存储：data/cache/<sha1(key)>.<cache_version>.json.php
 *
 * 【与 SPEC §7.4 的一处出入，已按 §9.4 处理】
 *   §7.4 写作 `...json`，但 §9.4-1 规定「data/ 下的所有数据文件使用 .php 扩展名，
 *   首行写 <?php exit; ?>」。线上没有 .htaccess，这是唯一可用的目录防护手段，
 *   故采用 `.json.php` + 守卫头；读取时跳过首行。缓存内容本身不含敏感数据，
 *   这样做是为了与 data/ 下其余文件（日志、导出）保持同一套防护约定。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Cache
{
    private const GUARD = "<?php exit; ?>\n";
    private const DEFAULT_TTL = 300;

    /**
     * 读缓存；未命中则执行 $fn 并写缓存。
     * 缓存关闭时直接执行 $fn（SPEC §7.4：默认关闭）。
     */
    public static function remember(string $action, array $params, callable $fn)
    {
        if (!self::enabled()) {
            return $fn();
        }

        $version = Settings::int('cache_version', 1);
        $path = self::path(self::key($action, $params, $version), $version);
        $ttl = self::ttl();

        if (is_file($path) && (time() - (int) filemtime($path)) < $ttl) {
            $value = self::read($path);
            if ($value !== null) {
                return $value;
            }
            // 文件损坏 → 视为未命中，重新生成（SPEC §10.4「缓存文件损坏」）
        }

        $value = $fn();
        self::write($path, $value);
        return $value;
    }

    /** 清理过期缓存与因 cache_version 递增产生的孤儿文件 */
    public static function gc(): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return 0;
        }

        $currentVersion = Settings::int('cache_version', 1);
        $ttl = self::ttl();
        $now = time();
        $deleted = 0;

        foreach (glob($dir . '/*.json.php') ?: [] as $file) {
            $parts = explode('.', basename($file));
            $orphan = (int) ($parts[1] ?? 0) !== $currentVersion;   // 版本已过期
            $expired = ($now - (int) @filemtime($file)) > $ttl;

            if ($orphan || $expired) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── 内部 ────────────────────────────────────────────────

    private static function enabled(): bool
    {
        try {
            return Settings::int('cache_enabled', 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function ttl(): int
    {
        return max(60, Settings::int('cache_ttl', self::DEFAULT_TTL));
    }

    /** key 组成：cache_version | action | 排序后的参数（SPEC §7.4） */
    private static function key(string $action, array $params, int $version): string
    {
        ksort($params);
        return $version . '|' . $action . '|' . json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    private static function dir(): string
    {
        return APP_ROOT . '/data/cache';
    }

    private static function path(string $key, int $version): string
    {
        return self::dir() . '/' . sha1($key) . '.' . $version . '.json.php';
    }

    private static function read(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        // 跳过守卫头
        if (str_starts_with($raw, '<?php')) {
            $pos = strpos($raw, "\n");
            $raw = $pos === false ? '' : substr($raw, $pos + 1);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }
        return $data['value'];
    }

    private static function write(string $path, $value): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($path, self::GUARD . $json, LOCK_EX);
    }
}
