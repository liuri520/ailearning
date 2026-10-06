<?php
/**
 * actions/admin.php —— 后台接口注册（SPEC §6.3）
 *
 * 【守卫策略】除 auth.* 外，全部 needsLogin = true。
 * Router 会对它们统一执行：requireLogin() → 写请求 verifyCsrf() → noCache()。
 * 这里不再重复判断登录状态 —— 分散的守卫一定会有遗漏的那一个。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

// ════════════════════════════════════════════════════════════
//  认证（SPEC §6.3 auth.*）
// ════════════════════════════════════════════════════════════

Router::add('auth.login', static function () {
    $password = (string) admin_input('password', '');
    if ($password === '') {
        Response::fail('VALIDATION_FAILED', '请输入密码', Response::BAD_REQUEST, 'password');
    }

    // Auth::login 内部做了限流 + 固定延时 + 会话重建，失败会直接响应
    return ['data' => Auth::login($password)];
}, needsLogin: false);

Router::add('auth.logout', static function () {
    Auth::logout();
    Response::ok(['logged_out' => true]);
}, needsLogin: false);

/**
 * 探测登录态。后台 SPA 启动时第一个调用。
 * 未登录返回 ok + logged_in:false，**不是** 401 ——
 * 401 会被前端当作「会话过期」弹提示，而首次访问本该是静默的。
 */
Router::add('auth.me', static function () {
    if (!Auth::isLoggedIn()) {
        return ['data' => ['logged_in' => false]];
    }
    return ['data' => [
        'logged_in'  => true,
        'user'       => Auth::currentUser(),
        // CSRF token 必须由 auth.me 下发：页面刷新后前端只记得会话，
        // 拿不到 token 就发不出任何写请求（SPEC §9.3）
        'csrf_token' => Auth::csrfToken(),
    ]];
}, needsLogin: false);

