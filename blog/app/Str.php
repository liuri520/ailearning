<?php
/**
 * Str.php —— 字符串工具：slug 生成、截断、转义、随机串、站点 URL
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Str
{
    /** slug 白名单（SPEC §9.5）：小写字母数字，单词间单个连字符，长度 ≤ 191 */
    private const SLUG_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public static function isValidSlug(string $s): bool
    {
        return $s !== '' && strlen($s) <= 191 && preg_match(self::SLUG_RE, $s) === 1;
    }

    /**
     * 由任意文本生成合法 slug。
     * 零依赖环境无法做中文转拼音，非 ASCII 字符会被剥离；
     * 剥离后为空则退化为 {fallback}-{6位随机}，后台可再手工修改。
     */
    public static function slug(string $input, string $fallback = 'post'): string
    {
        $s = strtolower(trim($input));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim((string) $s, '-');
        $s = preg_replace('/-+/', '-', $s) ?? '';

        if ($s === '') {
            $s = $fallback . '-' . substr(sha1($input . microtime(true)), 0, 6);
        }

        return mb_substr($s, 0, 191);
    }

    /**
     * 生成库内唯一的 slug。冲突时追加 -2 / -3 …（SPEC §10.1「slug 重复」）。
     * 调用方需保证 $base 已通过 slug() 规范化。
     */
    public static function uniqueSlug(string $base, ?int $excludeContentId = null): string
    {
        $base = mb_substr($base, 0, 180);   // 留出后缀余量
        $candidate = $base;
        $i = 1;

        while (true) {
            $sql = 'SELECT id FROM contents WHERE slug = ?';
            $params = [$candidate];
            if ($excludeContentId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $excludeContentId;
            }
            $sql .= ' LIMIT 1';

            if (Db::val($sql, $params) === null) {
                return $candidate;
            }

            $i++;
            $candidate = $base . '-' . $i;
            if ($i > 500) {
                // 异常保护，避免死循环
                return $base . '-' . substr(sha1((string) microtime(true)), 0, 8);
            }
        }
    }

    /** HTML 转义。输出到 HTML 的一律经过它（SPEC §9.5） */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 截断纯文本，用于摘要与列表展示 */
    public static function excerpt(?string $text, int $len = 120): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        return mb_strlen($t) <= $len ? $t : mb_substr($t, 0, $len) . '…';
    }

    /**
     * 站点 URL：自动补 APP_BASE（SPEC §4.2）。
     * 图片 URL 不走这里 —— 那是 asset_url() 的职责，两者不要混用。
     */
    public static function siteUrl(string $path = ''): string
    {
        $base = defined('APP_BASE') ? rtrim(APP_BASE, '/') : '';
        $path = ltrim($path, '/');
        return $path === '' ? ($base === '' ? '/' : $base . '/') : $base . '/' . $path;
    }

    /** 绝对 URL（供 feed / share 的 OG 标签使用） */
    public static function absoluteUrl(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . self::siteUrl($path);
    }

    /** 随机字符串，用于后台入口名、文件名等 */
    public static function random(int $len = 8, string $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** 计算正文词数（中文按字计，英文按词计，粗略即可） */
    public static function wordCount(string $text): int
    {
        $cn = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
        $en = preg_match_all('/[A-Za-z0-9]+/', $text);
        return (int) $cn + (int) $en;
    }
}
