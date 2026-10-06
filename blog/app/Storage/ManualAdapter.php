<?php
/**
 * ManualAdapter.php —— 手动登记外链（SPEC §7.2.4）
 *
 * 用于处理「你已经手动传好的图」。
 * 第一版保留这条通道，但它有明确的能力缺口：
 *   · 不能上传（文件不在我们手上）
 *   · 不能撤销（别人的图，无权撤销）
 *   · 不能变换尺寸
 *   · **不剥离 EXIF** → 后台登记表单必须给出隐私提示（SPEC §9.6 例外条款）
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class ManualAdapter implements StorageAdapter
{
    public function put(string $tmpPath, string $remoteName, string $mime): array
    {
        throw new StorageException('STORAGE_ERROR', '手动登记通道不支持上传', 400);
    }

    /** 别人的图，无权撤销。返回 false 让调用方记录日志并继续。 */
    public function revoke(array $asset, bool $deleteFile = false): bool
    {
        return false;
    }

    /** 无法枚举远端直链 */
    public function listLinks(): ?array
    {
        return null;
    }

    /** 外链同样可以巡检（SPEC §7.2.4） */
    public function probe(string $relPath): array
    {
        if (!preg_match('#^https?://#i', $relPath)) {
            return ['ok' => false, 'http_code' => null, 'error' => '不是合法的 http(s) URL'];
        }

        $ch = curl_init($relPath);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            // 超时视为 unknown，不误判为失效
            return ['ok' => false, 'http_code' => null, 'error' => $err !== '' ? $err : '网络错误'];
        }

        return [
            'ok'        => $code >= 200 && $code < 300,
            'http_code' => $code,
            'error'     => ($code >= 200 && $code < 300) ? null : ('HTTP ' . $code),
        ];
    }

    public function url(array $asset, bool $thumb = false): ?string
    {
        return Asset::url($asset, $thumb);
    }

    public function supportsUpload(): bool      { return false; }
    public function supportsRevoke(): bool      { return false; }
    public function supportsHardDelete(): bool  { return false; }
    public function supportsTransform(): bool   { return false; }
    /** 【重要】图床不会剥离元数据 → 后台必须提示用户自行清理（SPEC §9.6） */
    public function stripsExif(): bool          { return false; }
}
