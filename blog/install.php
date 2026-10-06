<?php
/**
 * install.php —— 一次性安装向导（SPEC §11.3）
 *
 * 步骤：
 *   1. 环境探测 → 确认 §2.1 的实测值
 *   2. 填数据库信息 → 写入 config.php
 *   3. 建表 → 执行 app/schema.sql.php
 *   4. 站点信息 + 管理员密码 + 图床配置（图床可跳过）
 *   5. 生成后台入口随机名 → 重命名 panel-template.php → 提示删除 install.php
 *
 * 【重要】本文件不加载 app/bootstrap.php（那时 config.php 可能还不存在）。
 * 安装完成后**必须从线上删除**（SPEC §13.1 验收项）。
 */

define('APP_BOOT', true);

$root = __DIR__;
$configFile = $root . '/config.php';
// 【扩展名是 .php，不是 .sql —— 见 DEBUG.md D-08】
// 线上没有重写能力（SPEC §2.1），.htaccess 这条路走不通，而静态文件
// 不受任何守卫保护 —— 命名成 app/schema.sql 会让完整 DDL 可被公开下载。
// 包成 .php + APP_BOOT 守卫后，直接访问得到 404，本向导 require 回来则是正常字符串。
$schemaFile = $root . '/app/schema.sql.php';

// ── 读取已有配置 ────────────────────────────────────────────
$installed = false;
$installComplete = false;
$db = null;
$dbError = null;

if (is_file($configFile)) {
    // require_once 而非 require：本文件下面还会再引一次 config.php，
    // 用 require 会把每个 define() 执行两遍，PHP 8 会为每个常量抛一条
    // 「Constant already defined」警告。只要 display_errors 是开的，
    // 这些警告就成了「响应体已开始输出」，后面所有 header('Location:')
    // 全部失效 —— 向导点按钮没反应，就是这么来的。
    require_once $configFile;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $installed = (bool) $pdo->query("SHOW TABLES LIKE 'settings'")->fetch();

        /*
         * 「装完了」的判据不是「settings 表存在」。
         *
         * 建表在步骤 2（③），设管理员密码在步骤 3（④）—— 中间隔着一次页面跳转。
         * 只看表在不在的话，刚建完表、跳到下一步的瞬间就会被 renderInstalled()
         * 拦下来，向导永远走不到设密码那一步，而用户看到的是一个写着
         * 「站点已安装」的页面，根本不知道该怎么继续。
         *
         * 真正的完成标志是密码已经写进 settings —— schema.sql.php 里这个键的初始值是
         * 空串，所以「表在 + 密码非空」恰好把「装到一半」和「装完了」分开。
         *
         * 读失败时保持 $installed 的取值（即按已安装处理）：settings 表在却查不出
         * 这个键，说明库已经不对了，此时放行向导比锁上更危险。
         */
        $installComplete = $installed;
        if ($installed) {
            $hash = (string) $pdo->query(
                "SELECT `value` FROM `settings` WHERE `key` = 'admin_pass_hash'"
            )->fetchColumn();
            $installComplete = $hash !== '';
        }
    } catch (Throwable $e) {
        $dbError = '已存在 config.php，但数据库连接失败：' . $e->getMessage();
    }
}

/** 已安装 → 自我禁用（SPEC §11.3-4） */
function renderInstalled(string $configFile): void
{
    renderPage('已安装', <<<HTML
        <h1>站点已安装</h1>
        <p class="warn">数据库里已有管理员密码，说明安装已完成，向导不再重复执行。</p>
        <p><strong>请立即从线上删除 <code>install.php</code></strong>，否则会留下安全隐患。</p>
        <p>如需重装，请先删除 <code>config.php</code> 并清空数据库。</p>
        <p>后台入口名保存在 <code>config.php</code> 的 <code>ADMIN_ENTRY</code> 常量中。</p>
        <p class="hint">如果你其实还没走完向导（例如建表时报了错），说明库里已经写进过密码 ——
           先在 <code>settings</code> 表里把 <code>admin_pass_hash</code> 清成空串，向导就会重新放行。</p>
        HTML);
    exit;
}

/**
 * 安装完成页。
 *
 * 【为什么是个函数而不是 ?step=4 的分支】它要显示后台入口的随机名 ——
 * 那是个凭据。做成可反复访问的地址，等于把 SPEC §9.1 的随机入口贴在墙上。
 * 见步骤 3 POST 成功处的注释。
 */
