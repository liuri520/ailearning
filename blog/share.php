<?php
/**
 * share.php —— 分享卡片落地页（SPEC D17 / §10.3）
 *
 * 【为什么必须有这个文件】
 * 站点是 SPA + Hash 路由（#/post/xxx）。井号之后的内容**永远不会发给服务器**，
 * 因此微信、QQ、微博、Twitter 的爬虫抓到的永远是首页 —— 分享出去只有一张空白卡片。
 * 这个入口把 slug 放进查询串，在**服务端**渲染出完整的 OG / Twitter Card 标签。
 *
 * 【爬虫友好是硬要求】
 * 微信的爬虫不执行 JavaScript。所有 meta 必须在首屏 HTML 里，
 * 不能靠前端补。验证方式（SPEC §10.3 给了命令）：
 *     curl -s -A "MicroMessenger" "…/share.php?slug=xxx" | grep og:
 * 输出里必须直接有 og:title / og:image。
 *
 * 【为什么不重定向到 SPA】
 * 真人在微信里点开时也应该能正常阅读，所以对非爬虫**不重定向**，
 * 而是渲染一个轻量的静态版正文 + 「打开完整站点」链接。
 * 这样即使分享页被搜索引擎收录，内容也是可读的（SEO 的最低补偿）。
 */
define('APP_BOOT', true);
require __DIR__ . '/app/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));

$siteName = (string) Settings::get('site_name', '') ?: '博客';
$siteDesc = (string) Settings::get('site_desc', '');

$row = null;
if ($slug !== '') {
    try {
        $row = Content::detailBySlug($slug);
    } catch (Throwable $e) {
        ErrorHandler::log($e);
    }
}

// ── 组装卡片数据 ──────────────────────────────────────────
if ($row !== null) {
    $title = (string) $row['title'];
    $desc  = (string) ($row['summary'] ?? '');
    if ($desc === '' && !empty($row['body_md'])) {
        // detailBySlug() 不下发 body_text（那是后台字段），故从 Markdown 现剥一次
        $desc = Str::excerpt(Markdown::toText((string) $row['body_md']), 100);
    }
    if ($desc === '') {
        $desc = $siteDesc;
    }

    // 图片优先级：图集主图 → 视频封面 → 内容封面
    $ogImage = null;
    foreach ([$row['image_url'] ?? null, $row['poster_url'] ?? null, $row['cover_path'] ?? null] as $candidate) {
        if (!empty($candidate)) {
            $ogImage = str_starts_with((string) $candidate, 'http')
                ? (string) $candidate
                : Str::absoluteUrl((string) $candidate);
            break;
        }
    }

    $shareUrl = Str::absoluteUrl('share.php?slug=' . rawurlencode((string) $row['slug']));
    $pageUrl  = Str::absoluteUrl('#' . '/post/' . $row['slug']);
    $type     = $row['type'] === 'video' ? 'video.other' : 'article';
    $httpCode = 200;
} else {
    // 找不到也**必须**回 200 + 一张站点卡片：404 会让微信直接放弃抓取，
    // 而分享卡片本来就是个尽力而为的降级展示，不值得为此丢整张卡片。
    $title    = $siteName;
    $desc     = $siteDesc;
    $ogImage  = null;
    $shareUrl = Str::absoluteUrl('share.php');
    $pageUrl  = Str::absoluteUrl();
    $type     = 'website';
    $httpCode = 200;
}

if (!headers_sent()) {
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=utf-8');
    // 分享页内容随内容更新而变，但爬虫缓存久一点无妨；真人不依赖缓存
    header('Cache-Control: public, max-age=600');
}

$e = static fn(?string $s): string => Str::e($s);

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($title) ?> - <?= $e($siteName) ?></title>

<meta name="description" content="<?= $e($desc) ?>">
<link rel="canonical" href="<?= $e($pageUrl) ?>">

<!-- Open Graph：微信 / QQ / 微博 / Facebook 读取这一组 -->
<meta property="og:type" content="<?= $e($type) ?>">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($desc) ?>">
<meta property="og:url" content="<?= $e($shareUrl) ?>">
<meta property="og:site_name" content="<?= $e($siteName) ?>">
<?php if ($ogImage !== null): ?>
<meta property="og:image" content="<?= $e($ogImage) ?>">
<meta property="og:image:alt" content="<?= $e($title) ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card" content="<?= $ogImage !== null ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $e($title) ?>">
<meta name="twitter:description" content="<?= $e($desc) ?>">
<?php if ($ogImage !== null): ?>
<meta name="twitter:image" content="<?= $e($ogImage) ?>">
<?php endif; ?>

<style>
  :root { color-scheme: light dark; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
         line-height:1.7; color:#24292f; background:#fff; }
  main { max-width:42rem; margin:0 auto; padding:2rem 1.25rem 4rem; }
  h1 { font-size:1.6rem; line-height:1.35; margin:0 0 .75rem; }
  .meta { color:#6b7280; font-size:.85rem; margin-bottom:1.5rem; }
  img.cover { width:100%; height:auto; border-radius:.5rem; margin:1rem 0; }
  pre { background:#f6f8fa; padding:1rem; border-radius:.5rem; overflow-x:auto; font-size:.85rem; }
  code { background:#f6f8fa; padding:.15em .35em; border-radius:.25rem; font-size:.9em; }
  pre code { background:none; padding:0; }
  a.cta { display:inline-block; margin-top:2rem; padding:.6rem 1.2rem; background:#24292f;
          color:#fff; border-radius:.375rem; text-decoration:none; }
  @media (prefers-color-scheme: dark) {
    body { color:#e6edf3; background:#0d1117; }
    pre, code { background:#161b22; }
    a.cta { background:#e6edf3; color:#0d1117; }
  }
</style>
</head>
<body>
<main>
<?php if ($row !== null): ?>

  <h1><?= $e($title) ?></h1>
  <p class="meta">
    <?= $e((string) ($row['published_at'] ?? '')) ?>
    <?php if (!empty($row['tags'])): ?>
      ·<?php foreach ($row['tags'] as $tag): ?> <?= $e((string) $tag['name']) ?><?php endforeach; ?>
    <?php endif; ?>
  </p>

  <?php if ($ogImage !== null): ?>
    <img class="cover" src="<?= $e($ogImage) ?>" alt="<?= $e((string) ($row['alt_text'] ?? $title)) ?>">
  <?php endif; ?>

  <?php
  // 分享页给的是**服务端渲染的静态正文**，因此这里用 Markdown::toHtml()：
  // 它做了转义与 javascript: 拦截，不引第三方库，也不必依赖 DOMPurify。
  $bodyHtml = '';
  if (($row['type'] ?? '') === 'article' && !empty($row['body_md'])) {
      $bodyHtml = Markdown::toHtml((string) $row['body_md']);
  } elseif (($row['type'] ?? '') === 'video' && !empty($row['source_key'])) {
      $label = ($row['source_type'] ?? '') === 'embed' ? '视频链接' : '视频地址';
      $bodyHtml = '<p>' . $e($label) . '：<a href="' . $e((string) $row['source_key']) . '" rel="noopener">'
                . $e((string) $row['source_key']) . '</a></p>';
  }
  ?>
  <div class="body"><?= $bodyHtml ?></div>

  <a class="cta" href="<?= $e($pageUrl) ?>">打开完整站点 →</a>

<?php else: ?>

  <h1><?= $e($siteName) ?></h1>
  <p class="meta"><?= $e($desc) ?></p>
  <a class="cta" href="<?= $e(Str::absoluteUrl()) ?>">进入首页 →</a>

<?php endif; ?>
</main>
</body>
</html>
