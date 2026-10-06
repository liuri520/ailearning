<?php
/**
 * actions/public.php —— 前台只读接口注册（SPEC §6.2）
 *
 * 本文件只做三件事：取参 → 调领域类 → 包装响应。
 * 不做业务判断（那属于 Content/Tag/Asset），不碰 SQL。
 *
 * 【输出裁剪原则】前台接口一律不下发 status / deleted_at / sort_weight 等
 * 后台字段。VISIBLE_SQL 已经保证了「查得到就能看」，但字段层面的最小暴露
 * 是第二道防线 —— 后台将来新增内部列时不会意外泄漏到前台。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

// ════════════════════════════════════════════════════════════
//  content.list —— 前台列表（文章 / 视频 / 图集）
// ════════════════════════════════════════════════════════════
Router::add('content.list', static function () {
    $f = [
        'type'     => $_GET['type']     ?? '',
        'tag'      => $_GET['tag']      ?? '',
        'q'        => $_GET['q']        ?? '',
        'year'     => $_GET['year']     ?? 0,
        'month'    => $_GET['month']    ?? 0,
        'featured' => $_GET['featured'] ?? '',
        'page'     => $_GET['page']     ?? 1,
        'per_page' => $_GET['per_page'] ?? 0,
    ];

    $result = Cache::remember('content.list', $f, static fn() => Content::list($f));

    return [
        'data' => array_map('public_content_row', public_attach_image_meta($result['rows'])),
        'meta' => public_page_meta($result),
    ];
});

// ════════════════════════════════════════════════════════════
//  content.detail —— 前台详情（按 slug）
// ════════════════════════════════════════════════════════════
Router::add('content.detail', static function () {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    if ($slug === '') {
        Response::fail('VALIDATION_FAILED', '缺少 slug 参数', Response::BAD_REQUEST, 'slug');
    }

    $row = Cache::remember('content.detail', ['slug' => $slug], static fn() => Content::detailBySlug($slug));

    if ($row === null) {
        // 草稿 / 定时未到 / 已删除 / 不存在 —— 对前台统一表现为「不存在」，
        // 不区分文案，避免通过错误信息探测草稿是否存在（SPEC §9.4）
        Response::fail('NOT_FOUND', '内容不存在或尚未发布', Response::NOT_FOUND);
    }

    return ['data' => $row];
});

// ════════════════════════════════════════════════════════════
//  content.related —— 基于共同标签的相关推荐
// ════════════════════════════════════════════════════════════
Router::add('content.related', static function () {
    $slug  = trim((string) ($_GET['slug'] ?? ''));
    $limit = (int) ($_GET['limit'] ?? 6);

    if ($slug === '') {
        return ['data' => []];
    }

    $rows = Cache::remember('content.related', ['slug' => $slug, 'limit' => $limit],
        static fn() => Content::related($slug, $limit));

    return ['data' => array_map('public_content_row', public_attach_image_meta($rows))];
});

// ════════════════════════════════════════════════════════════
//  tag.list —— 标签及计数
// ════════════════════════════════════════════════════════════
Router::add('tag.list', static function () {
    $type = trim((string) ($_GET['type'] ?? ''));
    $tags = Cache::remember('tag.list', ['type' => $type], static fn() => Tag::list($type ?: null));

    return ['data' => $tags];
});

// ════════════════════════════════════════════════════════════
//  archive.list —— 归档（按年月聚合）
// ════════════════════════════════════════════════════════════
Router::add('archive.list', static function () {
    $type = trim((string) ($_GET['type'] ?? ''));
    $rows = Cache::remember('archive.list', ['type' => $type], static fn() => Content::archive($type ?: null));

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'year'  => (int) $row['year'],
            'month' => (int) $row['month'],
            'count' => (int) $row['count'],
        ];
    }

    return ['data' => $out];
});

// ════════════════════════════════════════════════════════════
//  site.home —— 首页聚合（按 home_blocks 配置组装）
// ════════════════════════════════════════════════════════════
Router::add('site.home', static function () {
    $blocks = Cache::remember('site.home', [], static function () {
        $rows = Db::all(
            'SELECT * FROM home_blocks WHERE is_enabled = 1 ORDER BY sort_weight DESC, id ASC'
        );

        $out = [];
        foreach ($rows as $row) {
            $config = [];
            if (!empty($row['config'])) {
                $decoded = json_decode((string) $row['config'], true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }

            $limit = max(1, min(24, (int) ($config['limit'] ?? 6)));
            $item = [
                'block_type' => (string) $row['block_type'],
                'title'      => $row['title'],
                'config'     => $config,
                'items'      => [],
            ];

            switch ($row['block_type']) {
                case 'banner':
                    // banner 不需要额外数据，前端自行读 site.meta
                    break;

                case 'featured':
                    $item['items'] = array_map('public_content_row',
                        public_attach_image_meta(Content::featured($limit)));
                    break;

                case 'recent_article':
                    $item['items'] = array_map('public_content_row',
                        public_attach_image_meta(
                            Content::list(['type' => 'article', 'page' => 1, 'per_page' => $limit])['rows']));
                    break;

                case 'recent_video':
                    $item['items'] = array_map('public_content_row',
                        public_attach_image_meta(
                            Content::list(['type' => 'video', 'page' => 1, 'per_page' => $limit])['rows']));
                    break;

                case 'gallery':
                    // 图集区块没有 URL 就只是一排灰块，必须补 image_meta
                    $item['items'] = array_map('public_content_row',
                        public_attach_image_meta(
                            Content::list(['type' => 'image', 'page' => 1, 'per_page' => $limit])['rows']));
                    break;

                case 'tag_cloud':
                    $tags = Tag::list(null);
                    $item['items'] = array_slice($tags, 0, $limit);
                    break;

                case 'about':
                    // 正文来自区块 config，前端按 Markdown 渲染
                    break;
            }

            $out[] = $item;
        }

        return $out;
    });

    // site.meta 随首页一并下发，省掉前端一次请求
    return ['data' => ['blocks' => $blocks, 'site' => public_site_meta()]];
});

// ════════════════════════════════════════════════════════════
//  site.meta —— 站点公开信息
// ════════════════════════════════════════════════════════════
Router::add('site.meta', static function () {
    return ['data' => public_site_meta()];
});

// ════════════════════════════════════════════════════════════
//  view.hit —— 浏览上报（SPEC §7.5）
// ════════════════════════════════════════════════════════════

/**
 * 【必须永远返回 ok】浏览统计不能影响正常浏览。
 * 前端是 fire-and-forget 调用，任何失败都应被无声吞掉。
 */
