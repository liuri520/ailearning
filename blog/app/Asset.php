<?php
/**
 * Asset.php —— 媒体库与资产（SPEC §6.3 admin.asset.* / §7.2.5 / §10.2）
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Asset
{
    /*
     * 【与 SPEC §9.4-5 的差异，须向上汇报】
     *
     * SPEC 要求用 uploads/tmp/ 做上传中转，文件随机命名、扩展名白名单、用完即删。
     * 本实现**不落盘**：两个尺寸的 WebP 都在浏览器里用 Canvas 生成（SPEC §3.2 张力 C），
     * 服务端拿到的 $_FILES['full']['tmp_name'] 本身就是 PHP 管好的临时文件，
     * 直接以 CURLFile 交给钛盘即可。
     *
     * 不建目录 = 不必担心「删漏了一个文件」，也不必担心临时目录被填满 ——
     * 这是一次净收益的简化，不是遗漏。uploads/tmp/ 仍然保留在站点结构里，
     * 将来若要加服务端图片处理（例如抽视频帧），中转目录可以就地启用。
     */

    // ════════════════════════════════════════════════════════
    //  URL 解析（SPEC §7.2.5，四层回退）
    // ════════════════════════════════════════════════════════

    /**
     * 由 asset 记录解析出可用的图片 URL。
     *
     * 四层回退的设计意图：前三层覆盖正常路径，第四层保证
     * 「宁可显示占位图，也不吐出半截 URL」。
     *
     * @param string|bool $size 'full' | 'thumb'，或布尔值（true = thumb）
     */
    public static function url(array $asset, $size = 'full'): ?string
    {
        $isThumb = ($size === true || $size === 'thumb');

        $key    = $isThumb ? ($asset['thumb_rel_path']   ?? null) : ($asset['rel_path']        ?? null);
        $direct = $isThumb ? ($asset['thumb_direct_url'] ?? null) : ($asset['direct_url']      ?? null);
        $dkey   = $isThumb ? ($asset['thumb_dkey']       ?? null) : ($asset['remote_dkey']     ?? null);
        $name   = $isThumb ? ($asset['thumb_name']       ?? null) : ($asset['remote_name']     ?? null);

        if ($key === null || $key === '') {
            return null;
        }

        // ① 手动登记的外链 = 完整 URL，原样返回
        if (preg_match('#^https?://#i', (string) $key)) {
            return (string) $key;
        }

        // ② 已物化的直链 → 直接用（正常路径，零拼接开销）
        if ($direct !== null && $direct !== '') {
            return (string) $direct;
        }

        // ③ 用 dkey + name 按模板现场重建
        //    存在的意义：模板写错、或日后直链域名变更时，存量数据可批量自愈
        if ($dkey !== null && $dkey !== '' && $name !== null && $name !== '') {
            $built = TttttAdapter::buildDirectUrl((string) $dkey, (string) $name);
            if ($built !== null) {
                return $built;
            }
        }

        // ④ 兜不住 → null，前台走占位图
        return null;
    }

    /** 缩略版 URL；无缩略版时回退原图（调用方可据此标记「流量风险」） */
    public static function thumbUrl(array $asset): ?string
    {
        return self::url($asset, 'thumb') ?? self::url($asset, 'full');
    }

    /** 该资产是否有独立缩略版；false 表示列表页会拉原图（流量风险） */
    public static function hasThumb(array $asset): bool
    {
        return !empty($asset['thumb_rel_path']);
    }

    /**
     * 存库时的归一化（处理手动粘贴直链的场景，SPEC §7.2.5）。
     * cdn_base 的用途已收窄为「识别 URL 是否属于本图床」，不再参与 URL 构造。
     */
    public static function normalizeAssetKey(string $input): array
    {
        $input = trim($input);
        $base  = trim((string) Settings::get('cdn_base', ''));

        if ($base !== '' && str_starts_with($input, $base)) {
            // 自己的图床直链 → 剥出 UKEY 存 rel_path，同时把完整 URL 存进 direct_url
            return [
                'rel_path'    => ltrim(substr($input, strlen($base)), '/'),
                'direct_url'  => $input,
                'is_external' => 0,
            ];
        }

        // 别人的图 → 原样存，永不批量替换
        return ['rel_path' => $input, 'direct_url' => null, 'is_external' => 1];
    }

    /** 对外暴露的字段（去掉内部列），供前端渲染 */
    public static function publicShape(array $asset): array
    {
        return [
            'id'           => (int) $asset['id'],
            'provider'     => $asset['provider'] ?? 'manual',
            'is_external'  => (int) ($asset['is_external'] ?? 0) === 1,
            'url'          => self::url($asset, 'full'),
            'thumb_url'    => self::thumbUrl($asset),
            'has_thumb'    => self::hasThumb($asset),
            'width'        => isset($asset['width']) ? (int) $asset['width'] : null,
            'height'       => isset($asset['height']) ? (int) $asset['height'] : null,
            'thumb_width'  => isset($asset['thumb_width']) ? (int) $asset['thumb_width'] : null,
            'thumb_height' => isset($asset['thumb_height']) ? (int) $asset['thumb_height'] : null,
            'file_size'    => isset($asset['file_size']) ? (int) $asset['file_size'] : null,
            'check_status' => $asset['check_status'] ?? 'unknown',
            'last_error'   => $asset['last_error'] ?? null,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  适配器
    // ════════════════════════════════════════════════════════

    public static function adapter(?string $provider = null, ?float $deadline = null): StorageAdapter
    {
        $provider = $provider ?? (string) Settings::get('storage_driver', 'ttttt');

        return match ($provider) {
            'manual' => new ManualAdapter(),
            'ttttt'  => new TttttAdapter($deadline),
            default  => new ManualAdapter(),
        };
    }

    // ════════════════════════════════════════════════════════
    //  读取
    // ════════════════════════════════════════════════════════

    public static function list(array $f): array
    {
        $where = [];
        $params = [];

        $status = trim((string) ($f['check_status'] ?? ''));
        if ($status !== '' && in_array($status, ['unknown', 'ok', 'missing', 'expired'], true)) {
            $where[] = 'check_status = ?';
            $params[] = $status;
        }

        $provider = trim((string) ($f['provider'] ?? ''));
        if ($provider !== '' && in_array($provider, ['ttttt', 'manual'], true)) {
            $where[] = 'provider = ?';
            $params[] = $provider;
        }

        // 「孤立资源」：ref_count = 0（SPEC §8.4）
        if (!empty($f['orphan'])) {
            $where[] = 'ref_count = 0';
        }
        // 「流量风险」：没有独立缩略版，列表页会拉原图
        if (!empty($f['no_thumb'])) {
            $where[] = "(thumb_rel_path IS NULL OR thumb_rel_path = '')";
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(rel_path LIKE ? OR remote_name LIKE ? OR direct_url LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 24)));
        $offset = ($page - 1) * $perPage;

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        $rows = Db::all(
            'SELECT * FROM assets WHERE ' . $whereSql
            . ' ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        $total = (int) Db::val('SELECT COUNT(*) FROM assets WHERE ' . $whereSql, $params);

        $out = [];
        foreach ($rows as $row) {
            $shape = self::publicShape($row);
            $shape['rel_path'] = $row['rel_path'];
            $shape['remote_name'] = $row['remote_name'];
            $shape['direct_url'] = $row['direct_url'];
            $shape['ref_count'] = (int) $row['ref_count'];
            $shape['storage_model'] = (int) $row['storage_model'];
            $shape['expires_at'] = $row['expires_at'];
            $shape['created_at'] = $row['created_at'];
            $out[] = $shape;
        }

        return ['rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM assets WHERE id = ?', [$id]);
    }

    public static function findBySha256(string $sha256): ?array
    {
        if ($sha256 === '') {
            return null;
        }
        // 取最新一条，因为可能同时存在「部分完成」的孤儿记录
        return Db::one('SELECT * FROM assets WHERE sha256 = ? ORDER BY id DESC LIMIT 1', [$sha256]);
    }

    // ════════════════════════════════════════════════════════
    //  手动登记外链（SPEC §6.3 admin.asset.add）
    // ════════════════════════════════════════════════════════

    public static function addExternal(array $in): int
    {
        $url = trim((string) ($in['url'] ?? ''));

        if (!preg_match('#^https://#i', $url)) {
            // 站点是 HTTPS → http 图片会触发混合内容告警，登记时即拒绝（SPEC §10.2）
            Response::fail('VALIDATION_FAILED',
                '只接受 https 开头的图片地址（http 会造成混合内容告警）',
                Response::BAD_REQUEST, 'url');
        }

        $normalized = self::normalizeAssetKey($url);

        // 去重：同一 URL 不重复登记
        $existing = Db::val('SELECT id FROM assets WHERE rel_path = ? LIMIT 1', [$normalized['rel_path']]);
        if ($existing !== null) {
            return (int) $existing;
        }

        $width  = (int) ($in['width'] ?? 0);
        $height = (int) ($in['height'] ?? 0);

        // 注意不要往这里塞 alt_text：那是 image_meta（图片**内容**）的列，
        // assets 是**媒体库**，一个文件就是一条，没有无障碍文本的概念。
        // 曾经多写了一个 'alt_text' => null，导致本方法每次都抛
        // «Unknown column 'alt_text' in 'field list'»，手动登记外链全线不可用（见 DEBUG.md D-14）。
        $id = Db::insert('assets', [
            'rel_path'      => $normalized['rel_path'],
            'direct_url'    => $normalized['direct_url'],
            'origin_url'    => $url,
            'provider'      => 'manual',
            'is_external'   => $normalized['is_external'],
            'storage_model' => TttttAdapter::MODEL_PERMANENT,
            'expires_at'    => null,
            'mime'          => self::nullable($in['mime'] ?? null, 64),
            'width'         => $width > 0 ? $width : null,
            'height'        => $height > 0 ? $height : null,
            'ref_count'     => 0,
            'check_status'  => 'unknown',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        Settings::bumpCacheVersion();
        return $id;
    }

    // ════════════════════════════════════════════════════════
    //  上传（SPEC §6.3 admin.asset.upload / D9）
    // ════════════════════════════════════════════════════════

    /**
     * 服务端中转上传。前端须同时提交原图与缩略图两个 blob。
     *
     * @param array $files $_FILES 中的 ['full' => ..., 'thumb' => ...]
     * @return array 资产记录（publicShape + id）
     */
    public static function upload(array $files, array $meta): array
    {
        $maxBytes = Settings::int('ttttt_max_bytes', 104857600);

        $full  = self::validateUpload($files['full'] ?? null, $maxBytes, 'full');
        $thumb = self::validateUpload($files['thumb'] ?? null, $maxBytes, 'thumb');

        $adapter = self::adapter(null, microtime(true) + 25.0);
        if (!$adapter->supportsUpload()) {
            Response::fail('STORAGE_ERROR',
                '当前图床通道不支持上传，请到设置页检查图床配置', Response::BAD_REQUEST);
        }

        // ── 去重：相同图片不重复上传（SPEC §10.2）──
        $sha256 = hash_file('sha256', $full['tmp_name']) ?: '';
        $existing = self::findBySha256($sha256);
        if ($existing !== null && !empty($existing['rel_path']) && !empty($existing['thumb_rel_path'])) {
            self::cleanupTmp([$full, $thumb]);
            return self::publicShape($existing) + ['id' => (int) $existing['id'], 'reused' => true];
        }
        // 若存在「部分完成」的孤儿记录（缺缩略版），复用它已上传的 UKEY 而非重传
        $reuseUkey = $existing !== null && !empty($existing['rel_path']) && empty($existing['thumb_rel_path'])
            ? (string) $existing['rel_path']
            : null;

        $fullResult  = null;
        $thumbResult = null;

        try {
            // ① 原图：若可复用则跳过上传
            if ($reuseUkey !== null) {
                $fullResult = [
                    'rel_path'    => $reuseUkey,
                    'remote_dkey' => (string) ($existing['remote_dkey'] ?? ''),
                    'remote_name' => (string) ($existing['remote_name'] ?? ''),
                    'direct_url'  => (string) ($existing['direct_url'] ?? ''),
                    'model'       => TttttAdapter::MODEL_PERMANENT,
                ];
            } else {
                $fullResult = $adapter->put($full['tmp_name'], $full['name'], $full['mime']);
            }

            // ② 缩略图（钛盘无缩略参数，必须独立上传）
            $thumbResult = $adapter->put($thumb['tmp_name'], $thumb['name'], $thumb['mime']);
        } catch (Throwable $e) {
            // 【SPEC §10.2】双尺寸上传中任一失败 → 不产生可用记录。
            // 但已上传的 UKEY 若直接丢弃，将永远无法回收（对方空间被白占）。
            // 因此这里写入一条 ref_count=0 的**孤儿记录**：
            //   · 后台「孤立资源」列表可见并可一键清理（带 delete=1 释放空间）
            //   · 下次上传凭 sha256 命中它，复用已传的 UKEY、只补传缺失的那一版
            if ($fullResult !== null && $thumbResult === null) {
                self::recordOrphan($full, $sha256, $fullResult, $meta);
            }
            self::cleanupTmp([$full, $thumb]);
            ErrorHandler::log($e);
            throw $e;
        }

        self::cleanupTmp([$full, $thumb]);

        $now = date('Y-m-d H:i:s');
        $row = [
            'rel_path'         => $fullResult['rel_path'],
            'thumb_rel_path'   => $thumbResult['rel_path'],
            'remote_dkey'      => $fullResult['remote_dkey'],
            'remote_name'      => $fullResult['remote_name'],   // 列名是 remote_name，不是 remote_filename
            'direct_url'       => $fullResult['direct_url'],
            'thumb_dkey'       => $thumbResult['remote_dkey'],
            'thumb_name'       => $thumbResult['remote_name'],
            'thumb_direct_url' => $thumbResult['direct_url'],
            'origin_url'       => null,
            'provider'         => 'ttttt',
            'is_external'      => 0,
            // model 恒为 99（永久）。其他模式会让图片到期消失，不可接受。
            'storage_model'    => TttttAdapter::MODEL_PERMANENT,
            'expires_at'       => null,
            'mime'             => $full['mime'],
            'width'            => (int) ($meta['width'] ?? 0) ?: null,
            'height'           => (int) ($meta['height'] ?? 0) ?: null,
            'thumb_width'      => (int) ($meta['thumb_width'] ?? 0) ?: null,
            'thumb_height'     => (int) ($meta['thumb_height'] ?? 0) ?: null,
            'file_size'        => (int) $full['size'],
            'sha256'           => $sha256,
            'ref_count'        => 0,
            'check_status'     => 'ok',
            'last_check_at'    => $now,
            'last_http_code'   => 200,
            'last_error'       => null,
            'created_at'       => $now,
        ];

        // 复用部分记录时做 UPSERT，避免产生重复行
        if ($existing !== null && $reuseUkey !== null) {
            Db::update('assets', $row, 'id', (int) $existing['id']);
            $id = (int) $existing['id'];
        } else {
            $id = Db::insert('assets', $row);
        }

        Settings::bumpCacheVersion();
        $saved = self::find($id);

        return self::publicShape($saved ?? $row) + ['id' => $id, 'reused' => false];
    }

    /** 写入一条孤儿记录，供「孤立资源」清理 */
    private static function recordOrphan(array $full, string $sha256, array $result, array $meta): void
    {
        try {
            Db::insert('assets', [
                'rel_path'      => $result['rel_path'],
                'remote_dkey'   => $result['remote_dkey'],
                'remote_name'   => $result['remote_name'],
                'direct_url'    => $result['direct_url'],
                'provider'      => 'ttttt',
                'is_external'   => 0,
                'storage_model' => TttttAdapter::MODEL_PERMANENT,
                'mime'          => $full['mime'],
                'width'         => (int) ($meta['width'] ?? 0) ?: null,
                'height'        => (int) ($meta['height'] ?? 0) ?: null,
                'file_size'     => (int) $full['size'],
                'sha256'        => $sha256,
                'ref_count'     => 0,
                'check_status'  => 'unknown',
                'last_error'    => '缩略版上传失败，原图已成为孤立资源',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            ErrorHandler::log($e);
        }
    }

    private static function validateUpload(?array $file, int $maxBytes, string $field): array
    {
        if ($file === null || !isset($file['error'])) {
            Response::fail('VALIDATION_FAILED', "缺少 {$field} 文件", Response::BAD_REQUEST, $field);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $message = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超出服务器允许的大小',
                UPLOAD_ERR_PARTIAL                        => '文件只上传了一部分，请重试',
                UPLOAD_ERR_NO_FILE                        => "未收到 {$field} 文件",
                default                                   => '上传失败，请重试',
            };
            $code = in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'UPLOAD_TOO_LARGE' : 'VALIDATION_FAILED';
            Response::fail($code, $message, Response::BAD_REQUEST, $field);
        }

        // 后端二次校验（前端已按服务端下发的限制值预检，SPEC §10.2）
        if ((int) $file['size'] > $maxBytes) {
            Response::fail('UPLOAD_TOO_LARGE',
                '文件超出上限（' . self::humanSize($maxBytes) . '），请压缩后重试',
                Response::BAD_REQUEST, $field);
        }
        if ((int) $file['size'] <= 0) {
            Response::fail('VALIDATION_FAILED', '文件为空', Response::BAD_REQUEST, $field);
        }

        // 扩展名与 MIME 白名单
        $mime = (string) ($file['type'] ?? '');
        $allowed = ['image/webp', 'image/jpeg', 'image/png', 'image/gif', 'image/avif'];
        if (!in_array($mime, $allowed, true)) {
            $mime = self::guessMime((string) ($file['name'] ?? ''));
        }
        if (!in_array($mime, $allowed, true)) {
            Response::fail('VALIDATION_FAILED', '只允许上传 WebP / JPEG / PNG / GIF / AVIF 图片',
                Response::BAD_REQUEST, $field);
        }

        return [
            'tmp_name' => (string) $file['tmp_name'],
            'name'     => self::safeRemoteName((string) ($file['name'] ?? 'image'), $mime),
            'mime'     => $mime,
            'size'     => (int) $file['size'],
        ];
    }

    /** 远端文件名：随机化 + 白名单扩展名（SPEC §9.4-5） */
    private static function safeRemoteName(string $original, string $mime): string
    {
        $ext = match ($mime) {
            'image/webp' => 'webp',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/avif' => 'avif',
            default      => 'jpg',
        };
        return date('Ymd') . '-' . Str::random(12) . '.' . $ext;
    }

    private static function guessMime(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }

    /** 用完即删（SPEC §9.4-5） */
    private static function cleanupTmp(array $files): void
    {
        foreach ($files as $f) {
            if (!empty($f['tmp_name']) && is_file($f['tmp_name'])) {
                @unlink($f['tmp_name']);
            }
        }
    }

    // ════════════════════════════════════════════════════════
    //  巡检 / 自愈
    // ════════════════════════════════════════════════════════

    /** 手动复检单条（SPEC §6.3 admin.asset.recheck） */
    public static function recheck(int $id): array
    {
        $asset = self::find($id);
        if ($asset === null) {
            Response::fail('NOT_FOUND', '资产不存在', Response::NOT_FOUND, 'id');
        }
        return self::probeAndRecord($asset);
    }

    private static function probeAndRecord(array $asset): array
    {
        $adapter = self::adapter((string) ($asset['provider'] ?? 'manual'));
        $target = (string) ($asset['direct_url'] ?: $asset['rel_path']);

        $result = $adapter->probe($target);
        $code = $result['http_code'];

        $status = 'unknown';
        $error = $result['error'];

        if ($result['ok']) {
            $status = 'ok';
        } elseif ($code === null) {
            // 网络超时 → unknown，**不是 missing**（SPEC §10.2）
            $status = 'unknown';
        } elseif ($code === 403) {
            // T3 已确认钛盘无防盗链，故 403 不再是「预期内」状态，
            // 应作为异常暴露，而不是静默归入 missing（SPEC §10.2）
            $status = 'unknown';
            $error = 'HTTP 403（可能是直链被平台封禁或域名策略变更）';
            ErrorHandler::warn('巡检返回 403，非预期状态', ['asset_id' => $asset['id'] ?? null]);
        } elseif ($code === 404 || $code === 410) {
            $status = 'missing';
        } else {
            $status = 'missing';
        }

        Db::q(
            'UPDATE assets SET check_status = ?, last_check_at = ?, last_http_code = ?, last_error = ? WHERE id = ?',
            [$status, date('Y-m-d H:i:s'), $code, $error, (int) $asset['id']]
        );

        return ['ok' => $result['ok'], 'status' => $status, 'http_code' => $code, 'error' => $error];
    }

    /**
     * 用存量 UKEY 重跑 link_add 重建直链（SPEC §6.3 admin.asset.relink）。
     * 模板变更或直链丢失时的自愈入口 —— **不必重新上传图片**。
     */
    public static function relink(array $ids): array
    {
        $adapter = self::adapter('ttttt', microtime(true) + 20.0);
        $ok = 0;
        $failed = [];

        foreach (array_map('intval', $ids) as $id) {
            $asset = self::find($id);
            if ($asset === null || empty($asset['rel_path']) || preg_match('#^https?://#i', (string) $asset['rel_path'])) {
                $failed[] = $id;
                continue;
            }

            try {
                // 复用 put() 的两步流程：UKEY 已在手，等于只跑第 2 步
                $result = self::linkExisting($asset, $adapter);
                if ($result) {
                    $ok++;
                } else {
                    $failed[] = $id;
                }
            } catch (Throwable $e) {
                ErrorHandler::log($e);
                $failed[] = $id;
            }
        }

        Settings::bumpCacheVersion();
        return ['ok' => $ok, 'failed' => $failed];
    }

    /**
     * 对已存在的 UKEY 重建直链并回写。
     * 走 TttttAdapter::linkAddForUkey()，**不触发重传** —— 这是 relink 的全部意义所在。
     */
    private static function linkExisting(array $asset, StorageAdapter $adapter): bool
    {
        if (!$adapter instanceof TttttAdapter) {
            return false;
        }

        $updated = false;
        foreach ([['rel_path', 'remote_dkey', 'remote_name', 'direct_url'],
                  ['thumb_rel_path', 'thumb_dkey', 'thumb_name', 'thumb_direct_url']] as $map) {
            [$keyCol, $dkeyCol, $nameCol, $urlCol] = $map;
            $ukey = (string) ($asset[$keyCol] ?? '');
            if ($ukey === '' || preg_match('#^https?://#i', $ukey)) {
                continue;
            }

            $link = TttttAdapter::linkAddForUkey($ukey);
            if ($link === null) {
                continue;
            }

            Db::q(
                "UPDATE assets SET $dkeyCol = ?, $nameCol = ?, $urlCol = ? WHERE id = ?",
                [$link['dkey'], $link['name'], TttttAdapter::buildDirectUrl($link['dkey'], $link['name']), (int) $asset['id']]
            );
            $updated = true;
        }

        if ($updated) {
            Db::q('UPDATE assets SET check_status = ?, last_error = NULL, last_check_at = ? WHERE id = ?',
                ['ok', date('Y-m-d H:i:s'), (int) $asset['id']]);
        }

        return $updated;
    }

    /**
     * 该资产所属通道是否具备撤销能力。
     *
     * 这个能力位（StorageAdapter::supportsRevoke）原先全项目**没有任何调用点** ——
     * 定义了却没人问，于是 ManualAdapter 那种「设计上就不能撤销」的通道
     * 被当成了「撤销失败」（见 D-16）。
     */
    private static function adapterSupportsRevoke(?array $asset): bool
    {
        if ($asset === null) {
            return false;
        }

        // adapter() 内部用 match 兜底到 ManualAdapter，不会抛异常，可安全探测
        return self::adapter((string) $asset['provider'])->supportsRevoke();
    }

    /**
     * 撤销单条资产的直链。**内部固定带 delete=1**（真正释放空间）。
     *
     * 返回值 true 只代表「远端确实撤销成功」。返回 false 有两种**截然不同**的含义，
     * 调用方必须用 adapterSupportsRevoke() 区分，否则会做错决定：
     *   · 通道不支持撤销（manual，别人的图）→ 无事可做，记录可以直接删；
     *   · 通道支持但这次失败了（网络/Key/限流）→ **必须保留记录等重试**，
     *     删掉就等于销毁了唯一的重试凭据（见 D-18）。
     */
    public static function revoke(int $id): bool
    {
        $asset = self::find($id);
        if ($asset === null) {
            return false;
        }

        // 能力缺失 ≠ 操作失败。ManualAdapter::revoke() 那句注释写得很清楚：
        // 「别人的图，无权撤销。返回 false 让调用方记录日志并继续。」
        // 之前这里不查能力位，一律往下走并写入 last_error='撤销失败，待重试'，
        // 等于让用户去重试一件永远不会成功的事（见 D-16）。
        if (!self::adapterSupportsRevoke($asset)) {
            return false;
        }

        $adapter = self::adapter((string) $asset['provider']);
        $ok = $adapter->revoke($asset, true);

        if ($ok) {
            Db::q('UPDATE assets SET check_status = ?, last_error = ?, last_check_at = ? WHERE id = ?',
                ['missing', '直链已撤销', date('Y-m-d H:i:s'), $id]);
        } else {
            // 撤销失败不阻塞：写日志 + 进「待清理」列表（SPEC §10.2）
            Db::q('UPDATE assets SET last_error = ? WHERE id = ?',
                ['撤销失败，待重试', $id]);
        }

        return $ok;
    }

    /**
     * 删记录。ref_count > 0 时拒绝；= 0 时先 revoke() 再删（SPEC §6.3）。
     */
    public static function delete(int $id): bool
    {
        $asset = self::find($id);
        if ($asset === null) {
            Response::fail('NOT_FOUND', '资产不存在', Response::NOT_FOUND, 'id');
        }

        if ((int) $asset['ref_count'] > 0) {
            Response::fail('VALIDATION_FAILED',
                '该图片正被 ' . (int) $asset['ref_count'] . ' 处内容引用，无法删除',
                Response::BAD_REQUEST, 'id');
        }

        // 【D-18】支持撤销、但这次没撤成功 → **不删记录**。
        // 这条记录里的 rel_path 就是钛盘 UKEY，是重建/撤销直链的唯一凭据。
        // 删了它，钛盘上那个文件从此既占配额、又没有任何入口能再够到它
        // （Asset::sync() 的 orphan_remote 只能列出「远端有、本地无记录」的文件，
        //  给不出 UKEY，一样撤销不了）。报错让用户知道「没删干净」，
        // 记录留着，稍后重试即可 —— 比静默丢凭据好得多。
        if (!self::revoke($id) && self::adapterSupportsRevoke($asset)) {
            Response::fail('STORAGE_ERROR',
                '远端直链撤销失败，记录已保留以便重试，请稍后再试',
                Response::SERVER_ERR, 'id');
        }

        Db::q('DELETE FROM assets WHERE id = ?', [$id]);

        Settings::bumpCacheVersion();
        return true;
    }

    /**
     * 取出一篇内容引用过的资产 id（去重）。
     *
     * 【为什么必须单独走这一步】`releaseAssets()` 判断「还有没有人引用它」靠的是
     * 重新数 `image_meta`，所以它必须在 `image_meta` 的行被删**之后**才执行 ——
     * 那时候按 `content_id` 已经什么都查不到了，只能事先把 id 抄下来。
     *
     * 这个「先抄后删再释放」的顺序就是 D-17 的全部要害：原先写成
     * `destroy()` 里先调 releaseForContent()、后删 image_meta，
     * 于是被数的 COUNT 至少为 1，`$n === 0` 恒为假 —— 资产永远不释放，
     * 钛盘的 link_del 一次都不会发出，而 destroy 照样返回 ok。
     */
    public static function assetIdsForContent(int $contentId): array
    {
        $rows = Db::all(
            'SELECT DISTINCT asset_id FROM image_meta WHERE content_id = ? AND asset_id IS NOT NULL',
            [$contentId]
        );

        return array_map(static fn(array $r): int => (int) $r['asset_id'], $rows);
    }

    /**
     * 内容被彻底删除**之后**，释放它引用过的资产。
     *
     * 调用前提：该内容的 `image_meta` 行已经删掉了 —— 此时
     * `recomputeRefCount()` 数出来的才是「这篇内容消失之后」的真实剩余引用数。
     *
     * @param int[] $assetIds 由 assetIdsForContent() 在删 image_meta 之前抄下的 id
     */
    public static function releaseAssets(array $assetIds): void
    {
        foreach (array_unique(array_map('intval', $assetIds)) as $assetId) {
            $asset = $assetId > 0 ? self::find($assetId) : null;
            if ($asset === null) {
                continue;
            }

            if (self::recomputeRefCount($assetId) > 0) {
                continue;   // 还有别的内容在引用它，留着
            }

            // 【D-18】撤销失败不阻塞删除动作（SPEC §10.2），但**不能连凭据一起删**。
            // 这里 revoke() 刚写下 last_error='撤销失败，待重试'，
            // 若紧接着删掉记录，那条提示连同 rel_path（钛盘 UKEY）一并消失，
            // 远端文件就永远占着配额、再也无从重试。
            // 「不支持撤销」（manual）则相反：本就无事可做，记录该删。
            if (!self::revoke($assetId) && self::adapterSupportsRevoke($asset)) {
                continue;
            }

            Db::q('DELETE FROM assets WHERE id = ?', [$assetId]);
        }
    }

    /** 由 image_meta 重新计算引用计数 */
    public static function recomputeRefCount(int $assetId): int
    {
        $n = (int) Db::val('SELECT COUNT(*) FROM image_meta WHERE asset_id = ?', [$assetId]);
        Db::q('UPDATE assets SET ref_count = ? WHERE id = ?', [$n, $assetId]);
        return $n;
    }

    public static function recomputeForContent(int $contentId): void
    {
        $rows = Db::all(
            'SELECT DISTINCT asset_id FROM image_meta WHERE content_id = ? AND asset_id IS NOT NULL',
            [$contentId]
        );
        foreach ($rows as $row) {
            self::recomputeRefCount((int) $row['asset_id']);
        }

        // 上面只能修「当前还引用着」的资产。
        // 内容把配图从 A 换成 B 时，A 已不在 image_meta 中、无从枚举，
        // 它的 ref_count 会永远停在旧值 → 该图再也无法删除。故补一次全局对账。
        self::reconcileRefCounts();
    }

    /**
     * 校正所有计错的 ref_count。只处理**不一致**的行，正常情况下一条都不改。
     * 先 SELECT 出差异集再逐条 UPDATE —— 避免依赖 MySQL 5.6 的多表 UPDATE 语义。
     * @return int 修正的行数
     */
    public static function reconcileRefCounts(): int
    {
        $rows = Db::all(
            'SELECT a.id, COALESCE(m.n, 0) AS expected
               FROM assets a
               LEFT JOIN (
                    SELECT asset_id, COUNT(*) AS n
                      FROM image_meta
                     WHERE asset_id IS NOT NULL
                     GROUP BY asset_id
               ) m ON m.asset_id = a.id
              WHERE a.ref_count <> COALESCE(m.n, 0)'
        );

        foreach ($rows as $row) {
            Db::q('UPDATE assets SET ref_count = ? WHERE id = ?', [(int) $row['expected'], (int) $row['id']]);
        }

        return count($rows);
    }

    // ════════════════════════════════════════════════════════
    //  同步检查（SPEC §6.3 admin.asset.sync）
    // ════════════════════════════════════════════════════════

    /**
     * 调 list_of_direct 循环翻页拉取远端全部直链，与库比对。
     * @return array{remote_total:int, missing_local:array, orphan_remote:array}
     */
    public static function sync(): array
    {
        $adapter = self::adapter('ttttt', microtime(true) + 20.0);
        $links = $adapter->listLinks();

        if ($links === null) {
            Response::fail('STORAGE_ERROR',
                'list_of_direct 接口不可用，无法执行同步检查', Response::SERVER_ERR);
        }

        // 远端集合：以 link 为键（返回项自带 link 字段，可直接比对，SPEC §7.2.2）
        $remote = [];
        foreach ($links as $item) {
            if ($item['link'] !== '') {
                $remote[$item['link']] = $item;
            }
        }

        $local = Db::all(
            "SELECT id, rel_path, direct_url, thumb_direct_url, remote_dkey, thumb_dkey
               FROM assets WHERE is_external = 0"
        );

        // 库有远端无 → 直链缺失，可用存量 UKEY 批量重建
        $missing = [];
        // 远端有库无 → 孤立直链，可批量撤销释放空间
        $knownLinks = [];

        foreach ($local as $row) {
            foreach ([$row['direct_url'], $row['thumb_direct_url']] as $url) {
                if (!empty($url)) {
                    $knownLinks[$url] = true;
                }
            }
        }

        foreach ($local as $row) {
            if (!empty($row['direct_url']) && !isset($remote[$row['direct_url']])) {
                // has_ukey：rel_path 存的是 UKEY（非 URL）才能靠「补建直链」自愈，
                // 否则只能重新上传（前端会据此决定按钮文案）
                $relPath = (string) ($row['rel_path'] ?? '');
                $missing[] = [
                    'id'         => (int) $row['id'],
                    'direct_url' => $row['direct_url'],
                    'has_ukey'   => $relPath !== '' && !preg_match('#^https?://#i', $relPath),
                ];
            }
        }

        $orphan = [];
        foreach ($remote as $link => $item) {
            if (!isset($knownLinks[$link])) {
                $orphan[] = [
                    'dkey'  => $item['dkey'],
                    'name'  => $item['name'],
                    'link'  => $link,
                    'size'  => $item['size'],
                    'etime' => $item['etime'],
                ];
            }
        }

        // 供后台「孤立直链」列表展示
        Settings::set('asset_orphan_links', json_encode($orphan, JSON_UNESCAPED_UNICODE));

        // 【字段名的坑，别按字面读】
        //   missing_local —— 不是「本地缺记录」，是**本地记着的直链在远端没了**。
        //                    写成这个名字是历史原因，前端一度按字面理解，
        //                    把两个列表的说明写反了（见 DEBUG.md D-09）。
        //   orphan_remote —— 远端有、本地无引用，这个名如其义。
        // 两个列表都只用于展示：远端条目一旦撤销就真的没了（link_del 带 delete=1），
        // 所以这里不提供任何自动清理入口。
        return [
            'remote_total'   => count($remote),
            'missing_local'  => $missing,
            'orphan_remote'  => $orphan,
        ];
    }

    // ── 定时任务（由 Scheduler 调用，SPEC §7.1）──────────────

    /**
     * asset_check（12 小时 / 单批 20 条）
     * 优先走 list_of_direct 集合比对 —— 一次请求胜过 N 次探测；
     * 接口不可用时降级为逐条 HEAD（T3 已确认无防盗链，无需 Range 兼容层）。
     */
    public static function cronCheck(float $deadline, int $batch): string
    {
        $adapter = self::adapter('ttttt', $deadline);

        try {
            $links = $adapter->listLinks();
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            $links = null;
        }

        if ($links !== null) {
            $remote = [];
            foreach ($links as $item) {
                if ($item['link'] !== '') {
                    $remote[$item['link']] = $item;
                }
            }

            $rows = Db::all(
                "SELECT id, direct_url, thumb_direct_url FROM assets
                  WHERE is_external = 0 AND direct_url IS NOT NULL AND direct_url <> ''"
            );

            $ok = 0;
            $missing = 0;
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                if (isset($remote[$row['direct_url']])) {
                    Db::q('UPDATE assets SET check_status = ?, last_check_at = ?, last_http_code = ?, last_error = NULL WHERE id = ?',
                        ['ok', date('Y-m-d H:i:s'), 200, (int) $row['id']]);
                    $ok++;
                } else {
                    Db::q('UPDATE assets SET check_status = ?, last_check_at = ?, last_error = ? WHERE id = ?',
                        ['missing', date('Y-m-d H:i:s'), '远端直链集合中未找到（可用「批量重建直链」自愈）', (int) $row['id']]);
                    $missing++;
                }
            }

            return "集合比对 {$ok} 条正常，{$missing} 条缺失";
        }

        // ── 降级：逐条 HEAD ──
        $rows = Db::all(
            "SELECT * FROM assets
              WHERE check_status <> 'ok' OR last_check_at IS NULL
                 OR last_check_at < NOW() - INTERVAL 12 HOUR
              ORDER BY last_check_at IS NULL DESC, last_check_at ASC
              LIMIT " . (int) $batch
        );

        $checked = 0;
        $bad = 0;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $r = self::probeAndRecord($row);
            $checked++;
            if (!$r['ok']) {
                $bad++;
            }
        }

        return "逐条探测 {$checked} 条，{$bad} 条异常";
    }

    /**
     * expires_watch（1 天 / 单批 200 条）
     * 【保险丝】防止误用非永久模式导致图片集体消失（SPEC §7.1）。
     */
    public static function cronExpiresWatch(float $deadline, int $batch): string
    {
        $rows = Db::all(
            'SELECT id, storage_model, expires_at FROM assets
              WHERE storage_model <> ' . TttttAdapter::MODEL_PERMANENT . '
              LIMIT ' . (int) $batch
        );

        $expired = 0;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $isExpired = $row['expires_at'] === null || strtotime((string) $row['expires_at']) <= time();
            if ($isExpired) {
                Db::q('UPDATE assets SET check_status = ?, last_error = ? WHERE id = ?',
                    ['expired', '当前为非永久存储模式（storage_model=' . (int) $row['storage_model'] . '），图片可能已消失', (int) $row['id']]);
                $expired++;
            }
        }

        if ($rows !== []) {
            Settings::set('storage_model_warning', (string) count($rows));
            // 后台首页红色告警（SPEC §10.2）
            ErrorHandler::warn('检测到非永久存储模式的资产', ['count' => count($rows), 'expired' => $expired]);
        }

        return $rows === [] ? '未发现非永久模式资产' : '发现 ' . count($rows) . ' 条非永久模式资产，' . $expired . ' 条已过期';
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private static function nullable($v, int $max): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
