<?php
/**
 * Settings.php —— settings 表的键值配置读写
 *
 * 配置项 key 清单见 SPEC 附录 B，不得增删改名。
 * 【注意】密钥类常量（DB_* / TTTTT_API_KEY / IP_SALT / ADMIN_ENTRY / APP_BASE / APP_DEBUG）
 * 只在 config.php，**不入本表** —— 本表会经 admin.setting.get 返回给前端（SPEC §7.2.2 / R7）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Settings
{
    /** @var array<string, string|null>|null 请求内缓存，避免重复查询 */
    private static ?array $cache = null;

    /** @return array<string, string|null> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT `key`, `value` FROM settings') as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        if (!array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }
        return $all[$key];
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : ((int) $v === 1);
    }

    /**
     * 写入一项。
     * 用 REPLACE INTO 而非 ON DUPLICATE KEY UPDATE ... VALUES()：
     * 后者在 MySQL 8.0.20+ 已废弃，前者在 5.6 / 8.0 上行为一致（SPEC §2.2 迁移友好性要求）。
     */
    public static function set(string $key, $value): void
    {
        Db::q(
            'REPLACE INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)',
            [$key, $value === null ? null : (string) $value, date('Y-m-d H:i:s')]
        );
        self::$cache = null;
    }

    /** @param array<string, mixed> $kv */
    public static function setMany(array $kv): void
    {
        foreach ($kv as $k => $v) {
            self::set((string) $k, $v);
        }
    }

    /** 删除一项（如重置配置） */
    public static function forget(string $key): void
    {
        Db::q('DELETE FROM settings WHERE `key` = ?', [$key]);
        self::$cache = null;
    }

    /**
     * 缓存版本自增 —— 任何内容写入时调用。
     * 旧缓存文件随即成为孤儿，由 cache_gc 清理；
     * 不做逐个删除，避免失效逻辑出错（SPEC §7.4）。
     */
    public static function bumpCacheVersion(): void
    {
        self::set('cache_version', (string) (self::int('cache_version', 1) + 1));
    }

    /** 丢弃请求内缓存（供 install.php 在写入后立即读取） */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