Router::add('view.hit', static function () {
    $slug = trim((string) ($_POST['slug'] ?? $_GET['slug'] ?? ''));
    if ($slug !== '') {
        // reportView 内部已 try/catch 全吞，此处不再兜
        Content::reportView($slug);
    }

    return ['data' => ['recorded' => true]];
});

// ════════════════════════════════════════════════════════════
//  search.suggest —— 标题前缀建议（走 idx_title）
// ════════════════════════════════════════════════════════════
Router::add('search.suggest', static function () {
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $limit   = (int) ($_GET['limit'] ?? 8);

    if ($keyword === '') {
        return ['data' => []];
    }

    // 建议列表是输入框实时调用的，不缓存（每个前缀都不同，缓存只会堆垃圾文件）
    $rows = Content::suggest($keyword, $limit);

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'slug'  => (string) $row['slug'],
            'title' => (string) $row['title'],
            'type'  => (string) $row['type'],
        ];
    }

    return ['data' => $out];
});

// ════════════════════════════════════════════════════════════
//  辅助函数（仅本文件使用；PHP 无模块私有函数，故加前缀避免命名冲突）
// ════════════════════════════════════════════════════════════

/**
 * 裁剪前台可见字段。
 * 不直接返回数据库整行 —— hydrate() 会带出后台字段，list 则带出 status/deleted_at。
 */
function public_content_row(array $row): array
{
    $out = [
        'id'           => (int) $row['id'],
        'type'         => (string) $row['type'],
        'slug'         => (string) $row['slug'],
        'title'        => (string) $row['title'],
        'summary'      => (string) ($row['summary'] ?? ''),
        'cover_path'   => $row['cover_path'] ?? null,
        'is_featured'  => (int) ($row['is_featured'] ?? 0) === 1,
        'published_at' => $row['published_at'] ?? null,
        'created_at'   => $row['created_at'] ?? null,
        'tags'         => $row['tags'] ?? [],
    ];

    // 详情页才有的字段，列表页不重复下发（省带宽，也避免误用）
    foreach (['body_md', 'word_count', 'image_url', 'thumb_url', 'asset_id',
              'asset_path', 'width', 'height', 'alt_text', 'camera_note',
              'image_meta_asset', 'source_type', 'provider', 'source_key',
              'poster_path', 'poster_url', 'duration_seconds', 'aspect_ratio',
              'redirect_to'] as $key) {
        if (array_key_exists($key, $row)) {
            $out[$key] = $row[$key];
        }
    }

    return $out;
}

