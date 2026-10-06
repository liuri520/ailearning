<?php
/**
 * Tag.php —— 标签体系（SPEC §5.3 / §6.3 / §10.1）
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Tag
{
    /**
     * 标签列表及每个标签的内容计数。
     * 前台只统计前台可见内容；后台统计全部（含草稿）。
     */
    public static function list(?string $type = null, bool $admin = false): array
    {
        $params = [];
        $where = '';

        if (!$admin) {
            $where = ' AND ' . Content::VISIBLE_SQL;
        }
        if ($type !== null && in_array($type, Content::TYPES, true)) {
            $where .= ' AND c.type = ?';
            $params[] = $type;
        }

        // GROUP BY 写全非聚合列（SPEC §2.2）
        return Db::all(
            'SELECT t.id, t.name, t.slug, COUNT(c.id) AS count
               FROM tags t
               LEFT JOIN content_tags ct ON ct.tag_id = t.id
               LEFT JOIN contents c ON c.id = ct.content_id' . $where . '
              GROUP BY t.id, t.name, t.slug
              HAVING COUNT(c.id) > 0
              ORDER BY count DESC, t.name ASC',
            $params
        );
    }

    /** 全部标签（含计数为 0 的），供后台管理页 */
    public static function adminAll(): array
    {
        return Db::all(
            'SELECT t.id, t.name, t.slug, t.created_at, COUNT(ct.content_id) AS count
               FROM tags t
               LEFT JOIN content_tags ct ON ct.tag_id = t.id
              GROUP BY t.id, t.name, t.slug, t.created_at
              ORDER BY t.name ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM tags WHERE id = ?', [$id]);
    }

    /** 新建或重命名。返回 tag_id。 */
    public static function save(array $in, ?int $id = null): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            Response::fail('VALIDATION_FAILED', '标签名不能为空', Response::BAD_REQUEST, 'name');
        }
        if (mb_strlen($name) > 64) {
            Response::fail('VALIDATION_FAILED', '标签名不能超过 64 个字符', Response::BAD_REQUEST, 'name');
        }

        $slug = trim((string) ($in['slug'] ?? ''));
        $slug = $slug !== '' ? Str::slug($slug, 'tag') : Str::slug($name, 'tag');
        $slug = mb_substr($slug, 0, 64);

        // 名称 / slug 唯一性检查（给出明确错误，而不是让 SQL 抛 1062）
        $dupeName = Db::val('SELECT id FROM tags WHERE name = ? AND id <> ? LIMIT 1', [$name, $id ?? 0]);
        if ($dupeName !== null) {
            Response::fail('VALIDATION_FAILED', '已存在同名标签：' . $name, Response::BAD_REQUEST, 'name');
        }
        $dupeSlug = Db::val('SELECT id FROM tags WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $id ?? 0]);
        if ($dupeSlug !== null) {
            Response::fail('VALIDATION_FAILED', '已存在相同 slug 的标签', Response::BAD_REQUEST, 'slug');
        }

        if ($id === null) {
            $id = Db::insert('tags', [
                'name'       => $name,
                'slug'       => $slug,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::update('tags', ['name' => $name, 'slug' => $slug], 'id', $id);
        }

        Settings::bumpCacheVersion();
        return $id;
    }

    public static function rename(int $id, string $name, string $slug): void
    {
        self::save(['name' => $name, 'slug' => $slug], $id);
    }

    /**
     * 合并标签：把源标签的关联全部指向保留标签，去重后删除源标签（SPEC §10.1）。
     */
    public static function merge(int $fromId, int $intoId): array
    {
        if ($fromId === $intoId) {
            Response::fail('VALIDATION_FAILED', '不能合并到自身', Response::BAD_REQUEST, 'into_id');
        }

        $from = self::find($fromId);
        $into = self::find($intoId);
        if ($from === null || $into === null) {
            Response::fail('NOT_FOUND', '标签不存在', Response::NOT_FOUND);
        }

        $moved = 0;
        Db::begin();
        try {
            // 先把「目标标签已有」的关联删掉，避免主键冲突
            Db::q(
                'DELETE ct FROM content_tags ct
                   JOIN content_tags ct2 ON ct2.content_id = ct.content_id AND ct2.tag_id = ?
                  WHERE ct.tag_id = ?',
                [$intoId, $fromId]
            );

            // 其余关联改指向保留标签
            $moved = Db::exec('UPDATE content_tags SET tag_id = ? WHERE tag_id = ?', [$intoId, $fromId]);

            Db::q('DELETE FROM tags WHERE id = ?', [$fromId]);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Settings::bumpCacheVersion();
        return ['moved' => $moved, 'into' => (int) $intoId];
    }

    /**
     * 删除标签：仅删关联，不删内容（SPEC §10.1）。
     * 调用方应先通过 count() 提示「影响 N 篇内容」。
     */
    public static function delete(int $id): array
    {
        $affected = self::countContent($id);

        Db::begin();
        try {
            Db::q('DELETE FROM content_tags WHERE tag_id = ?', [$id]);
            Db::q('DELETE FROM tags WHERE id = ?', [$id]);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Settings::bumpCacheVersion();
        return ['affected' => $affected];
    }

    /** 该标签关联的内容条数（删除前提示用） */
    public static function countContent(int $id): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM content_tags WHERE tag_id = ?', [$id]);
    }

    /** 按名称找或建（供编辑器的标签输入框使用） */
    public static function findOrCreate(string $name): int
    {
        $name = trim($name);
        $existing = Db::val('SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]);
        if ($existing !== null) {
            return (int) $existing;
        }
        return self::save(['name' => $name]);
    }
}
