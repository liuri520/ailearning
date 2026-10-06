<?php
/**
 * TttttAdapter.php —— 钛盘（ttttt.link）适配器（SPEC §7.2.2）
 *
 * ⚠️ 关键事实：钛盘上传**只返回 UKEY**，不是一个能放进 <img src> 的链接。
 *    要拿到直链，**必须再调第二个接口**（services/direct 的 link_add）。
 *    一张图片的完整流程是 4 次远程调用（2 个版本 × 2 步）：
 *
 *      缩略图 blob ──① upload_cli──> UKEY_thumb ──② link_add──> 直链A
 *      原图   blob ──③ upload_cli──> UKEY_full  ──④ link_add──> 直链B
 *
 * 三个必须留意的解析细节（写错会表现为「上传成功但图片全是占位图」，极难排查）：
 *   1. link_add 的 data 是**数组**，必须取 data[0].dkey / data[0].name
 *   2. 字段名是 name，**不是 filename**（表列名相应为 remote_name）
 *   3. 响应里的 debug 字段**只写日志，不回显给前端**
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class TttttAdapter implements StorageAdapter
{
    /** 存储模式：99 = 永久。写死，不在后台 UI 暴露为可选项（SPEC §7.2.2） */
    public const MODEL_PERMANENT = 99;

    private const UPLOAD_ENDPOINT = 'https://tmp-cli.vx-cdn.com/app/upload_cli';
    private const DIRECT_ENDPOINT = 'https://tmp-api.vx-cdn.com/services/direct';

    /**
     * cURL 超时。v1.1 的「单次 25 秒」在两步流程下不再可行 —— 4 次调用必须共享预算，
     * 故单次收紧到 8 秒（SPEC §7.2.2）。
     */
    private const TIMEOUT         = 8;
    private const CONNECT_TIMEOUT = 5;

    /** 整批远程调用的墙钟预算（秒）。SPEC §7.2.2：4 次调用 + 重试累计须留在 25 秒内 */
    private const TOTAL_BUDGET = 25.0;

    private float $deadline;

    public function __construct(?float $deadline = null)
    {
        $this->deadline = $deadline ?? (microtime(true) + self::TOTAL_BUDGET);
    }

    // ════════════════════════════════════════════════════════
    //  StorageAdapter 实现
    // ════════════════════════════════════════════════════════

    public function put(string $tmpPath, string $remoteName, string $mime): array
    {
        $ukey = $this->uploadFile($tmpPath, $remoteName, $mime);
        $link = $this->linkAdd($ukey);

        return [
            'rel_path'    => $ukey,
            'remote_dkey' => $link['dkey'],
            'remote_name' => $link['name'],
            'direct_url'  => self::buildDirectUrl($link['dkey'], $link['name']) ?? '',
            'model'       => self::MODEL_PERMANENT,
        ];
    }

    /**
     * 用**已有的 UKEY** 补建直链，不重传文件（SPEC §6.3 admin.asset.relink）。
     *
     * 这是「1005 文件未就绪」与「直链丢失」两条路径的自愈出口：
     * 图片本体已在图床上，重建一条直链的成本是一次 API 调用，而不是重传整张图。
     * 也用于直链模板变更后的存量数据批量修复。
     *
     * @return array{dkey:string,name:string}|null 失败返回 null，由调用方计入失败列表
     */
    public static function linkAddForUkey(string $ukey, ?float $deadline = null): ?array
    {
        if ($ukey === '') {
            return null;
        }

        try {
            $adapter = new self($deadline ?? (microtime(true) + self::TOTAL_BUDGET));
            return $adapter->linkAdd($ukey);
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            return null;
        }
    }

    /**
     * 撤销访问权限。
     *
     * ⚠️【唯一的下限陷阱】link_del **必须显式传 delete=1** 才会释放空间。
     *    只传 dkey 的话，链接失效了、文件却永远占位，status=4（空间不足）会缓慢累积
     *    且难以察觉。因此该参数写死在本方法内部，**不作为调用方可选项**（SPEC §7.2.3）。
     */
    public function revoke(array $asset, bool $deleteFile = false): bool
    {
        $results = [];

        if (!empty($asset['remote_dkey'])) {
            $results[] = $this->linkDel((string) $asset['remote_dkey']);
        }
        if (!empty($asset['thumb_dkey'])) {
            $results[] = $this->linkDel((string) $asset['thumb_dkey']);
        }

        if ($results === []) {
            return false;   // 没有 dkey（如手动登记的外链），无从撤销
        }

        return !in_array(false, $results, true);
    }

    /**
     * 列出远端现存直链。
     * 【必须循环翻页】只取第一页会产生大量「远端缺失」的假阳性，
     * 进而误判大量图片失效（SPEC §10.2）。返回项自带 link 字段。
     */
    public function listLinks(): ?array
    {
        $all  = [];
        $page = 1;
        // 安全上限，防止接口异常导致死循环
        $maxPages = 500;

        while ($page <= $maxPages) {
            try {
                $res = $this->request(self::DIRECT_ENDPOINT, [
                    $this->keyParam() => $this->apiKey(),
                    'action'          => 'list_of_direct',
                    'page'            => (string) $page,
                ]);
            } catch (Throwable $e) {
                ErrorHandler::log($e);
                // 接口不可用 → 交由调用方降级为逐条 HEAD（SPEC §7.2.2）
                return null;
            }

            $json   = $this->decode($res['body']);
            $status = $json['status'] ?? null;

            if ((int) $status !== 1) {
                if ($page === 1) {
                    ErrorHandler::warn('list_of_direct 失败', [
                        'status' => $status,
                        'raw'    => substr($res['body'], 0, 500),
                    ]);
                    return null;
                }
                break;
            }

            $items = $json['data'] ?? [];
            if (!is_array($items) || $items === []) {
                break;   // 本页为空 → 已取完
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $all[] = [
                        'dkey'  => (string) ($item['dkey'] ?? ''),
                        'name'  => (string) ($item['name'] ?? ''),
                        'link'  => (string) ($item['link'] ?? ''),
                        'size'  => isset($item['size']) ? (int) $item['size'] : null,
                        'etime' => (string) ($item['etime'] ?? ''),
                    ];
                }
            }

            $page++;
        }

        return $all;
    }

    /**
     * 存活探测（SPEC §7.2.2）。
     *
     * @param string $relPath 对钛盘而言应传入**已拼好的直链**：UKEY 本身不可直接请求。
     *                        Asset::recheck() 传的是 direct_url ?: rel_path。
     * @return array{ok:bool, http_code:int|null, error:string|null}
     *
     * T3 已实测确认钛盘**无防盗链**，故用最朴素的 HEAD 即可，不需要 Range 兼容层。
     */
    public function probe(string $relPath): array
    {
        if (!preg_match('#^https?://#i', $relPath)) {
            return ['ok' => false, 'http_code' => null, 'error' => '仅有 UKEY，无法直接探测'];
        }

        $ch = curl_init($relPath);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,     // HEAD
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,     // 不得关闭
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
            // 超时视为 unknown，**不是** missing —— 不误判为失效（SPEC §10.2）
            return ['ok' => false, 'http_code' => null, 'error' => $err !== '' ? $err : '网络错误'];
        }

        return [
            'ok'        => $code >= 200 && $code < 300,
            'http_code' => $code,
            'error'     => ($code >= 200 && $code < 300) ? null : ('HTTP ' . $code),
        ];
    }

    /** 由 Asset::url() 统一实现四层回退（SPEC §7.2.5），此处仅代为转发 */
    public function url(array $asset, bool $thumb = false): ?string
    {
        return Asset::url($asset, $thumb);
    }

    public function supportsUpload(): bool     { return true; }
    public function supportsRevoke(): bool     { return true; }
    /** link_del 的 delete=1 会连带删除文件本体，空间即时释放（T14 已确认） */
    public function supportsHardDelete(): bool { return true; }
    /** 无图片处理参数 → 列表页小图必须在浏览器端生成并独立上传（SPEC §7.2.3） */
    public function supportsTransform(): bool  { return false; }
    /** 不剥离 EXIF → 前端 Canvas 重绘是必需步骤，不是可选优化（SPEC §9.6） */
    public function stripsExif(): bool         { return false; }

    // ════════════════════════════════════════════════════════
    //  步骤 1：上传文件
    // ════════════════════════════════════════════════════════

    /**
     * @return string 文件 UKEY
     */
    private function uploadFile(string $tmpPath, string $remoteName, string $mime): string
    {
        $backoffs = [1, 3];   // status=3（服务器繁忙）的退避秒数
        $attempt  = 0;

        while (true) {
            $this->assertBudget();

            $res    = $this->request(self::UPLOAD_ENDPOINT, [
                $this->keyParam() => $this->apiKey(),
                'model'           => (string) self::MODEL_PERMANENT,
                // 【不发送 mrid】T9 已证伪其绕开日配额的作用（SPEC §7.2.2）
            ], [
                'file' => new CURLFile($tmpPath, $mime, $remoteName),
            ]);

            $json   = $this->decode($res['body']);
            $status = $json['status'] ?? null;

            // debug 字段只写日志，不回显给前端
            if (isset($json['debug'])) {
                ErrorHandler::warn('钛盘上传响应', ['debug' => $json['debug'], 'status' => $status]);
            }

            switch ((int) $status) {
                case 1:   // 成功
                    $data = $json['data'] ?? null;
                    if (is_array($data)) {
                        $data = reset($data);
                    }
                    if (!is_string($data) || $data === '') {
                        throw new StorageException('STORAGE_ERROR', '图床返回异常：未取到文件标识',
                            500, (string) $status, $res['body']);
                    }
                    return $data;

                case 2:   // 文件过大
                    // T2 未测出钛盘上限，实际闸门是本机 upload_max_filesize（100M）
                    throw new StorageException('UPLOAD_TOO_LARGE',
                        '文件超出图床上限，请压缩后重试', 413, (string) $status, $res['body']);

                case 3:   // 服务器繁忙 → 自动退避重试 2 次，不写错误日志（是对方问题）
                    if ($attempt < count($backoffs)) {
                        sleep($backoffs[$attempt]);
                        $attempt++;
                        continue 2;
                    }
                    throw new StorageException('STORAGE_BUSY', '图床服务器繁忙，请稍后重试',
                        503, (string) $status, $res['body']);

                case 4:   // 空间不足
                    throw new StorageException('STORAGE_FULL',
                        '图床空间不足。请到后台「孤立资源」清理以释放空间', 507, (string) $status, $res['body']);

                case 5:   // 配额不足 —— 按天重置，次日自动恢复（文案必须写明，否则会被误判为永久故障）
                    throw new StorageException('STORAGE_QUOTA',
                        '图床当日上传配额已用尽，按天重置，次日自动恢复', 429, (string) $status, $res['body']);

                case 6:   // API Key 失效
                    throw new StorageException('STORAGE_AUTH',
                        '钛盘 API Key 已失效，请到设置页检查', 401, (string) $status, $res['body']);

                case 0:   // 无效请求 —— 视为**代码缺陷**，记录完整响应便于排查
                    ErrorHandler::warn('钛盘返回无效请求（多半是参数拼装 bug）', [
                        'status'   => $status,
                        'params'   => ['model' => self::MODEL_PERMANENT, 'remote_name' => $remoteName],
                        'response' => substr($res['body'], 0, 500),
                    ]);
                    throw new StorageException('STORAGE_ERROR', '图床拒绝了本次请求',
                        500, (string) $status, $res['body']);

                default:  // 未知 / 无法解析
                    throw new StorageException('STORAGE_ERROR', '图床返回了未知状态',
                        502, (string) $status, $res['body']);
            }
        }
    }

    // ════════════════════════════════════════════════════════
    //  步骤 2：直链管理
    // ════════════════════════════════════════════════════════

    /**
     * 建直链。返回 ['dkey' => ..., 'name' => ...]
     */
    private function linkAdd(string $ukey): array
    {
        $backoffs = [1, 2, 4];   // status=1005（文件未就绪）的退避秒数
        $attempt  = 0;
        $retry1002 = 0;

        while (true) {
            $this->assertBudget();

            try {
                $res = $this->request(self::DIRECT_ENDPOINT, [
                    $this->keyParam()   => $this->apiKey(),
                    'action'            => 'link_add',
                    'ukey'              => $ukey,
                    // 【必须显式传 0】虽然实测不传时默认也是 0，但依赖默认值是脆弱的（SPEC §7.2.2）
                    'valid_time'        => '0',
                    'download_limit'    => '0',
                ]);
            } catch (StorageException $e) {
                throw $e;
            }

            $json   = $this->decode($res['body']);
            $status = (int) ($json['status'] ?? -1);

            switch ($status) {
                case 1:   // 成功
                    // ⚠️ data 是数组，不是对象。写成 $json['data']['dkey'] 会得到 null 且不报错。
                    $item = $json['data'][0] ?? null;
                    if (!is_array($item)) {
                        throw new StorageException('STORAGE_ERROR', '图床未返回直链信息（data 结构异常）',
                            500, (string) $status, $res['body']);
                    }
                    $dkey = (string) ($item['dkey'] ?? '');
                    $name = (string) ($item['name'] ?? '');   // 字段名是 name，不是 filename
                    if ($dkey === '' || $name === '') {
                        throw new StorageException('STORAGE_ERROR', '图床未返回直链信息（字段缺失）',
                            500, (string) $status, $res['body']);
                    }
                    return ['dkey' => $dkey, 'name' => $name];

                case 1001:   // 文件不存在 —— UKEY 有效但文件没了，属严重异常
                    ErrorHandler::warn('link_add 返回文件不存在（UKEY 有效但文件丢失）', ['ukey' => $ukey]);
                    throw new StorageException('STORAGE_ERROR', '图床上的文件不存在，请重新上传',
                        500, (string) $status, $res['body']);

                case 1002:   // 内部错误 → 重试 2 次
                    if ($retry1002 < 2) {
                        $retry1002++;
                        sleep(1);
                        continue 2;
                    }
                    throw new StorageException('STORAGE_BUSY', '图床内部错误，请稍后重试',
                        503, (string) $status, $res['body']);

                case 1005:   // 文件未就绪 —— 上传后有短暂异步就绪期
                    // 退避 1s / 2s / 4s 重试 3 次；仍失败则**保留已上传的 UKEY**，
                    // 下次只需补建直链、不必重传整张图（SPEC §7.2.2 / §10.2）
                    if ($attempt < count($backoffs)) {
                        sleep($backoffs[$attempt]);
                        $attempt++;
                        continue 2;
                    }
                    throw new StorageException('STORAGE_BUSY',
                        '文件刚上传尚未就绪，可直接重试「补建直链」（无需重传）',
                        503, (string) $status, $res['body']);

                case 0:
                default:
                    throw new StorageException('STORAGE_ERROR', '图床建直链失败',
                        500, (string) $status, $res['body']);
            }
        }
    }

    /**
     * 删直链。**内部固定带 delete=1**，真正释放空间。
     * 失败不抛异常 —— 调用方记录日志并继续（SPEC §7.2.1）。
     */
    private function linkDel(string $dkey): bool
    {
        try {
            $res = $this->request(self::DIRECT_ENDPOINT, [
                $this->keyParam() => $this->apiKey(),
                'action'          => 'link_del',
                'dkey'            => $dkey,
                'delete'          => '1',   // ← 写死。漏传则「链接失效但空间永久占位」
            ]);
        } catch (Throwable $e) {
            ErrorHandler::log($e);
            return false;
        }

        $json   = $this->decode($res['body']);
        $status = (int) ($json['status'] ?? -1);

        if ($status === 1) {
            return true;
        }
        if ($status === 1003) {
            // 直链不存在 → 视为**成功（幂等）**：目标状态已达成，重复删除不应报错（SPEC §7.2.2）
            return true;
        }

        ErrorHandler::warn('link_del 失败，将进入「待清理」列表', [
            'dkey'     => $dkey,
            'status'   => $status,
            'response' => substr($res['body'], 0, 500),
        ]);
        return false;
    }

    // ════════════════════════════════════════════════════════
    //  工具
    // ════════════════════════════════════════════════════════

    /**
     * 直链拼接：https://download.wuyuge.cn/files/{dkey}/{name}
     * 模板来自 settings.ttttt_direct_template（占位符是 {dkey} 与 {name}，不是 {filename}）。
     */
    public static function buildDirectUrl(string $dkey, string $name): ?string
    {
        $tpl = (string) Settings::get('ttttt_direct_template', 'https://download.wuyuge.cn/files/{dkey}/{name}');
        if ($tpl === '' || !str_contains($tpl, '{dkey}') || !str_contains($tpl, '{name}')) {
            return null;
        }
        return str_replace(['{dkey}', '{name}'], [$dkey, $name], $tpl);
    }

    private function apiKey(): string
    {
        $key = defined('TTTTT_API_KEY') ? (string) TTTTT_API_KEY : '';
        if ($key === '') {
            throw new StorageException('STORAGE_AUTH',
                '尚未配置钛盘 API Key，请到后台「设置」页填写', 400);
        }
        return $key;
    }

    /** Key 的参数名。已确认就是 key；此项保留仅作兜底（SPEC §7.2.2） */
    private function keyParam(): string
    {
        $p = (string) Settings::get('ttttt_key_param', 'key');
        return $p !== '' ? $p : 'key';
    }

    /**
     * 执行一次远程调用。
     * 【不得关闭 SSL 校验】关闭等于把 API Key 和图片内容暴露给中间人（SPEC §7.2.2）。
     */
    private function request(string $url, array $fields, array $files = []): array
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new StorageException('STORAGE_BUSY', '远程调用预算已用尽，请重试', 504);
        }

        $post = $fields;
        foreach ($files as $name => $file) {
            $post[$name] = $file;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            // 与剩余预算比对，取较小值（SPEC §10.2）
            CURLOPT_TIMEOUT        => max(1, min(self::TIMEOUT, (int) floor($remaining))),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,   // 不得因为任何原因关闭
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body  = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errNo !== 0) {
            throw new StorageException('STORAGE_BUSY',
                '图床连接失败：' . ($err !== '' ? $err : '未知网络错误'), 504);
        }

        if ($code >= 500) {
            throw new StorageException('STORAGE_BUSY', '图床服务暂时不可用（HTTP ' . $code . '）',
                503, null, (string) $body);
        }

        return ['body' => (string) $body, 'http_code' => $code];
    }

    /** 解析 JSON 响应。解析失败不抛异常，交由调用方按 status=null 走「未知」分支。 */
    private function decode(string $body): array
    {
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /** 远程调用预算检查 */
    private function assertBudget(): void
    {
        if (microtime(true) >= $this->deadline) {
            throw new StorageException('STORAGE_BUSY',
                '上传耗时超出预算已中止，请重试（已完成的进度会保留）', 504);
        }
    }
}