Router::add('auth.password', static function () {
    $new = (string) admin_input('new_password', '');
    if (mb_strlen($new) < 8) {
        Response::fail('VALIDATION_FAILED', '新密码至少 8 位', Response::BAD_REQUEST, 'new_password');
    }
    if ($new !== (string) admin_input('confirm_password', '')) {
        Response::fail('VALIDATION_FAILED', '两次输入的密码不一致', Response::BAD_REQUEST, 'confirm_password');
    }

    Auth::changePassword($new);

    // 改密后当前会话已被重建，需要把新 token 交回前端，否则下一次写请求必 403
    return ['data' => ['changed' => true, 'csrf_token' => Auth::csrfToken()]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  内容（SPEC §6.3 admin.content.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.content.list', static function () {
    $f = [
        'type'     => $_GET['type']     ?? '',
        'status'   => $_GET['status']   ?? '',
        'tag'      => $_GET['tag']      ?? '',
        'q'        => $_GET['q']        ?? '',
        'year'     => $_GET['year']     ?? 0,
        'month'    => $_GET['month']    ?? 0,
        'page'     => $_GET['page']     ?? 1,
        'per_page' => $_GET['per_page'] ?? 20,
    ];

    $result = Content::adminList($f);

    // 后台列表不返回 body_md（可能几十 KB），只给摘要与状态
    $rows = [];
    foreach ($result['rows'] as $row) {
        $rows[] = [
            'id'           => (int) $row['id'],
            'type'         => (string) $row['type'],
            'slug'         => (string) $row['slug'],
            'title'        => (string) $row['title'],
            'summary'      => (string) ($row['summary'] ?? ''),
            'cover_path'   => $row['cover_path'] ?? null,
            'status'       => (string) $row['status'],
            'is_featured'  => (int) $row['is_featured'] === 1,
            'sort_weight'  => (int) $row['sort_weight'],
            'published_at' => $row['published_at'] ?? null,
            'deleted_at'   => $row['deleted_at'] ?? null,
            'updated_at'   => $row['updated_at'] ?? null,
            'tags'         => $row['tags'] ?? [],
        ];
    }

    return ['data' => $rows, 'meta' => public_page_meta($result)];
}, needsLogin: true);

Router::add('admin.content.get', static function () {
    $id = (int) ($_GET['id'] ?? 0);
    $row = Content::adminGet($id);
    if ($row === null) {
        Response::fail('NOT_FOUND', '内容不存在', Response::NOT_FOUND, 'id');
    }
    return ['data' => $row];
}, needsLogin: true);

Router::add('admin.content.save', static function () {
    $in = admin_input_all();
    $id = isset($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null;

    $savedId = Content::save($in, $id);

    return ['data' => ['id' => $savedId]];
}, needsLogin: true);

Router::add('admin.content.trash', static function () {
    Content::trash((int) admin_input('id', 0));
    return ['data' => ['trashed' => true]];
}, needsLogin: true);

Router::add('admin.content.restore', static function () {
    Content::restore((int) admin_input('id', 0));
    return ['data' => ['restored' => true]];
}, needsLogin: true);

Router::add('admin.content.destroy', static function () {
    Content::destroy((int) admin_input('id', 0));
    return ['data' => ['destroyed' => true]];
}, needsLogin: true);

Router::add('admin.content.batch', static function () {
    $ids = (array) admin_input('ids', []);
    $op  = (string) admin_input('op', '');
    $args = (array) admin_input('args', []);

    return ['data' => Content::batch($ids, $op, $args)];
}, needsLogin: true);

Router::add('admin.content.reorder', static function () {
    Content::reorder((array) admin_input('ids', []));
    return ['data' => ['reordered' => true]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  标签（SPEC §6.3 admin.tag.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.tag.list', static function () {
    return ['data' => Tag::adminAll()];
}, needsLogin: true);

Router::add('admin.tag.save', static function () {
    $in = admin_input_all();
    $id = isset($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null;

    return ['data' => ['id' => Tag::save($in, $id)]];
}, needsLogin: true);

/** 删除前先查影响面，让前端能弹「将影响 N 篇内容」的确认框 */
Router::add('admin.tag.delete', static function () {
    $id = (int) admin_input('id', 0);
    $affected = Tag::countContent($id);

    if ((bool) admin_input('confirm', false) === false && $affected > 0) {
        // 未确认且确有影响 → 只回报影响面，不执行删除
        return ['data' => ['deleted' => false, 'affected' => $affected, 'need_confirm' => true]];
    }

    $result = Tag::delete($id);
    return ['data' => ['deleted' => true, 'affected' => $result['affected'] ?? $affected]];
}, needsLogin: true);

Router::add('admin.tag.merge', static function () {
    $from = (int) admin_input('from_id', 0);
    $into = (int) admin_input('into_id', 0);

    return ['data' => Tag::merge($from, $into)];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  媒体库（SPEC §6.3 admin.asset.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.asset.list', static function () {
    return ['data' => Asset::list([
        'q'            => $_GET['q']            ?? '',
        'provider'     => $_GET['provider']     ?? '',
        'check_status' => $_GET['check_status'] ?? '',
        'orphan'       => $_GET['orphan']       ?? '',
        'no_thumb'     => $_GET['no_thumb']     ?? '',
        'page'         => $_GET['page']         ?? 1,
        'per_page'     => $_GET['per_page']     ?? 24,
    ])];
}, needsLogin: true);

Router::add('admin.asset.upload', static function () {
    if (empty($_FILES)) {
        Response::fail('VALIDATION_FAILED',
            '未收到文件。若上传较大图片请检查 php.ini 的 post_max_size', Response::BAD_REQUEST, 'full');
    }

    $result = Asset::upload($_FILES, [
        'width'        => $_POST['width']        ?? 0,
        'height'       => $_POST['height']       ?? 0,
        'thumb_width'  => $_POST['thumb_width']  ?? 0,
        'thumb_height' => $_POST['thumb_height'] ?? 0,
    ]);

    Settings::bumpCacheVersion();
    return ['data' => $result];
}, needsLogin: true);

Router::add('admin.asset.add', static function () {
    $in = admin_input_all();
    return ['data' => ['id' => Asset::addExternal($in)]];
}, needsLogin: true);

Router::add('admin.asset.recheck', static function () {
    return ['data' => Asset::recheck((int) admin_input('id', 0))];
}, needsLogin: true);

Router::add('admin.asset.relink', static function () {
    $ids = (array) admin_input('ids', []);
    if ($ids === []) {
        Response::fail('VALIDATION_FAILED', '未选择任何资产', Response::BAD_REQUEST, 'ids');
    }
    return ['data' => Asset::relink($ids)];
}, needsLogin: true);

Router::add('admin.asset.revoke', static function () {
    $id = (int) admin_input('id', 0);
    return ['data' => ['revoked' => Asset::revoke($id)]];
}, needsLogin: true);

Router::add('admin.asset.delete', static function () {
    Asset::delete((int) admin_input('id', 0));
    return ['data' => ['deleted' => true]];
}, needsLogin: true);

Router::add('admin.asset.sync', static function () {
    return ['data' => Asset::sync()];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  首页布局（SPEC §6.3 admin.home.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.home.get', static function () {
    $rows = Db::all('SELECT * FROM home_blocks ORDER BY sort_weight DESC, id ASC');

    $out = [];
    foreach ($rows as $row) {
        $config = json_decode((string) ($row['config'] ?? ''), true);
        $out[] = [
            'id'          => (int) $row['id'],
            'block_type'  => (string) $row['block_type'],
            'title'       => $row['title'],
            'config'      => is_array($config) ? $config : [],
            'sort_weight' => (int) $row['sort_weight'],
            'is_enabled'  => (int) $row['is_enabled'] === 1,
        ];
    }

    // 可选区块类型清单，供「新增区块」下拉框使用
    return ['data' => [
        'blocks'      => $out,
        'block_types' => ['banner', 'featured', 'recent_article', 'recent_video', 'gallery', 'tag_cloud', 'about'],
    ]];
}, needsLogin: true);

Router::add('admin.home.block.save', static function () {
    $in = admin_input_all();

    $type = (string) ($in['block_type'] ?? '');
    $allowed = ['banner', 'featured', 'recent_article', 'recent_video', 'gallery', 'tag_cloud', 'about'];
    if (!in_array($type, $allowed, true)) {
        Response::fail('VALIDATION_FAILED', '未知的区块类型', Response::BAD_REQUEST, 'block_type');
    }

    $title = trim((string) ($in['title'] ?? ''));
    $config = $in['config'] ?? [];
    if (is_string($config)) {
        $decoded = json_decode($config, true);
        $config = is_array($decoded) ? $decoded : [];
    }

    $row = [
        'block_type'  => $type,
        'title'       => $title === '' ? null : mb_substr($title, 0, 128),
        'config'      => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sort_weight' => (int) ($in['sort_weight'] ?? 0),
        'is_enabled'  => !empty($in['is_enabled']) ? 1 : 0,
    ];

    $id = isset($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null;
    if ($id !== null) {
        Db::update('home_blocks', $row, 'id', $id);
    } else {
        $id = Db::insert('home_blocks', $row);
    }

    Settings::bumpCacheVersion();
    return ['data' => ['id' => $id]];
}, needsLogin: true);

Router::add('admin.home.block.delete', static function () {
    Db::q('DELETE FROM home_blocks WHERE id = ?', [(int) admin_input('id', 0)]);
    Settings::bumpCacheVersion();
    return ['data' => ['deleted' => true]];
}, needsLogin: true);

Router::add('admin.home.reorder', static function () {
    $ids = array_values(array_filter(array_map('intval', (array) admin_input('ids', []))));

    Db::begin();
    try {
        $weight = count($ids) * 10;
        foreach ($ids as $id) {
            Db::q('UPDATE home_blocks SET sort_weight = ? WHERE id = ?', [$weight, $id]);
            $weight -= 10;
        }
        Db::commit();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }

    Settings::bumpCacheVersion();
    return ['data' => ['reordered' => true]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  设置（SPEC §6.3 admin.setting.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.setting.get', static function () {
    $all = Settings::all();

    // 只回可编辑项。settings 里还躺着 view_dedup_salt / cache_version 等内部状态，
    // 以及历史遗留键；白名单确保任何新增内部键都不会被顺手带出去。
    $out = [];
    foreach (admin_editable_settings() as $key) {
        $out[$key] = $all[$key] ?? '';
    }

    // 图床 Key 只在 config.php，此处仅回报「是否已配置」，让前端给出提示
    $out['ttttt_key_configured'] = defined('TTTTT_API_KEY') && (string) TTTTT_API_KEY !== '';
    $out['admin_entry'] = defined('ADMIN_ENTRY') ? (string) ADMIN_ENTRY : '';

    return ['data' => $out];
}, needsLogin: true);

Router::add('admin.setting.save', static function () {
    $in = admin_input('settings', admin_input_all());
    if (!is_array($in)) {
        Response::fail('VALIDATION_FAILED', '参数格式不正确', Response::BAD_REQUEST, 'settings');
    }

    $editable = admin_editable_settings();
    $kv = [];

    foreach ($in as $key => $value) {
        $key = (string) $key;

        // 白名单之外一律丢弃。这是防「顺手把 tttt_key 之类的运营配置写进 settings 表」
        // 的关键闸门 —— 一旦写进去，admin.setting.get 就会把它吐给前端（SPEC §7.2.2 / R7）。
        if (!in_array($key, $editable, true)) {
            continue;
        }

        if (is_array($value) || is_object($value)) {
            continue;
        }

        $kv[$key] = admin_normalize_setting($key, (string) $value);
    }

    if ($kv === []) {
        Response::fail('VALIDATION_FAILED', '没有可保存的设置项', Response::BAD_REQUEST, 'settings');
    }

    Settings::setMany($kv);
    Settings::bumpCacheVersion();

    return ['data' => ['saved' => array_keys($kv)]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  任务（SPEC §6.3 admin.task.*）
// ════════════════════════════════════════════════════════════

Router::add('admin.task.list', static function () {
    $registry = Scheduler::registry();
    $rows = Db::all('SELECT * FROM tasks ORDER BY task_key ASC');

    $out = [];
    foreach ($rows as $row) {
        $key = (string) $row['task_key'];
        $out[] = [
            'task_key'    => $key,
            'period'      => $registry[$key][1] ?? 0,
            'last_run_at' => $row['last_run_at'],
            'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            'last_result' => $row['last_result'],
            'running'     => !empty($row['locked_at'])
                             && strtotime((string) $row['locked_at']) > time() - 60,
        ];
    }

    return ['data' => $out];
}, needsLogin: true);

Router::add('admin.task.run', static function () {
    $key = (string) admin_input('task_key', '');
    if ($key === '') {
        Response::fail('VALIDATION_FAILED', '缺少 task_key', Response::BAD_REQUEST, 'task_key');
    }

    // 后台手动执行不受周期间隔限制（用户点「立即执行」就是要它现在跑）
    return ['data' => Scheduler::runOne($key)];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  导出备份（SPEC §6.3 admin.export.*；D19 手动导出）
// ════════════════════════════════════════════════════════════

Router::add('admin.export.list', static function () {
    $lastAt = (string) Settings::get('last_export_at', '');
    $days   = Settings::int('backup_remind_days', -1);

    return ['data' => [
        'last_export_at' => $lastAt === '' ? null : $lastAt,
        // -1 表示尚未导出过，前端据此显示「建议立即备份」
        'days_since'     => $lastAt === '' ? -1 : (int) floor((time() - strtotime($lastAt)) / 86400),
        'remind_days'    => $days,
        // 用 reset() 取首列，不写列名：SHOW TABLES 的列名是 `Tables_in_<库名>`，
        // 库名本地是 blog_dev、线上另起，写死会在换环境时再炸一次。
        // （原本这里写的 $r['table'] 就是这么错的，见 DEBUG.md D-10）
        'tables'         => array_map(
            static fn(array $r): string => (string) reset($r),
            admin_list_tables()
        ),
    ]];
}, needsLogin: true);

/**
 * 生成 SQL 导出，**直接把内容返回给前端**由浏览器存盘。
 *
 * 为什么不落盘再下载：data/ 下所有文件都是 `.php` + 守卫头（SPEC §9.4-1），
 * 落盘就得再写一个带鉴权的下载入口，多一个面就多一处可能配错的地方。
 * 个人博客的库体积小，一次性返回最省事也最安全。
 */
Router::add('admin.export.create', static function () {
    $sql = admin_dump_sql();

    Settings::set('last_export_at', date('Y-m-d H:i:s'));

    $filename = 'blog-backup-' . date('Ymd-His') . '.sql';

    return ['data' => [
        'filename' => $filename,
        'size'     => strlen($sql),
        'sql'      => $sql,
    ]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  系统信息与日志（SPEC §6.3 admin.sysinfo / admin.log.list）
// ════════════════════════════════════════════════════════════

Router::add('admin.sysinfo', static function () {
    $counts = [
        'article' => (int) Db::val("SELECT COUNT(*) FROM contents WHERE type = 'article' AND status <> 'trashed'"),
        'video'   => (int) Db::val("SELECT COUNT(*) FROM contents WHERE type = 'video' AND status <> 'trashed'"),
        'image'   => (int) Db::val("SELECT COUNT(*) FROM contents WHERE type = 'image' AND status <> 'trashed'"),
        'draft'   => (int) Db::val("SELECT COUNT(*) FROM contents WHERE status = 'draft'"),
        'trashed' => (int) Db::val("SELECT COUNT(*) FROM contents WHERE status = 'trashed'"),
        'tags'    => (int) Db::val('SELECT COUNT(*) FROM tags'),
        'assets'  => (int) Db::val('SELECT COUNT(*) FROM assets'),
    ];

    $brokenAssets = (int) Db::val("SELECT COUNT(*) FROM assets WHERE check_status IN ('missing','expired')");
    $orphanAssets = (int) Db::val('SELECT COUNT(*) FROM assets WHERE ref_count = 0');
    $noThumb      = (int) Db::val("SELECT COUNT(*) FROM assets WHERE is_external = 0 AND (thumb_rel_path IS NULL OR thumb_rel_path = '')");
    $nonPermanent = (int) Db::val('SELECT COUNT(*) FROM assets WHERE storage_model <> ' . TttttAdapter::MODEL_PERMANENT);

    $totalPv = (int) Db::val('SELECT COALESCE(SUM(pv), 0) FROM view_daily');
    $totalUv = (int) Db::val('SELECT COALESCE(SUM(uv), 0) FROM view_daily');

    return ['data' => [
        'counts' => $counts,
        'views'  => ['pv' => $totalPv, 'uv' => $totalUv],
        // 后台首页告警区（SPEC §10.2）
        'alerts' => [
            'broken_assets'  => $brokenAssets,
            'orphan_assets'  => $orphanAssets,
            // 没有独立缩略版的图会在列表页拉原图 → 流量风险（SPEC §3.2 张力 C）
            'no_thumb'       => $noThumb,
            // 【保险丝】非 99 模式意味着图片可能到期消失，必须显性告警
            'non_permanent'  => $nonPermanent,
            'trash_pending'  => $counts['trashed'],
        ],
        'env' => [
            'php_version'    => PHP_VERSION,
            'mysql_version'  => (string) Db::val('SELECT VERSION()'),
            'upload_limit'   => ini_get('upload_max_filesize'),
            'post_limit'     => ini_get('post_max_size'),
            'time_limit'     => ini_get('max_execution_time'),
            'has_fastcgi'    => function_exists('fastcgi_finish_request'),
            'debug'          => defined('APP_DEBUG') && APP_DEBUG,
            'storage_driver' => (string) Settings::get('storage_driver', 'ttttt'),
            'server_time'    => date('Y-m-d H:i:s'),
            'timezone'       => date_default_timezone_get(),
        ],
    ]];
}, needsLogin: true);

Router::add('admin.log.list', static function () {
    $logDir = APP_ROOT . '/data/logs';
    $files = glob($logDir . '/error-*.log.php') ?: [];
    rsort($files);

    $month = preg_replace('/[^0-9\-]/', '', (string) ($_GET['month'] ?? ''));
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

    if ($month !== '') {
        $files = array_values(array_filter($files, static fn(string $f): bool => str_contains($f, $month)));
    }

    $entries = [];
    foreach ($files as $file) {
        foreach (admin_read_log($file) as $entry) {
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break 2;
            }
        }
    }

    // 可用月份列表
    $months = [];
    foreach (glob($logDir . '/error-*.log.php') ?: [] as $f) {
        if (preg_match('/error-(\d{4}-\d{2})\.log\.php$/', $f, $m)) {
            $months[] = $m[1];
        }
    }
    rsort($months);

    return ['data' => ['entries' => $entries, 'months' => array_values(array_unique($months))]];
}, needsLogin: true);

// ════════════════════════════════════════════════════════════
//  辅助函数
// ════════════════════════════════════════════════════════════

/** 取单个入参 */
function admin_input(string $key, $default = null)
{
    $all = admin_input_all();
    return $all[$key] ?? $default;
}

/**
 * 读取全部入参，合并 JSON body 与表单。
 *
 * 后台写请求有两种来源：SPA 的 fetch（JSON）与上传（multipart）。
 * 统一在这里归一，处理函数就只需面对一个数组（SPEC §6.1）。
 * @return array<string, mixed>
 */
function admin_input_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $body = $_POST;

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
    }

    $cache = $body;
    return $cache;
}

/** 后台可编辑的 settings 键（白名单，SPEC 附录 B 的用户可配置子集） */
function admin_editable_settings(): array
{
    return [
        'site_name', 'site_desc', 'site_icp',
        'cdn_base', 'cdn_whitelist', 'frame_whitelist', 'ttttt_direct_template',
        'cache_enabled', 'cache_ttl', 'cron_probability',
        'storage_driver', 'ttttt_key_param', 'ttttt_max_bytes',
        'image_thumb_edge', 'image_full_edge', 'page_size',
        'backup_remind_days',
    ];
}

/** 设置项的取值规范化 */
function admin_normalize_setting(string $key, string $value): string
{
    $value = trim($value);

    switch ($key) {
        case 'cache_enabled':
            return $value === '1' || $value === 'true' ? '1' : '0';

        case 'cache_ttl':
            return (string) max(60, min(86400, (int) $value));

        case 'cron_probability':
            // 概率按百分比理解，限制在 0–100，避免误填 1000 导致每个请求都跑任务
            return (string) max(0, min(100, (int) $value));

        case 'ttttt_max_bytes':
            return (string) max(1048576, min(104857600, (int) $value));

        case 'image_thumb_edge':
            return (string) max(100, min(2000, (int) $value));

        case 'image_full_edge':
            return (string) max(400, min(6000, (int) $value));

        case 'page_size':
            return (string) max(1, min(100, (int) $value));

        case 'backup_remind_days':
            return (string) max(-1, min(365, (int) $value));

        case 'storage_driver':
            return in_array($value, ['ttttt', 'manual'], true) ? $value : 'ttttt';

        case 'ttttt_key_param':
            return preg_match('/^[a-z_]{1,32}$/', $value) ? $value : 'key';

        case 'cdn_whitelist':
        case 'frame_whitelist':
            // 这两个值会被直接拼进 Content-Security-Policy 响应头。
            // 若原样透传，一个分号就能往头里塞进任意指令（CSP 指令注入），
            // 一个换行更是能分裂出第二个响应头（CRLF 注入）。
            // 所以这里按 CSP 源表达式的合法字符集过滤，只留下域名/源本身。
            return admin_normalize_domains($value);

        case 'ttttt_direct_template':
            // 模板必须同时含两个占位符，否则拼出来的直链全是坏的
            if ($value !== '' && (!str_contains($value, '{dkey}') || !str_contains($value, '{name}'))) {
                Response::fail('VALIDATION_FAILED',
                    '直链模板必须同时包含 {dkey} 与 {name} 占位符',
                    Response::BAD_REQUEST, 'ttttt_direct_template');
            }
            return mb_substr($value, 0, 500);

        default:
            return mb_substr($value, 0, 1000);
    }
}

/**
 * 规范化一串 CSP 来源（图片域名 / 嵌入域名）。
 *
 * 输入允许用换行、空格或逗号分隔 —— 用户不会在意自己敲的是哪种。
 * 输出统一改用换行，并在设置页里正好显示成「一行一个域名」。
 *
 * 【为什么必须过滤字符】这两个值会原样进 CSP 响应头。放行分号 = 攻击者
 * （或只是手滑）能往 CSP 里追加 `; script-src *` 之类的指令；放行换行 =
 * 拆分出第二个响应头。字符集限制在这里是最省事也最彻底的防线。
 *
 * 允许的字符集涵盖完整 URL 的常见写法，因此这些都能填：
 *   https://download.example.com   （带协议，CSP 会连协议一起匹配）
 *   *.example.net                  （通配子域）
 *   https:                         （该协议下任意来源，慎用）
 *   i.example.com:8443             （带端口）
 * 排除的 `; , " ' \` 与空白，正是能构造头注入的那几个 —— 一个都不放。
 *
 * @return string 换行分隔的合法来源；全部非法时返回空串（等于没配）
 */
function admin_normalize_domains(string $value): string
{
    $tokens = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ok = [];
    foreach ($tokens as $t) {
        $t = strtolower($t);
        if ($t !== '' && strlen($t) <= 253 && preg_match('#^[a-z0-9.\-:*/?_~%=+@\[\]]+$#', $t)) {
            $ok[$t] = true;   // 用键去重，顺手把重复项吃掉
        }
    }
    return implode("\n", array_keys($ok));
}

/** 库中实际存在的表（导出时按需列举） */
function admin_list_tables(): array
{
    return Db::all('SHOW TABLES');
}

/** 生成整库 SQL 导出 */
function admin_dump_sql(): string
{
    $tables = [];
    foreach (admin_list_tables() as $row) {
        $tables[] = (string) reset($row);
    }
    sort($tables);

    $out = "-- 博客数据导出\n-- 生成时间：" . date('Y-m-d H:i:s') . "\n"
         . "-- PHP " . PHP_VERSION . " / MySQL " . Db::val('SELECT VERSION()') . "\n\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        // 还原时先删后建。用反引号包裹，表名来自 SHOW TABLES 而非用户输入。
        $create = Db::one('SHOW CREATE TABLE `' . $table . '`');
        $ddl = (string) ($create['Create Table'] ?? '');

        $out .= "-- ----------------------------\n"
              . "-- 表结构：{$table}\n"
              . "-- ----------------------------\n"
              . "DROP TABLE IF EXISTS `{$table}`;\n"
              . $ddl . ";\n\n";

        // 分批读取，避免大表一次性载入内存
        $offset = 0;
        $batch  = 500;
        while (true) {
            $rows = Db::all('SELECT * FROM `' . $table . '` LIMIT ' . $batch . ' OFFSET ' . $offset);
            if ($rows === []) {
                break;
            }

            $columns = array_keys($rows[0]);
            $quoted  = '(`' . implode('`, `', $columns) . '`)';

            foreach (array_chunk($rows, 100) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $cells = [];
                    foreach ($columns as $col) {
                        $v = $row[$col] ?? null;
                        $cells[] = $v === null ? 'NULL' : "'" . Db::escape($v) . "'";
                    }
                    $values[] = '(' . implode(', ', $cells) . ')';
                }

                $out .= "INSERT INTO `{$table}` {$quoted} VALUES\n"
                      . implode(",\n", $values) . ";\n";
            }

            $offset += count($rows);
            if (count($rows) < $batch) {
                break;
            }
        }

        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

/**
 * 解析日志文件为结构化条目。
 * 每条形如：
 *   [2026-10-06 12:00:00] RuntimeError 消息
 *   {"file":"...","trace":"..."}
 */
function admin_read_log(string $file): array
{
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return [];
    }

    // 去掉守卫头
    if (str_starts_with($raw, '<?php')) {
        $pos = strpos($raw, "\n");
        $raw = $pos === false ? '' : substr($raw, $pos + 1);
    }

    $lines = explode("\n", $raw);
    $entries = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s+(.*)$/', $line, $m)) {
            continue;
        }

        $context = null;
        $next = $lines[$i + 1] ?? '';
        if ($next !== '' && ($next[0] ?? '') === '{') {
            $decoded = json_decode($next, true);
            if (is_array($decoded)) {
                $context = $decoded;
                $i++;
            }
        }

        // 最新在前
        array_unshift($entries, [
            'time'    => $m[1],
            'level'   => $m[2],
            'message' => $m[3],
            'context' => $context,
            'file'    => basename($file),
        ]);

        if (count($entries) > 500) {
            array_pop($entries);
        }
    }

    return $entries;
}
