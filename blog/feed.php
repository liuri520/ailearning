<?php
/**
 * feed.php —— RSS 2.0 / JSON Feed 1.1（SPEC §8.5）
 *
 * SPA 牺牲了 SEO（SPEC D4 已接受），订阅源是**唯一**的结构化出口，
 * 因此这里的正确性要靠自己保证：XML 必须合法、时间必须 RFC 822、链接必须绝对。
 *
 * 用法：
 *   feed.php              → RSS 2.0
 *   feed.php?format=json  → JSON Feed 1.1
 *   feed.php?type=article → 只输出文章（可省略＝全部）
 */
define('APP_BOOT', true);
require __DIR__ . '/app/bootstrap.php';

$format = strtolower((string) ($_GET['format'] ?? 'rss'));
$type   = trim((string) ($_GET['type'] ?? ''));

$siteName = (string) Settings::get('site_name', '') ?: '博客';
$siteDesc = (string) Settings::get('site_desc', '');
$limit    = max(1, min(50, (int) ($_GET['limit'] ?? 20)));

$rows = [];
try {
    $result = Content::list([
        'type'     => $type,
        'page'     => 1,
        'per_page' => $limit,
    ]);
    $rows = $result['rows'];

    // Content::list() 走的是轻量路径，不带 body_md（列表页用不上）。
    // 订阅源要发全文，故按需补一次 —— 一条 IN 查询，不是 N 条。
    $articleIds = [];
    foreach ($rows as $row) {
        if (($row['type'] ?? '') === 'article') {
            $articleIds[] = (int) $row['id'];
        }
    }

    if ($articleIds !== []) {
        $bodies = [];
        $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
        foreach (Db::all(
            "SELECT content_id, body_md FROM article_meta WHERE content_id IN ($placeholders)",
            $articleIds
        ) as $meta) {
            $bodies[(int) $meta['content_id']] = (string) $meta['body_md'];
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['body_md'] = $bodies[(int) $row['id']] ?? '';
        }
    }
} catch (Throwable $e) {
    ErrorHandler::log($e);
}

$selfUrl = Str::absoluteUrl('feed.php' . ($format === 'json' ? '?format=json' : ''));

// ════════════════════════════════════════════════════════════
//  JSON Feed 1.1
// ════════════════════════════════════════════════════════════
if ($format === 'json') {
    if (!headers_sent()) {
        header('Content-Type: application/feed+json; charset=utf-8');
        header('Cache-Control: public, max-age=600');
    }

    $items = [];
    foreach ($rows as $row) {
        $url = Str::absoluteUrl('#' . '/post/' . $row['slug']);

        $item = [
            'id'             => $url,
            'url'            => $url,
            // 分享页是服务端渲染的，作为 external_url 比 hash 路由更适合外部消费
            'external_url'   => Str::absoluteUrl('share.php?slug=' . rawurlencode((string) $row['slug'])),
            'title'          => (string) $row['title'],
            'content_text'   => (string) ($row['summary'] ?? ''),
            'date_published' => feed_iso8601($row['published_at'] ?? null),
        ];

        if (!empty($row['cover_path'])) {
            $item['image'] = str_starts_with((string) $row['cover_path'], 'http')
                ? (string) $row['cover_path']
                : Str::absoluteUrl((string) $row['cover_path']);
        }

        if (!empty($row['tags'])) {
            $item['tags'] = array_map(static fn(array $t): string => (string) $t['name'], $row['tags']);
        }

        $items[] = $item;
    }

    echo json_encode([
        'version'       => 'https://jsonfeed.org/version/1.1',
        'title'         => $siteName,
        'home_page_url' => Str::absoluteUrl(),
        'feed_url'      => $selfUrl,
        'description'   => $siteDesc,
        'language'      => 'zh-CN',
        'items'         => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
}

// ════════════════════════════════════════════════════════════
//  RSS 2.0
// ════════════════════════════════════════════════════════════
if (!headers_sent()) {
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=600');
}

// 最后构建时间取最新一篇的发布时间，而非 now()：
// 用 now() 会让聚合器每次抓取都认为有更新
$lastBuild = null;
foreach ($rows as $row) {
    if (!empty($row['published_at'])) {
        $lastBuild = $row['published_at'];
        break;
    }
}

// 【必须手写 XML】simplexml / DOMDocument 会引入扩展依赖且需处理命名空间，
// RSS 2.0 结构简单，直接拼字符串 + 严格转义更可控。
$e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title><?= $e($siteName) ?></title>
    <link><?= $e(Str::absoluteUrl()) ?></link>
    <description><?= $e($siteDesc) ?></description>
    <language>zh-CN</language>
    <generator>blog</generator>
    <atom:link href="<?= $e($selfUrl) ?>" rel="self" type="application/rss+xml" />
<?php if ($lastBuild !== null): ?>
    <lastBuildDate><?= $e(feed_rfc822($lastBuild)) ?></lastBuildDate>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
<?php
    $link = Str::absoluteUrl('#' . '/post/' . $row['slug']);
    $shareLink = Str::absoluteUrl('share.php?slug=' . rawurlencode((string) $row['slug']));

    // description 用纯文本摘要；正文走 content:encoded（订阅器里能直接读全文）
    $summary = (string) ($row['summary'] ?? '');
    if ($summary === '' && !empty($row['body_md'])) {
        $summary = Str::excerpt(Markdown::toText((string) $row['body_md']), 200);
    }

    $bodyHtml = '';
    if (($row['type'] ?? '') === 'article' && !empty($row['body_md'])) {
        $bodyHtml = Markdown::toHtml((string) $row['body_md']);
    }
?>
    <item>
      <title><?= $e((string) $row['title']) ?></title>
      <link><?= $e($link) ?></link>
      <guid isPermaLink="false"><?= $e($shareLink) ?></guid>
      <description><?= $e($summary) ?></description>
<?php if ($row['published_at'] !== null): ?>
      <pubDate><?= $e(feed_rfc822((string) $row['published_at'])) ?></pubDate>
<?php endif; ?>
<?php if (!empty($row['tags'])): ?>
<?php foreach ($row['tags'] as $tag): ?>
      <category><?= $e((string) $tag['name']) ?></category>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($bodyHtml !== ''): ?>
      <content:encoded><![CDATA[<?= feed_cdata($bodyHtml) ?>]]></content:encoded>
<?php endif; ?>
    </item>
<?php endforeach; ?>
  </channel>
</rss>

<?php
// ════════════════════════════════════════════════════════════
//  辅助
// ════════════════════════════════════════════════════════════

/** MySQL DATETIME → RFC 822（RSS 2.0 要求的格式） */
function feed_rfc822(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    $ts = strtotime($datetime);
    return $ts === false ? '' : date(DATE_RFC822, $ts);
}

/** MySQL DATETIME → RFC 3339（JSON Feed 要求的格式） */
function feed_iso8601(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    $ts = strtotime($datetime);
    return $ts === false ? '' : date('c', $ts);
}

/**
 * CDATA 安全化。
 * 【关键】正文里若含 `]]>`（例如讲 Markdown 的文章里写了 CDATA 示例），
 * 直接塞进 CDATA 段会**当场截断 XML**，整个 feed 变砖。必须拆开。
 */
function feed_cdata(string $html): string
{
    return str_replace(']]>', ']]]]><![CDATA[>', $html);
}