function renderDone(): void
{
    $entry = defined('ADMIN_ENTRY') ? (string) ADMIN_ENTRY : '';
    $panelFile = $entry !== '' ? 'panel-' . $entry . '.php' : '（未生成，请检查 panel-template.php）';
    $panelLink = $entry !== '' && is_file(__DIR__ . '/' . $panelFile)
        ? '<a href="' . h($panelFile) . '">' . h($panelFile) . '</a>'
        : '<strong>' . h($panelFile) . '</strong>';

    renderPage('安装完成', <<<HTML
      <h1>⑤ 安装完成</h1>
      <p class="warn"><strong>请立即删除 <code>install.php</code></strong> —— 这是上线验收清单的强制项。</p>
      <h2>接下来</h2>
      <ul>
        <li>后台入口：{$panelLink}（请自行妥善保管这个地址）</li>
        <li>前台首页：<a href="./">打开站点</a></li>
        <li>确认 <code>config.php</code> 中的 <code>APP_DEBUG</code> 上线前改为 <code>false</code></li>
        <li>访问 <code>panel.php</code> / <code>admin.php</code> 应返回 404</li>
      </ul>
      <h2>验收自测（SPEC §13.1 节选）</h2>
      <ul>
        <li>站点目录改名后全站正常（仅改 APP_BASE）</li>
        <li>上传一张图，确认 Network 面板加载的是缩略版</li>
        <li>数据库中不存在 <code>storage_model != 99</code> 的资产</li>
      </ul>
      <p class="hint">刷新本页会看到「站点已安装」—— 属正常现象：密码已经写进库，
        向导从此不再放行。这也正是提醒你把 <code>install.php</code> 删掉的原因。</p>
      HTML);
}

