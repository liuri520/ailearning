<?php
/**
 * Response.php —— 统一 JSON 响应 + 安全响应头
 *
 * 响应格式固定为 SPEC §6.1：
 *   成功 { "ok": true,  "data": ..., "meta": {...} }
 *   失败 { "ok": false, "error": { "code": "...", "message": "...", "field": "..." } }
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Response
{
    /** HTTP 状态码约定（SPEC §6.1） */
    public const OK          = 200;
    public const BAD_REQUEST = 400;
    public const UNAUTH      = 401;
    public const FORBIDDEN   = 403;
    public const NOT_FOUND   = 404;
    public const RATE_LIMIT  = 429;
    public const SERVER_ERR  = 500;

    public static function ok($data = null, ?array $meta = null, int $status = self::OK): never
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        self::emit($payload, $status);
    }

    /**
     * @param string      $code    错误码枚举，见 SPEC §6.1
     * @param string      $message 面向用户的中文文案
     * @param string|null $field   出错字段名，供表单定位
     */
    public static function fail(
        string $code,
        string $message,
        int $status = self::BAD_REQUEST,
        ?string $field = null
    ): never {
        $error = ['code' => $code, 'message' => $message];
        if ($field !== null) {
            $error['field'] = $field;
        }
        self::emit(['ok' => false, 'error' => $error], $status);
    }

    /** 后台接口禁止任何缓存 */
    public static function noCache(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    /**
     * 安全响应头（SPEC §9.8）。
     * 引导阶段即调用，此时可能尚未安装数据库，故整个查询包在 try/catch 内。
     */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        // share.php 需要 SAMEORIGIN 才能被同源 iframe 引用，不要改成 DENY（SPEC §9.8）
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // img-src 白名单：图床直链域名已确认（SPEC §9.8 收紧建议）。
        // 手动登记的外链（is_external=1）需把域名写进 settings.cdn_whitelist，否则会被 CSP 拦截。
        $imgSrc = "img-src 'self' data: https://download.wuyuge.cn";
        // frame-src：bilibili / YouTube 是内置的两种嵌入平台。
        // 其余第三方播放器（自建 player、Vimeo 等）必须在设置里显式加白名单 ——
        // 否则 iframe 会被浏览器拦成一片空白，而那是最难排查的一种失败：
        // 页面上没有任何报错，只有控制台里一条 CSP 违规。
        $frameSrc = "frame-src player.bilibili.com www.youtube.com";

        try {
            foreach (self::domainList('cdn_whitelist') as $host) {
                $imgSrc .= ' ' . $host;
            }
            foreach (self::domainList('frame_whitelist') as $host) {
                $frameSrc .= ' ' . $host;
            }
        } catch (Throwable $e) {
            // 未安装或数据库不可用：退回最宽松值，避免引导阶段直接崩溃
            $imgSrc = "img-src 'self' data: https:";
        }

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . $imgSrc . '; '
            . "media-src 'self' https:; "                              // 视频源可切换，暂时放宽
            . $frameSrc . '; '
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "                      // Vue 内联样式需要
            . "connect-src 'self'"
        );
    }

    /**
     * 把设置里的一串域名拆成 CSP 可用的列表。
     *
     * 用空白和逗号分隔都能接受 —— 用户在设置页里可能一行一个，也可能用逗号连写。
     *
     * 这里只做拆分，不做校验：写入端 admin_normalize_domains() 已经把非法字符
     * 挡在库外了。在这里再拒一次，只会让「存进去的」和「发出去的」不一致 ——
     * 设置页显示着某个域名、响应头里却没有，那种偏差比多一个无意义的来源难查得多。
     *
     * @return string[]
     */
    private static function domainList(string $key): array
    {
        $raw = (string) Settings::get($key, '');
        return preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function emit(array $payload, int $status): never
    {
        // 清掉任何意外产生的输出（PHP 警告、BOM、前置空行），保证返回体是合法 JSON
        // 对应 SPEC §10.4「API 返回非 JSON」这一条缺陷
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
