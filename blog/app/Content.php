<?php
/**
 * Content.php —— 内容服务（文章 / 视频 / 图片三类共用）
 *
 * 【唯一可见性条件】所有前台查询必须拼上：
 *     status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
 * 这是「定时发布」的实现方式 —— 未来时间的内容到点自然可见，零任务依赖。
 * SPEC §3.2 张力 B 明确要求「不要改成任务驱动」。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Content
{
    public const TYPES = ['article', 'video', 'image'];

    /** 单篇内容最多 10 个标签（SPEC §10.1） */
    private const MAX_TAGS = 10;

    /** 前台可见性条件。别名固定为 c。 */
    public const VISIBLE_SQL = "c.status = 'published' AND c.published_at IS NOT NULL AND c.published_at <= NOW()";

    // ════════════════════════════════════════════════════════
    //  公开读取
    // ════════════════════════════════════════════════════════

    /**
     * 前台列表。
     * @param array $f type / tag / q / year / month / page / per_page / featured
     * @return array{rows: array, total: int, page: int, per_page: int}
     */
    public static function list(array $f): array
    {
        [$where, $params, $joins] = self::buildFilter($f, admin: false);

        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = self::perPage($f);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.* FROM contents c ' . $joins
             . ' WHERE ' . $where
             . ' ORDER BY c.sort_weight DESC, c.published_at DESC, c.id DESC'
             . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        $rows = Db::all($sql, $params);
        $rows = self::attachTags($rows);

        $countSql = 'SELECT COUNT(DISTINCT c.id) FROM contents c ' . $joins . ' WHERE ' . $where;
        $total = (int) Db::val($countSql, $params);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** 后台列表：含草稿与回收站 */
    public static function adminList(array $f): array
    {
        [$where, $params, $joins] = self::buildFilter($f, admin: true);

        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = self::perPage($f, 20);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.* FROM contents c ' . $joins
             . ' WHERE ' . $where
             . ' ORDER BY c.updated_at DESC, c.id DESC'
             . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        $rows = self::attachTags(Db::all($sql, $params));

        $total = (int) Db::val('SELECT COUNT(DISTINCT c.id) FROM contents c ' . $joins . ' WHERE ' . $where, $params);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** 前台详情（按 slug）。命中 slug_redirects 时返回值带 redirect_to。 */
    public static function detailBySlug(string $slug): ?array
    {
        $row = Db::one(
            'SELECT c.* FROM contents c WHERE c.slug = ? AND ' . self::VISIBLE_SQL . ' LIMIT 1',
            [$slug]
        );
        if ($row !== null) {
            return self::hydrate($row);
        }

        // 旧 slug 重定向（SPEC §10.1）
        $newId = Db::val('SELECT content_id FROM slug_redirects WHERE old_slug = ? LIMIT 1', [$slug]);
        if ($newId !== null) {
            $target = self::detailById((int) $newId);
            if ($target !== null) {
                $target['redirect_to'] = $target['slug'];
                return $target;
            }
        }

        return null;
    }

    /** 前台详情（按 id），同样强制可见性 */
    public static function detailById(int $id): ?array
    {
        $row = Db::one(
            'SELECT c.* FROM contents c WHERE c.id = ? AND ' . self::VISIBLE_SQL . ' LIMIT 1',
            [$id]
        );
        return $row === null ? null : self::hydrate($row);
    }

    /** 后台取原始记录（含草稿 / 回收站，含 Markdown 原文） */
    public static function adminGet(int $id): ?array
    {
        $row = Db::one('SELECT c.* FROM contents c WHERE c.id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::hydrate($row, admin: true);
    }

    /**
     * 相关推荐：基于共同标签（SPEC §6.2 content.related）
     * 无 CTE / 窗口函数 → 普通 JOIN + GROUP BY（SPEC §2.2）。
     */
    public static function related(string $slug, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));

        $id = Db::val(
            'SELECT c.id FROM contents c WHERE c.slug = ? AND ' . self::VISIBLE_SQL . ' LIMIT 1',
            [$slug]
        );
        if ($id === null) {
            return [];
        }

        // GROUP BY 写全非聚合列，以便未来迁移 5.7/8.0 不被 ONLY_FULL_GROUP_BY 打断
        $rows = Db::all(
            'SELECT c.*, COUNT(*) AS shared_tags
               FROM content_tags t1
               JOIN content_tags t2 ON t2.tag_id = t1.tag_id AND t2.content_id <> t1.content_id
               JOIN contents c ON c.id = t2.content_id
              WHERE t1.content_id = ?
                AND ' . self::VISIBLE_SQL . '
              GROUP BY c.id, c.type, c.slug, c.title, c.summary, c.cover_path, c.status,
                       c.published_at, c.is_featured, c.sort_weight, c.deleted_at,
                       c.created_at, c.updated_at
              ORDER BY shared_tags DESC, c.published_at DESC
              LIMIT ' . (int) $limit,
            [(int) $id]
        );

        return self::attachTags($rows);
    }

    /** 归档：按年月聚合（SPEC §6.2 archive.list） */
    public static function archive(?string $type = null): array
    {
        $params = [];
        $where = self::VISIBLE_SQL;

        if ($type !== null && in_array($type, self::TYPES, true)) {
            $where .= ' AND c.type = ?';
            $params[] = $type;
        }

        return Db::all(
            'SELECT YEAR(c.published_at) AS year, MONTH(c.published_at) AS month, COUNT(*) AS count
               FROM contents c
              WHERE ' . $where . '
              GROUP BY YEAR(c.published_at), MONTH(c.published_at)
              ORDER BY year DESC, month DESC',
            $params
        );
    }

    /** 首页精选 */
    public static function featured(int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));
        $rows = Db::all(
            'SELECT c.* FROM contents c
              WHERE ' . self::VISIBLE_SQL . ' AND c.is_featured = 1
              ORDER BY c.sort_weight DESC, c.published_at DESC
              LIMIT ' . (int) $limit
        );
        return self::attachTags($rows);
    }

    /** 标题前缀建议（走 idx_title 索引，SPEC §6.2 search.suggest） */
    public static function suggest(string $keyword, int $limit = 8): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));

        return Db::all(
            'SELECT c.slug, c.title, c.type FROM contents c
              WHERE ' . self::VISIBLE_SQL . ' AND c.title LIKE ?
              ORDER BY c.published_at DESC
              LIMIT ' . (int) $limit,
            [self::escapeLike($kw) . '%']
        );
    }

    // ════════════════════════════════════════════════════════
    //  写入
    // ════════════════════════════════════════════════════════

    /**
     * 新建或更新。返回 content_id。
     * 事务内写 contents + 扩展表 + content_tags（SPEC §4.3 后台写入流程）。
     */
    public static function save(array $in, ?int $id = null): int
    {
        $type = (string) ($in['type'] ?? 'article');
        if (!in_array($type, self::TYPES, true)) {
            Response::fail('VALIDATION_FAILED', '内容类型不合法', Response::BAD_REQUEST, 'type');
        }

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            Response::fail('VALIDATION_FAILED', '标题不能为空', Response::BAD_REQUEST, 'title');
        }
        if (mb_strlen($title) > 255) {
            Response::fail('VALIDATION_FAILED', '标题不能超过 255 个字符', Response::BAD_REQUEST, 'title');
        }

        $status = (string) ($in['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'trashed'], true)) {
            Response::fail('VALIDATION_FAILED', '状态不合法', Response::BAD_REQUEST, 'status');
        }

        // ── slug ──
        $slugInput = trim((string) ($in['slug'] ?? ''));
        $baseSlug = $slugInput !== '' ? Str::slug($slugInput, 'post') : Str::slug($title, 'post');
        if (!Str::isValidSlug($baseSlug)) {
            $baseSlug = Str::slug('post-' . substr(sha1($title . microtime(true)), 0, 8));
        }
        $slug = Str::uniqueSlug($baseSlug, $id);

        // ── 发布时间 ──
        $publishedAt = trim((string) ($in['published_at'] ?? ''));
        if ($status === 'published' && $publishedAt === '') {
            $publishedAt = date('Y-m-d H:i:s');   // 发布时未填则自动填 NOW()（SPEC §10.1）
        }
        $publishedAt = $publishedAt !== '' ? self::normalizeDateTime($publishedAt) : null;

        $now = date('Y-m-d H:i:s');

        // ── 类型专属数据处理（含摘要兜底）──
        [$metaTable, $metaRow, $bodyText] = self::buildMeta($type, $in);

        // ── 摘要：留空则自动从正文截取前 120 字（SPEC §10.1）──
        $summary = trim((string) ($in['summary'] ?? ''));
        if ($summary === '') {
            $summary = Str::excerpt($bodyText, 120);
        }

        $row = [
            'type'         => $type,
            'slug'         => $slug,
            'title'        => $title,
            'summary'      => $summary === '' ? null : mb_substr($summary, 0, 500),
            'cover_path'   => self::nullable($in['cover_path'] ?? null, 500),
            'status'       => $status,
            'published_at' => $publishedAt,
            'is_featured'  => !empty($in['is_featured']) ? 1 : 0,
            'sort_weight'  => (int) ($in['sort_weight'] ?? 0),
            'updated_at'   => $now,
        ];

        Db::begin();
        try {
            if ($id === null) {
                $row['created_at'] = $now;
                $row['deleted_at'] = $status === 'trashed' ? $now : null;
                $id = Db::insert('contents', $row);
            } else {
                $existing = Db::one('SELECT id, slug, type FROM contents WHERE id = ?', [$id]);
                if ($existing === null) {
                    Db::rollback();
                    Response::fail('NOT_FOUND', '内容不存在', Response::NOT_FOUND, 'id');
                }
                if ($existing['type'] !== $type) {
                    Db::rollback();
                    Response::fail('VALIDATION_FAILED', '不能修改内容类型', Response::BAD_REQUEST, 'type');
                }

                if ($status === 'trashed') {
                    $row['deleted_at'] = $now;
                } elseif ($status === 'published') {
                    $row['deleted_at'] = null;
                }
                Db::update('contents', $row, 'id', $id);

                // slug 变更 → 写重定向（SPEC §10.1）
                $oldSlug = (string) $existing['slug'];
                if ($oldSlug !== $slug) {
                    Db::q(
                        'REPLACE INTO slug_redirects (old_slug, content_id, created_at) VALUES (?, ?, ?)',
                        [$oldSlug, $id, $now]
                    );
                }
            }

            // 扩展表：主键即 content_id，用 REPLACE 保证 1:1 幂等
            $metaRow['content_id'] = $id;
            self::replaceRow($metaTable, $metaRow);

            // 标签：同时接受 id（整数）与名称（字符串）
            $tagIds = self::resolveTags((array) ($in['tags'] ?? []));
            if (count($tagIds) > self::MAX_TAGS) {
                Db::rollback();
                Response::fail('VALIDATION_FAILED', '单篇内容最多 ' . self::MAX_TAGS . ' 个标签',
                    Response::BAD_REQUEST, 'tags');
            }
            self::syncTags($id, $tagIds);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 图片内容的 asset_id 可能被换掉，引用计数须重算，
        // 否则旧图会永远显示「被 1 处引用」而无法清理（SPEC §7.3）
        Asset::recomputeForContent($id);

        // 内容变更 → 全部缓存失效（SPEC §7.4）
        Settings::bumpCacheVersion();

        return $id;
    }

    /** 移入回收站（软删除） */
    public static function trash(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $n = Db::exec(
            "UPDATE contents SET status = 'trashed', deleted_at = ?, updated_at = ? WHERE id = ?",
            [$now, $now, $id]
        );
        if ($n === 0) {
            Response::fail('NOT_FOUND', '内容不存在', Response::NOT_FOUND, 'id');
        }
        Settings::bumpCacheVersion();
    }

    /**
     * 从回收站恢复。
     *
     * 【恢复为草稿是**有意为之**，不是 bug，别再"修"一遍】
     *
     * `trash()` 把 status 覆盖成 'trashed' 时没有留下原状态（contents 表 status
     * 只有 draft/published/trashed 三态，SPEC §5 也没有「删除前状态」这一列），
     * 所以恢复时无从还原 —— 除非加列，那就偏离了 SPEC §5 冻结的表结构。
     *
     * 取舍（已与需求方确认，见 DEBUG.md D-15）：宁愿让已发布的文章恢复后
     * 退回草稿、需要手动重新发布，也不愿因为猜错而**把一篇本该是草稿的内容
     * 意外公开**。前者是可挽回的多点一下，后者是不可逆的信息暴露。
     */
    public static function restore(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        Db::q(
            "UPDATE contents SET status = 'draft', deleted_at = NULL, updated_at = ? WHERE id = ? AND status = 'trashed'",
            [$now, $id]
        );
        Settings::bumpCacheVersion();
    }

    /**
     * 彻底删除（SPEC §10.2）。
     * 顺序：释放关联资产（含图床删除）→ 删关联行 → 删主行。
     * 图床撤销失败**不阻塞**删除流程。
     */
    public static function destroy(int $id): void
    {
        Db::begin();
        try {
            // 【顺序不能反，见 D-17】
            // 释放资产（releaseAssets）判断「还有没有人引用」靠的是重新数 image_meta，
            // 所以必须排在 image_meta 删掉**之后**。原先写成先释放、后删引用，
            // 那一刻那条引用行还在表里，COUNT(*) 至少为 1，释放分支永远进不去 ——
            // 结果是内容删了、资产记录赖着不走、钛盘上文件也永远不撤销，
            // 而 destroy 依然返回 ok，全程静默。
            // 但 id 要在删之前抄下来，删完就按 content_id 查不到了。
            $assetIds = Asset::assetIdsForContent($id);

            Db::q('DELETE FROM content_tags WHERE content_id = ?', [$id]);
            Db::q('DELETE FROM article_meta WHERE content_id = ?', [$id]);
            Db::q('DELETE FROM video_meta WHERE content_id = ?', [$id]);
            Db::q('DELETE FROM image_meta WHERE content_id = ?', [$id]);
            Db::q('DELETE FROM contents WHERE id = ?', [$id]);

            // 现在 image_meta 里已经没有这篇内容的引用了，
            // recomputeRefCount() 得到的是真实剩余引用数
            Asset::releaseAssets($assetIds);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Settings::bumpCacheVersion();
    }

    /**
     * 批量操作（SPEC §6.3 admin.content.batch）
     * @param string $op trash / restore / destroy / publish / set_tags
     */
    public static function batch(array $ids, string $op, array $args = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, static fn(int $i): bool => $i > 0);
        if ($ids === []) {
            Response::fail('VALIDATION_FAILED', '未选择任何内容', Response::BAD_REQUEST, 'ids');
        }

        $done = 0;
        foreach ($ids as $id) {
            switch ($op) {
                case 'trash':
                    self::trash($id);
                    break;
                case 'restore':
                    self::restore($id);
                    break;
                case 'destroy':
                    self::destroy($id);
                    break;
                case 'publish':
                    $now = date('Y-m-d H:i:s');
                    Db::q(
                        "UPDATE contents SET status = 'published', deleted_at = NULL,
                                published_at = COALESCE(published_at, ?), updated_at = ?
                          WHERE id = ?",
                        [$now, $now, $id]
                    );
                    break;
                case 'set_tags':
                    $tagIds = array_values(array_unique(array_map('intval', (array) ($args['tags'] ?? []))));
                    self::syncTags($id, array_slice($tagIds, 0, self::MAX_TAGS));
                    break;
                default:
                    Response::fail('VALIDATION_FAILED', '未知的批量操作：' . $op, Response::BAD_REQUEST, 'op');
            }
            $done++;
        }

        Settings::bumpCacheVersion();
        return ['affected' => $done, 'op' => $op];
    }

    /** 拖拽排序：按数组顺序写 sort_weight（SPEC §6.3 admin.content.reorder） */
    public static function reorder(array $ids): void
    {
        $ids = array_values(array_map('intval', $ids));
        $weight = count($ids);

        Db::begin();
        try {
            foreach ($ids as $id) {
                Db::q('UPDATE contents SET sort_weight = ?, updated_at = ? WHERE id = ?',
                    [$weight, date('Y-m-d H:i:s'), $id]);
                $weight--;
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Settings::bumpCacheVersion();
    }

    // ════════════════════════════════════════════════════════
    //  浏览统计（SPEC §7.5）
    // ════════════════════════════════════════════════════════

    /**
     * 上报浏览。服务端去重。
     * 【重要】写库失败一律静默忽略 —— 浏览统计不能影响正常浏览（SPEC §7.5）。
     */
    public static function reportView(string $slug): void
    {
        try {
            $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

            // 常见爬虫关键字直接丢弃
            if ($ua === '' || preg_match('/bot|spider|crawl|curl|wget|headless|python-requests|facebookexternalhit/i', $ua)) {
                return;
            }

            $contentId = Db::val(
                'SELECT c.id FROM contents c WHERE c.slug = ? AND ' . self::VISIBLE_SQL . ' LIMIT 1',
                [$slug]
            );
            if ($contentId === null) {
                return;
            }

            $today = date('Y-m-d');
            $hash = Auth::visitorHash(self::dailySalt($today));

            // 主键冲突即忽略 —— 同一访客同一天同内容只记一次（SPEC §7.5）
            Db::q(
                'INSERT IGNORE INTO view_seen (content_id, visitor_hash, stat_date) VALUES (?, ?, ?)',
                [(int) $contentId, $hash, $today]
            );
        } catch (Throwable $e) {
            // 静默：浏览统计失败不影响浏览
        }
    }

    /** 当日去重盐，每日轮换（SPEC §7.5） */
    private static function dailySalt(string $today): string
    {
        $stored = (string) Settings::get('view_dedup_salt', '');
        // 格式：YYYY-MM-DD|<salt>
        if (str_starts_with($stored, $today . '|')) {
            return substr($stored, strlen($today) + 1);
        }
        $salt = $today . '|' . bin2hex(random_bytes(16));
        Settings::set('view_dedup_salt', $salt);
        return substr($salt, strlen($today) + 1);
    }

    // ════════════════════════════════════════════════════════
    //  内部
    // ════════════════════════════════════════════════════════

    /**
     * 构造 WHERE / 参数 / JOIN。
     * @return array{0: string, 1: array, 2: string}
     */
    private static function buildFilter(array $f, bool $admin): array
    {
        $where = [];
        $params = [];
        $joins = '';

        if (!$admin) {
            $where[] = self::VISIBLE_SQL;
        } else {
            // 后台按状态筛选；默认不显示回收站
            $status = (string) ($f['status'] ?? '');
            if ($status !== '' && in_array($status, ['draft', 'published', 'trashed'], true)) {
                $where[] = 'c.status = ?';
                $params[] = $status;
            } elseif ($status !== 'all') {
                $where[] = "c.status <> 'trashed'";
            }
        }

        $type = (string) ($f['type'] ?? '');
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where[] = 'c.type = ?';
            $params[] = $type;
        }

        if (!empty($f['featured'])) {
            $where[] = 'c.is_featured = 1';
        }

        // 标签筛选（用 EXISTS，避免与关键词搜索的 JOIN 互相干扰计数）
        $tag = trim((string) ($f['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM content_tags ct JOIN tags t ON t.id = ct.tag_id
                                  WHERE ct.content_id = c.id AND (t.slug = ? OR t.name = ?))';
            $params[] = $tag;
            $params[] = $tag;
        }

        // 关键词（SPEC D15：LIKE %kw% + 标签筛选组合）
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(c.title LIKE ? OR c.summary LIKE ?
                         OR EXISTS (SELECT 1 FROM article_meta am
                                     WHERE am.content_id = c.id AND am.body_text LIKE ?))';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $year = (int) ($f['year'] ?? 0);
        if ($year > 0) {
            $where[] = 'YEAR(c.published_at) = ?';
            $params[] = $year;
        }

        $month = (int) ($f['month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $where[] = 'MONTH(c.published_at) = ?';
            $params[] = $month;
        }

        return [$where === [] ? '1=1' : implode(' AND ', $where), $params, $joins];
    }

    /** 一次性把标签挂到结果集上，避免 N+1 */
    private static function attachTags(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $tagRows = Db::all(
            "SELECT ct.content_id, t.id, t.name, t.slug
               FROM content_tags ct
               JOIN tags t ON t.id = ct.tag_id
              WHERE ct.content_id IN ($placeholders)
              ORDER BY t.name",
            $ids
        );

        $map = [];
        foreach ($tagRows as $t) {
            $map[(int) $t['content_id']][] = [
                'id'   => (int) $t['id'],
                'name' => $t['name'],
                'slug' => $t['slug'],
            ];
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['tags'] = $map[(int) $row['id']] ?? [];
            $row = self::castContentRow($row);
        }
        unset($row);

        return $rows;
    }

    /** 挂上扩展表与资产信息 */
    private static function hydrate(array $row, bool $admin = false): array
    {
        $id = (int) $row['id'];
        $type = (string) $row['type'];
        $row = self::castContentRow($row);
        $row['tags'] = [];

        $tagRows = Db::all(
            'SELECT t.id, t.name, t.slug FROM content_tags ct JOIN tags t ON t.id = ct.tag_id
              WHERE ct.content_id = ? ORDER BY t.name',
            [$id]
        );
        foreach ($tagRows as $t) {
            $row['tags'][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug']];
        }

        if ($type === 'article') {
            $meta = Db::one('SELECT * FROM article_meta WHERE content_id = ?', [$id]);
            $row['body_md'] = $meta['body_md'] ?? '';
            $row['word_count'] = (int) ($meta['word_count'] ?? 0);
            if ($admin) {
                $row['body_text'] = $meta['body_text'] ?? '';
            }
        } elseif ($type === 'video') {
            $meta = Db::one('SELECT * FROM video_meta WHERE content_id = ?', [$id]) ?? [];
            $row['source_type'] = $meta['source_type'] ?? 'embed';
            $row['provider'] = $meta['provider'] ?? null;
            $row['source_key'] = $meta['source_key'] ?? '';
            $row['poster_path'] = $meta['poster_path'] ?? null;
            $row['duration_seconds'] = isset($meta['duration_seconds']) ? (int) $meta['duration_seconds'] : null;
            $row['aspect_ratio'] = $meta['aspect_ratio'] ?? '16:9';
            $row['poster_url'] = $meta['poster_path'] ?? $row['cover_path'];
        } elseif ($type === 'image') {
            $meta = Db::one('SELECT * FROM image_meta WHERE content_id = ?', [$id]) ?? [];
            $row['asset_id'] = isset($meta['asset_id']) ? (int) $meta['asset_id'] : null;
            $row['asset_path'] = $meta['asset_path'] ?? '';
            $row['width'] = isset($meta['width']) ? (int) $meta['width'] : null;
            $row['height'] = isset($meta['height']) ? (int) $meta['height'] : null;
            $row['alt_text'] = $meta['alt_text'] ?? null;
            $row['camera_note'] = $meta['camera_note'] ?? null;

            // 由资产记录解出可用 URL（四层回退，SPEC §7.2.5）
            $asset = $row['asset_id'] !== null
                ? Db::one('SELECT * FROM assets WHERE id = ?', [$row['asset_id']])
                : Db::one('SELECT * FROM assets WHERE rel_path = ? LIMIT 1', [$row['asset_path']]);
            if ($asset !== null) {
                $row['image_url'] = Asset::url($asset, false);
                $row['thumb_url'] = Asset::url($asset, true);
                $row['image_meta_asset'] = Asset::publicShape($asset);
            }
        }

        return $row;
    }

    private static function castContentRow(array $row): array
    {
        foreach (['id', 'sort_weight'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = (int) $row[$k];
            }
        }
        if (isset($row['is_featured'])) {
            $row['is_featured'] = (int) $row['is_featured'] === 1;
        }
        return $row;
    }

    /**
     * 构造扩展表行数据。
     * @return array{0: string, 1: array, 2: string} [表名, 行数据, 用于摘要兜底的纯文本]
     */
    private static function buildMeta(string $type, array $in): array
    {
        if ($type === 'article') {
            $bodyMd = (string) ($in['body_md'] ?? '');
            if (mb_strlen($bodyMd) > 16 * 1024 * 1024) {
                Response::fail('VALIDATION_FAILED', '正文过长', Response::BAD_REQUEST, 'body_md');
            }
            $bodyText = Markdown::toText($bodyMd);

            // 正文为空允许（可能只是占位），但后台会提示（SPEC §10.1）
            return ['article_meta', [
                'body_md'    => $bodyMd,
                'body_text'  => $bodyText,
                'word_count' => Str::wordCount($bodyText),
            ], $bodyText];
        }

        if ($type === 'video') {
            $sourceType = (string) ($in['source_type'] ?? 'embed');
            if (!in_array($sourceType, ['embed', 'mp4'], true)) {
                Response::fail('VALIDATION_FAILED', '视频来源类型不合法', Response::BAD_REQUEST, 'source_type');
            }

            $provider = self::nullable($in['provider'] ?? null, 32);
            $sourceKey = trim((string) ($in['source_key'] ?? ''));
            if ($sourceKey === '') {
                Response::fail('VALIDATION_FAILED', '视频地址 / BV 号不能为空', Response::BAD_REQUEST, 'source_key');
            }
            // BV 号填成完整 URL 时自动提取（SPEC §10.3）
            $sourceKey = self::extractVideoKey($sourceType, $provider, $sourceKey, $in);

            $aspect = (string) ($in['aspect_ratio'] ?? '16:9');
            if (!preg_match('/^\d{1,3}:\d{1,3}$/', $aspect)) {
                $aspect = '16:9';   // 未填则默认（SPEC §10.3）
            }

            $poster = self::nullable($in['poster_path'] ?? null, 500);
            if ($poster === null) {
                // 无 FFmpeg 无法自动抽帧 → 封面强制必填（SPEC §10.3）
                Response::fail('VALIDATION_FAILED', '视频封面为必填项（无法自动抽帧）',
                    Response::BAD_REQUEST, 'poster_path');
            }

            return ['video_meta', [
                'source_type'      => $sourceType,
                'provider'         => $provider,
                'source_key'       => mb_substr($sourceKey, 0, 255),
                'poster_path'      => $poster,
                'duration_seconds' => isset($in['duration_seconds']) && $in['duration_seconds'] !== ''
                    ? max(0, (int) $in['duration_seconds']) : null,
                'aspect_ratio'     => $aspect,
            ], trim((string) ($in['summary'] ?? '') . ' ' . $sourceKey)];
        }

        // image
        $width = (int) ($in['width'] ?? 0);
        $height = (int) ($in['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            Response::fail('VALIDATION_FAILED', '图片宽高为必填项（防布局跳动）',
                Response::BAD_REQUEST, 'width');
        }

        return ['image_meta', [
            'asset_id'    => isset($in['asset_id']) && (int) $in['asset_id'] > 0 ? (int) $in['asset_id'] : null,
            'asset_path'  => mb_substr((string) ($in['asset_path'] ?? ''), 0, 500),
            'width'       => $width,
            'height'      => $height,
            'alt_text'    => self::nullable($in['alt_text'] ?? null, 255),
            'camera_note' => self::nullable($in['camera_note'] ?? null, 255),
        ], trim((string) ($in['summary'] ?? '') . ' ' . (string) ($in['alt_text'] ?? ''))];
    }

    /** 从完整 URL 中提取 BV 号 / videoId（SPEC §10.3） */
    private static function extractVideoKey(string $sourceType, ?string $provider, string $key, array $in): string
    {
        if ($sourceType !== 'embed') {
            return $key;
        }

        $provider = $provider ?? (string) ($in['provider'] ?? '');

        if ($provider === 'bilibili' || preg_match('#bilibili\.com|b23\.tv#i', $key)) {
            if (preg_match('#(BV[0-9A-Za-z]{10})#', $key, $m)) {
                return $m[1];
            }
        }
        if ($provider === 'youtube' || preg_match('#youtu\.be|youtube\.com#i', $key)) {
            if (preg_match('#(?:v=|youtu\.be/|embed/)([A-Za-z0-9_-]{6,20})#', $key, $m)) {
                return $m[1];
            }
        }

        return $key;
    }

    /**
     * 把前端传来的标签归一成 id 列表。
     *
     * 【为什么接受名称】编辑器里用户直接打标签名是最自然的交互 ——
     * 让他先去标签页建好再回来选，是把系统的内部结构暴露给了用户。
     * 按名称传入时用 findOrCreate，不存在就顺手建一个（SPEC §10.1）。
     *
     * @param array $tags 元素为整数（id）或字符串（名称）
     * @return int[]
     */
    private static function resolveTags(array $tags): array
    {
        $ids = [];

        foreach ($tags as $tag) {
            if (is_int($tag) || (is_string($tag) && ctype_digit($tag))) {
                $id = (int) $tag;
                if ($id > 0) {
                    $ids[] = $id;
                }
                continue;
            }

            if (is_array($tag)) {
                // 兼容 {id, name} 形式
                if (isset($tag['id']) && (int) $tag['id'] > 0) {
                    $ids[] = (int) $tag['id'];
                } elseif (!empty($tag['name'])) {
                    $ids[] = Tag::findOrCreate((string) $tag['name']);
                }
                continue;
            }

            $name = trim((string) $tag);
            if ($name !== '' && mb_strlen($name) <= 64) {
                $ids[] = Tag::findOrCreate($name);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** content_tags 全量同步 */
    private static function syncTags(int $contentId, array $tagIds): void
    {
        Db::q('DELETE FROM content_tags WHERE content_id = ?', [$contentId]);
        if ($tagIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $valid = Db::all("SELECT id FROM tags WHERE id IN ($placeholders)", $tagIds);
        foreach ($valid as $t) {
            Db::q('INSERT IGNORE INTO content_tags (content_id, tag_id) VALUES (?, ?)',
                [$contentId, (int) $t['id']]);
        }
    }

    /** 用 REPLACE 写扩展表（主键为 content_id，天然幂等） */
    private static function replaceRow(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = 'REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
             . implode(',', array_fill(0, count($cols), '?')) . ')';
        Db::q($sql, array_values($row));
    }

    private static function perPage(array $f, int $default = 0): int
    {
        $perPage = (int) ($f['per_page'] ?? 0);
        if ($perPage <= 0) {
            $perPage = $default > 0 ? $default : Settings::int('page_size', 10);
        }
        return max(1, min(100, $perPage));
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private static function nullable($v, int $max): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** 宽松解析多种时间格式，统一输出 Y-m-d H:i:s */
    private static function normalizeDateTime(string $s): ?string
    {
        $s = str_replace('T', ' ', trim($s));
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