/**
 * 批量补全图片类内容的展示信息。
 *
 * 【为什么需要】Content::list() 走的是轻量路径（只查 contents + 标签），
 * 不带 image_meta —— 列表页不需要正文，这本来是对的。但图集列表**必须**
 * 有图可显示，否则画廊会变成一排灰色占位。
 * 用一条 JOIN 补齐，而不是每行查一次（N+1）。
 */
function public_attach_image_meta(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (($row['type'] ?? '') === 'image') {
            $ids[] = (int) $row['id'];
        }
    }
    if ($ids === []) {
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $metas = Db::all(
        "SELECT im.content_id, im.asset_id, im.asset_path, im.width, im.height, im.alt_text,
                a.id AS a_id, a.rel_path, a.thumb_rel_path, a.remote_dkey, a.remote_name,
                a.direct_url, a.thumb_dkey, a.thumb_name, a.thumb_direct_url,
                a.provider, a.is_external, a.width AS a_width, a.height AS a_height,
                a.check_status
           FROM image_meta im
           LEFT JOIN assets a ON a.id = im.asset_id
          WHERE im.content_id IN ($placeholders)",
        $ids
    );

    $byContent = [];
    foreach ($metas as $meta) {
        $byContent[(int) $meta['content_id']] = $meta;
    }

    foreach ($rows as $i => $row) {
        $meta = $byContent[(int) $row['id']] ?? null;
        if ($meta === null) {
            continue;
        }

        $rows[$i]['asset_id']   = $meta['asset_id'] !== null ? (int) $meta['asset_id'] : null;
        $rows[$i]['asset_path'] = (string) $meta['asset_path'];
        // 内容自身的 width/height 优先 —— 它才是版式依据
        $rows[$i]['width']      = $meta['width'] !== null ? (int) $meta['width']
                                : ($meta['a_width'] !== null ? (int) $meta['a_width'] : null);
        $rows[$i]['height']     = $meta['height'] !== null ? (int) $meta['height']
                                : ($meta['a_height'] !== null ? (int) $meta['a_height'] : null);
        $rows[$i]['alt_text']   = $meta['alt_text'];

        // 四层回退由 Asset::url() 统一负责，这里只负责把资产行还原出来
        $asset = null;
        if ($meta['a_id'] !== null) {
            $asset = [
                'id'               => (int) $meta['a_id'],
                'rel_path'         => $meta['rel_path'],
                'thumb_rel_path'   => $meta['thumb_rel_path'],
                'remote_dkey'      => $meta['remote_dkey'],
                'remote_name'      => $meta['remote_name'],
                'direct_url'       => $meta['direct_url'],
                'thumb_dkey'       => $meta['thumb_dkey'],
                'thumb_name'       => $meta['thumb_name'],
                'thumb_direct_url' => $meta['thumb_direct_url'],
            ];
        } elseif (!empty($meta['asset_path'])) {
            // 没有 asset_id 的历史数据，仅凭冗余的 asset_path 解析
            $asset = ['id' => 0, 'rel_path' => $meta['asset_path']];
        }

        if ($asset !== null) {
            $rows[$i]['image_url'] = Asset::url($asset, false);
            $rows[$i]['thumb_url'] = Asset::url($asset, true) ?? Asset::url($asset, false);
        }
    }

    return $rows;
}

/** 分页 meta */
function public_page_meta(array $result): array
{
    $perPage = max(1, (int) $result['per_page']);
    $total   = (int) $result['total'];

    return [
        'total'       => $total,
        'page'        => (int) $result['page'],
        'per_page'    => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ];
}

/**
 * 站点公开信息白名单。
 *
 * 【为什么用白名单而不是黑名单】settings 表里混着 ttttt_* 之类的运营配置，
 * 黑名单式「排除敏感项」在新增配置项时必然漏掉。这里只列可以公开的键，
 * 以后无论 settings 加什么，都不会顺着这个口子流出去（SPEC §7.2.2 / R7）。
 */
function public_site_meta(): array
{
    return [
        'site_name'        => (string) Settings::get('site_name', ''),
        'site_desc'        => (string) Settings::get('site_desc', ''),
        'site_icp'         => (string) Settings::get('site_icp', ''),
        'page_size'        => Settings::int('page_size', 10),
        'comments_enabled' => Settings::bool('comments_enabled', false),
        // 前端按此值做上传前预检（超过就不必白传一趟）
        'image_thumb_edge' => Settings::int('image_thumb_edge', 800),
        'image_full_edge'  => Settings::int('image_full_edge', 2560),
        'max_upload_bytes' => Settings::int('ttttt_max_bytes', 104857600),
    ];
}