// ── 工具函数 ────────────────────────────────────────────────

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $body): void
{
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · 安装向导</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;
         max-width: 760px; margin: 40px auto; padding: 0 20px; line-height: 1.7; }
  h1 { font-size: 1.5rem; }
  h2 { font-size: 1.1rem; margin-top: 2rem; }
  table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
  th, td { border: 1px solid #8884; padding: 6px 10px; text-align: left; font-size: .9rem; }
  th { background: #8881; }
  code { background: #8881; padding: 1px 5px; border-radius: 3px; }
  .ok { color: #1a7f37; font-weight: 600; }
  .bad { color: #c0392b; font-weight: 600; }
  .warn { background: #fdf0d5; border-left: 4px solid #e6a700; padding: 10px 14px; color: #7a4f01; }
  .field { margin: 10px 0; }
  label { display: block; font-size: .88rem; margin-bottom: 3px; }
  input[type=text], input[type=password], input[type=number] {
      width: 100%; padding: 7px 9px; border: 1px solid #8886; border-radius: 4px; font-size: .95rem; }
  button { background: #2563eb; color: #fff; border: 0; padding: 10px 22px;
           border-radius: 5px; font-size: 1rem; cursor: pointer; margin-top: 12px; }
  button:hover { background: #1d4ed8; }
  .hint { font-size: .8rem; color: #888; margin-top: 2px; }
  ul { padding-left: 1.2rem; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
}

/** 逐条执行 schema.sql.php（PDO 的 multi-statement 在各版本行为不一致，故手工切分） */
function runSchema(PDO $pdo, string $sql): int
{
    /*
     * 【按字节切行，绝不能用 \R —— 这是踩过的坑，别改回去】
     *
     * PCRE 在非 UTF-8 模式下把 \R 定义成「CR / LF / VT / FF / NEL(0x85) / LS / PS」，
     * 其中 0x85 是**单个字节**。而 UTF-8 的汉字是 3 字节，中间字节落在 0x80–0xBF，
     * 其中 0x85 极其常见：关(E5 85 B3) 全(E5 85 A8) 八(E5 85 AB) 六(E5 85 AD)
     * 公(E5 85 AC) 共(E5 85 B1) 元(E5 85 83) 照(E7 85 A7) 先(E5 85 88)…
     *
     * 结果：preg_split('/\R/') 会**从汉字正中间劈开**。劈出来的后半截不以 -- 开头，
     * 于是躲过下面的注释过滤，被当作 SQL 发出去，MySQL 回一句 1064，
     * 而报错里的片段看着像乱码，根本看不出是行切分出了问题。
     * 实测现象：第 1 条语句就是一堆注释的后半截拼上 SET NAMES utf8mb4。
     *
     * 行分隔符老老实实写出来，不依赖 PCRE 的换行定义。
     */
    $lines = preg_split('/\r\n|\n|\r/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $clean[] = $line;
    }
    $body = implode("\n", $clean);

    $count = 0;
    foreach (explode(';', $body) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }

        /*
         * 兜底自检：正常的语句一定以字母开头（CREATE / INSERT / SET / ALTER…）。
         * 不以字母开头，说明切分或注释过滤出了岔子。
         * 与其把垃圾喂给 MySQL 换回一句「check the manual」，不如在这里
         * 直接把混进来的东西摆在眼前 —— 上面那个 \R 的坑，当初就是被
         * MySQL 的 1064 带偏了很久才找到的。
         */
        if (!preg_match('/^[A-Za-z]/', $stmt)) {
            $junk = mb_substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 60);
            throw new RuntimeException(
                '第 ' . ($count + 1) . ' 条语句不像 SQL，可能是注释没剔干净：' . $junk
            );
        }

        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            /*
             * 报出是第几条语句失败的，并附上语句开头。
             *
             * schema.sql.php 有十几条语句，MySQL 自己的报错又常常只有一句
             * 「Specified key was too long」，光靠它无法判断是哪张表。
             * 前面成功的语句不会回滚（`CREATE TABLE IF NOT EXISTS` 保证了
             * 重跑安全），所以这条错误信息要能直接定位到出问题的那一条。
             */
            $head = mb_substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 80);
            throw new RuntimeException(
                '第 ' . ($count + 1) . ' 条语句执行失败：' . $head . "\n\n" . $e->getMessage(),
                0,
                $e
            );
        }
        $count++;
    }
    return $count;
}

function writeConfig(string $path, array $c): bool
{
    $tpl = <<<'PHP'
<?php
/**
 * config.php —— 环境配置
 *
 * 由 install.php 生成。本文件是唯一含密钥的文件，部署时**永不覆盖**（SPEC §4.1）。
 * 首行守卫：直接访问立即 404（SPEC §9.4-2）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

// 数据库
define('DB_HOST', %DB_HOST%);
define('DB_PORT', %DB_PORT%);
define('DB_NAME', %DB_NAME%);
define('DB_USER', %DB_USER%);
define('DB_PASS', %DB_PASS%);

// 站点
define('APP_BASE', %APP_BASE%);       // 子目录前缀，部署到域名根时为空字符串
define('APP_DEBUG', %APP_DEBUG%);     // 生产环境必须为 false

// 密钥（不入 settings 表）
define('ADMIN_ENTRY', %ADMIN_ENTRY%);       // 后台入口随机名
define('TTTTT_API_KEY', %TTTTT_API_KEY%);   // 钛盘 API Key
define('IP_SALT', %IP_SALT%);               // IP 哈希盐
PHP;

    $replace = [
        '%DB_HOST%'       => var_export($c['DB_HOST'], true),
        '%DB_PORT%'       => (string) (int) $c['DB_PORT'],
        '%DB_NAME%'       => var_export($c['DB_NAME'], true),
        '%DB_USER%'       => var_export($c['DB_USER'], true),
        '%DB_PASS%'       => var_export($c['DB_PASS'], true),
        '%APP_BASE%'      => var_export($c['APP_BASE'], true),
        '%APP_DEBUG%'     => $c['APP_DEBUG'] ? 'true' : 'false',
        '%ADMIN_ENTRY%'   => var_export($c['ADMIN_ENTRY'], true),
        '%TTTTT_API_KEY%' => var_export($c['TTTTT_API_KEY'], true),
        '%IP_SALT%'       => var_export($c['IP_SALT'], true),
    ];

    $content = str_replace(array_keys($replace), array_values($replace), $tpl);
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

// ── 自动探测 APP_BASE（SPEC §4.2 / §11.3-6）──────────────────
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
$detectedBase = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/');

// ── 环境探测（SPEC §2.1）────────────────────────────────────
function probeEnvironment(): array
{
    return [
        ['PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')
            ? '需 8.1+，可用 match / 构造器属性提升 / readonly / 枚举' : '低于 8.1，本规范要求 8.1'],
        ['upload_max_filesize', ini_get('upload_max_filesize') ?: '未知', '文档实测值 100M'],
        ['max_execution_time', (ini_get('max_execution_time') ?: '未知') . ' 秒', '文档实测值 30s'],
        ['fastcgi_finish_request()', function_exists('fastcgi_finish_request') ? '可用' : '不可用',
            function_exists('fastcgi_finish_request')
                ? 'PHP-FPM：伪 cron 可先响应后执行'
                : '本地 mod_php：伪 cron 走 5 秒预算分支（SPEC §11.1）'],
        ['GD / Imagick', (extension_loaded('gd') || extension_loaded('imagick')) ? '存在' : '不存在',
            '本规范不依赖，全部交给图床与浏览器'],
        ['开放目录列举（index.html 防护）', is_file(__DIR__ . '/data/index.html') ? '已放置' : '缺失',
            'data/ 与 app/ 下应各有一个空 index.html'],
    ];
}

// ── 路由 ────────────────────────────────────────────────────
$step = (int) ($_GET['step'] ?? 1);

if ($installComplete) {
    renderInstalled($configFile);
}

$error = '';

// ── 步骤 1：环境探测 + 数据库信息 ────────────────────────────
if ($step === 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $host = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
        $port = (int) ($_POST['db_port'] ?? 3306);
        $name = trim((string) ($_POST['db_name'] ?? ''));
        $user = trim((string) ($_POST['db_user'] ?? ''));
        $pass = (string) ($_POST['db_pass'] ?? '');
        $base = trim((string) ($_POST['app_base'] ?? $detectedBase));
        $debug = isset($_POST['app_debug']);

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            if (!writeConfig($configFile, [
                'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $name,
                'DB_USER' => $user, 'DB_PASS' => $pass,
                'APP_BASE' => $base, 'APP_DEBUG' => $debug,
                'ADMIN_ENTRY' => '', 'TTTTT_API_KEY' => '', 'IP_SALT' => bin2hex(random_bytes(16)),
            ])) {
                throw new RuntimeException('config.php 写入失败，请检查站点根目录是否可写');
            }

            header('Location: install.php?step=2');
            exit;
        } catch (Throwable $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    }

    $rows = '';
    foreach (probeEnvironment() as [$name, $value, $note]) {
        $rows .= '<tr><td>' . h($name) . '</td><td><strong>' . h((string) $value)
              . '</strong></td><td class="hint">' . h($note) . '</td></tr>';
    }

    $errHtml = $error !== '' ? '<p class="warn">' . h($error) . '</p>' : '';

    renderPage('环境探测', <<<HTML
      <h1>① 环境探测</h1>
      <p>请核对下表与 SPEC.md §2.1 的实测值是否一致。不一致时代码仍可运行，但请留意差异。</p>
      <table><tr><th>项目</th><th>当前值</th><th>说明</th></tr>{$rows}</table>

      <h2>② 数据库配置</h2>
      {$errHtml}
      <form method="post" action="install.php?step=1">
        <div class="field"><label>数据库主机</label>
          <input type="text" name="db_host" value="127.0.0.1" required></div>
        <div class="field"><label>端口</label>
          <input type="number" name="db_port" value="3306" required></div>
        <div class="field"><label>数据库名</label>
          <input type="text" name="db_name" value="blog_dev" required>
          <div class="hint">需先在主机面板 / 本地建好空库</div></div>
        <div class="field"><label>用户名</label>
          <input type="text" name="db_user" value="root" required></div>
        <div class="field"><label>密码</label>
          <input type="password" name="db_pass" value=""></div>
        <div class="field"><label>站点子目录（APP_BASE）</label>
          <input type="text" name="app_base" value="{$detectedBase}">
          <div class="hint">自动探测结果：<code>{$detectedBase}</code>。部署在域名根则留空。
            改名验收见 SPEC §4.2</div></div>
        <div class="field"><label>
          <input type="checkbox" name="app_debug" value="1" checked> 开启调试模式（APP_DEBUG）
          </label><div class="hint">本地开发勾选；<strong>上线前必须取消</strong></div></div>
        <button type="submit">保存并继续</button>
      </form>
      HTML);
    exit;
}

// ── 以下步骤都需要 config.php ────────────────────────────────
if (!is_file($configFile)) {
    header('Location: install.php?step=1');
    exit;
}
require_once $configFile;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    renderPage('连接失败', '<h1>数据库连接失败</h1><p class="warn">' . h($e->getMessage())
        . '</p><p><a href="install.php?step=1">返回修改配置</a></p>');
    exit;
}

$mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

// ── 步骤 2：建表 ────────────────────────────────────────────
if ($step === 2) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            /*
             * 读不到文件必须当场报错。
             * 不报的话 file_get_contents 返回 false、强转成空串，
             * runSchema('') 会「成功」执行 0 条语句并跳到下一步 ——
             * 于是变成了「install.php 说装好了，但一张表都没有」。
             * 这类静默的假成功比报错难查得多。
             */
            if (!is_file($schemaFile)) {
                throw new RuntimeException('找不到建表脚本：app/schema.sql.php。请确认该文件已上传。');
            }
            // 用 require 而非 file_get_contents：该文件是 .php，用取文件的方式
            // 读回来的是「<?php + 守卫 + nowdoc」的源码，喂给 runSchema 必炸。
            // 里面 return 出来的才是 SQL 字符串。
            $sql = (string) require $schemaFile;
            if (trim($sql) === '') {
                throw new RuntimeException('app/schema.sql.php 是空文件，请重新上传。');
            }

            $n = runSchema($pdo, $sql);
            header('Location: install.php?step=3');
            exit;
        } catch (Throwable $e) {
            $error = '建表失败：' . $e->getMessage();
        }
    }

    $warnHtml = '';
    if (!version_compare($mysqlVersion, '5.7', '>=')) {
        $warnHtml = '<p class="warn">MySQL 版本为 ' . h($mysqlVersion)
            . '。本规范已按 5.6 的限制编写（索引前缀 ≤191 字符、无 DESC、无 JSON 列等），'
            . '若版本低于 5.6.5，<code>DATETIME</code> 相关行为可能不同。</p>';
    }

    $dbLabel = h(DB_NAME);

    /*
     * 【这一行是必需的，不是为了好看】
     *
     * 上面 catch 把建表异常收进了 $error。但本页原本谁也没渲染 $error ——
     * 于是「某条 SQL 执行失败」的表现就是页面原样重绘一遍：用户点了「执行建表」，
     * 什么都没发生，再点，还是什么都没发生。一个被吞掉的异常比一条报错难查十倍。
     *
     * 步骤 3 有同样的 {$errHtml}，步骤 2 当初漏了。
     */
    $errHtml = $error !== '' ? '<p class="warn">' . h($error) . '</p>' : '';

    renderPage('建表', <<<HTML
      <h1>③ 建立数据表</h1>
      {$errHtml}
      <table><tr><th>MySQL 版本</th><td>{$mysqlVersion}</td></tr>
             <tr><th>数据库</th><td>{$dbLabel}</td></tr></table>
      {$warnHtml}
      <p>将执行 <code>app/schema.sql.php</code>，创建 14 张表并写入默认配置与首页区块。</p>
      <p class="hint">脚本使用 <code>CREATE TABLE IF NOT EXISTS</code> 与 <code>INSERT IGNORE</code>，
        重复执行是安全的。</p>
      <form method="post" action="install.php?step=2"><button type="submit">执行建表</button></form>
      HTML);
    exit;
}

// ── 步骤 3：站点信息 + 管理员密码 + 图床 ─────────────────────
if ($step === 3) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $siteDesc = trim((string) ($_POST['site_desc'] ?? ''));
        $siteIcp  = trim((string) ($_POST['site_icp'] ?? ''));
        $user     = trim((string) ($_POST['admin_user'] ?? 'admin'));
        $pass     = (string) ($_POST['admin_pass'] ?? '');
        $key      = trim((string) ($_POST['ttttt_api_key'] ?? ''));
        $cdnBase  = trim((string) ($_POST['cdn_base'] ?? ''));
        $template = trim((string) ($_POST['ttttt_direct_template'] ?? ''));

        if ($pass === '' || mb_strlen($pass) < 8) {
            $error = '管理员密码至少 8 位';
        } else {
            $now = date('Y-m-d H:i:s');
            $entry = '';
            // 生成 8 位后台入口名（SPEC §9.1）
            for ($i = 0; $i < 8; $i++) {
                $entry .= 'abcdefghijklmnopqrstuvwxyz0123456789'[random_int(0, 35)];
            }

            $stmt = $pdo->prepare(
                'REPLACE INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)'
            );
            $kv = [
                'site_name'             => $siteName !== '' ? $siteName : '我的博客',
                'site_desc'             => $siteDesc,
                'site_icp'              => $siteIcp,
                'admin_user'            => $user !== '' ? $user : 'admin',
                'admin_pass_hash'       => password_hash($pass, PASSWORD_DEFAULT),
                'cdn_base'              => $cdnBase,
                'ttttt_direct_template' => $template !== ''
                    ? $template
                    : 'https://download.wuyuge.cn/files/{dkey}/{name}',
                'view_dedup_salt'       => bin2hex(random_bytes(16)),
            ];
            foreach ($kv as $k => $v) {
                $stmt->execute([$k, (string) $v, $now]);
            }

            // 把 ADMIN_ENTRY / TTTTT_API_KEY 回写进 config.php
            $merged = [
                'DB_HOST' => DB_HOST, 'DB_PORT' => DB_PORT, 'DB_NAME' => DB_NAME,
                'DB_USER' => DB_USER, 'DB_PASS' => DB_PASS,
                'APP_BASE' => APP_BASE, 'APP_DEBUG' => APP_DEBUG,
                'ADMIN_ENTRY' => $entry,
                'TTTTT_API_KEY' => $key,
                'IP_SALT' => defined('IP_SALT') ? IP_SALT : bin2hex(random_bytes(16)),
            ];
            writeConfig($configFile, $merged);

            // 生成后台入口文件（SPEC §9.1：访问 panel.php / admin.php 一律 404）
            $from = __DIR__ . '/panel-template.php';
            $to   = __DIR__ . '/panel-' . $entry . '.php';
            if (is_file($from) && !is_file($to)) {
                @rename($from, $to);
            }

            /*
             * 直接渲染完成页，**不**重定向到 install.php?step=4。
             *
             * 完成页要显示后台入口的随机名（panel-xxxxxxxx.php），那是个凭据：
             * 一旦这个地址可以事后重新打开，任何人访问 install.php?step=4
             * 就能读到它，SPEC §9.1 那层随机入口防护也就白做了。
             * 所以它只在「刚设完密码」这一次响应里出现。
             */
            renderDone();
            exit;
        }
    }

    $errHtml = $error !== '' ? '<p class="warn">' . h($error) . '</p>' : '';
    $entryWarn = is_file(__DIR__ . '/panel-template.php')
        ? ''
        : '<p class="warn"><code>panel-template.php</code> 不存在，后台入口将无法生成。请确认文件已上传。</p>';

    renderPage('站点设置', <<<HTML
      <h1>④ 站点与图床</h1>
      {$errHtml}{$entryWarn}
      <form method="post" action="install.php?step=3">
        <h2>站点信息</h2>
        <div class="field"><label>站点名</label>
          <input type="text" name="site_name" value="我的博客" required></div>
        <div class="field"><label>站点描述（用于 OG 与 RSS）</label>
          <input type="text" name="site_desc" value=""></div>
        <div class="field"><label>备案号（留空则不展示）</label>
          <input type="text" name="site_icp" value=""></div>

        <h2>管理员</h2>
        <div class="field"><label>用户名</label>
          <input type="text" name="admin_user" value="admin" required></div>
        <div class="field"><label>密码（至少 8 位）</label>
          <input type="password" name="admin_pass" required minlength="8">
          <div class="hint">忘记密码只能直接改数据库，本系统不提供找回（SPEC §9.1）</div></div>

        <h2>图床（钛盘，可跳过）</h2>
        <p class="hint">跳过则上传功能不可用，可稍后在后台设置页补填。</p>
        <div class="field"><label>钛盘 API Key</label>
          <input type="text" name="ttttt_api_key" value="">
          <div class="hint">只写入 config.php，绝不入库、不返回前端</div></div>
        <div class="field"><label>cdn_base（直链域名前缀）</label>
          <input type="text" name="cdn_base" value="https://download.wuyuge.cn">
          <div class="hint">用途已收窄为「识别 URL 是否属于本图床」</div></div>
        <div class="field"><label>直链拼接模板</label>
          <input type="text" name="ttttt_direct_template"
                 value="https://download.wuyuge.cn/files/{dkey}/{name}"></div>
        <button type="submit">完成安装</button>
      </form>
      HTML);
    exit;
}

// ── 步骤 4：完成 ────────────────────────────────────────────
/*
 * 这一步**没有可独立打开的地址**，内容由 renderDone() 直接产出，
 * 只在「刚设完密码」的那一次 POST 响应里出现（理由见步骤 3 里的注释）。
 * 走到这里说明有人手动敲了 ?step=4 —— 没装完就送回步骤 3，装完了上面的
 * renderInstalled() 已经拦下了。
 */
if ($step === 4) {
    header('Location: install.php?step=3');
    exit;
}

header('Location: install.php?step=1');
exit;
