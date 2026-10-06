# 调试记录（DEBUG）

本文件记录博客项目的每一次故障：**现象 → 根因 → 修复 → 验证**。
与 `SPEC.md` 同级。**修复前先登记，修复后补验证结果** —— 没登记就改代码，等于把排查过程丢了。

- 代码位置：`ailearning/blog/`
- 环境基线：PHP 8.1.25 / XAMPP（Apache 2.4.58 + MariaDB）/ 库 `blog_dev` / 站点 `http://localhost/blog/`
- 线上环境：PHP 8.1 + MySQL 5.6.51（见 SPEC §2.1/§2.2）

**编号规则**：`D-序号`，只增不改。已修复的不删，保留作为回归依据。

---

## 索引

| 编号 | 标题 | 严重度 | 状态 |
|---|---|---|---|
| D-01 | install.php 第二步建表失败时页面无反应 | 阻断 | ✅ 已修 |
| D-02 | `\R` 正则把 UTF-8 汉字从中间劈开，导致建表 SQL 报 1064 | 阻断 | ✅ 已修 |
| D-03 | `Router::add()` 形参声明为 `string`，45 个调用点全传闭包 | 阻断 | ✅ 已修 |
| D-04 | CSP 缺 `frame-src`，自定义播放器 iframe 被静默拦成白框 | 高 | ✅ 已修 |
| D-05 | 白名单域名未过滤即拼进响应头，可注入 CSP 指令 / CRLF | 高（安全） | ✅ 已修 |
| D-06 | Vite dev 代理指向 `http://localhost`，子目录部署下全部 404 | 中 | ✅ 已修 |
| D-07 | **`app/Storage/` 四个类无法自动加载，存储层全线失效** | **阻断** | ✅ 已修 |
| D-08 | `app/schema.sql` 等非 PHP 文件可被公开下载 | 高（安全） | ✅ 已修 |
| D-09 | 媒体页「同步」结果的文案与后端语义相反，误导排查方向 | 中 | ✅ 已修 |
| D-10 | **`admin.export.list` 必定报错，备份页拿不到表清单** | 高 | ✅ 已修 |
| D-11 | **`Markdown` 正则分隔符打架，含链接的文章让 share.php / feed.php 双双 500** | 高 | ✅ 已修 |
| D-12 | 链接正则不认括号，维基百科这类 URL 被截断并漏出多余的 `)` | 中 | ✅ 已修 |
| D-13 | 行内图片语法未实现，`![alt](url)` 渲染成 `!<a>` | 中 | ✅ 已修 |
| D-14 | **`Asset::addExternal` 往 assets 表写入不存在的 `alt_text` 列，手动登记外链图片必失败** | 高 | ✅ 已修 |
| D-15 | `content.restore` 把内容一律恢复为草稿，已发布的文章会静默下线 | 非缺陷（设计确认） | ✅ 已确认 |
| D-16 | `Asset::revoke` 不查 `supportsRevoke()`，对外链资产报「撤销失败，待重试」的假错误 | 中 | ✅ 已修 |
| D-17 | **永久删除内容时 `releaseForContent()` 调用时机错位，资产永远不被释放，远端文件永不撤销** | **高** | ✅ 已修 |
| D-18 | 撤销失败仍删 assets 记录，丢失 UKEY 使远端文件永久占位且无从重试 | 中高 | ✅ 已修 |
| D-19 | install.php 建表页文案称「创建 12 张表」，实际 14 张 | 低 | ✅ 已修 |

---

# D-07 · `app/Storage/` 四个类无法自动加载

**状态**：🔧 修复中
**发现时间**：2026-10-06 11:45（自测第一轮）
**严重度**：阻断 —— 存储层（上传/关联/删除/对账/巡检）全线不可用

## 现象

自测时在 `data/logs/error-2026-10.log.php` 里发现两条未被预期的致命错误：

```
[2026-10-06 11:45:51] Error Class "TttttAdapter" not found
  #0 app/Asset.php(826): Asset::adapter('ttttt', 1791258356.547)
  #1 app/Scheduler.php(299): Asset::cronCheck(...)
  #2 app/Scheduler.php(194): Scheduler::taskAssetCheck(...)
```

**注意：站点表面上完全正常。** 所有页面、公开接口都返回 200。原因是错误发生在
`register_shutdown_function` 阶段（响应已发出），且库里没有内容，读路径没走到那些分支。
这是最难发现的一类失败 —— 页面全绿，功能全废。

确定性复现（CLI）：

```
$ php -r 'define("APP_BOOT",1); require "app/bootstrap.php"; Asset::adapter("ttttt");'
✗ Error: Class "TttttAdapter" not found
   位置：app/Asset.php:137
```

## 根因

`app/bootstrap.php` 的自动加载器把类名直接映射成 `app/<类名>.php`：

```php
spl_autoload_register(static function (string $class): void {
    // 'Storage\TttttAdapter' → app/Storage/TttttAdapter.php
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = __DIR__ . '/' . $relative;
    if (is_file($path)) { require $path; }
});
```

注释已经写明它**预期类名带命名空间前缀**。但事实是：

| 事实 | 验证 |
|---|---|
| 全项目**零 `namespace` 声明** | `grep -rn "^namespace" app/` → 无输出 |
| `app/Storage/*.php` 四个类都是全局裸名 | `interface StorageAdapter` / `final class ManualAdapter` / `final class TttttAdapter` / `final class StorageException` |
| 17 处调用点也全用裸名 | `Asset.php` 16 处 + `actions/admin.php:491` |

于是 `new TttttAdapter()` → 加载器去找 `app/TttttAdapter.php` → 不存在 → `Error`。

同一进程内的对照实验：

```
TttttAdapter     class_exists=false    app/TttttAdapter.php 存在=false
Asset            class_exists=true     app/Asset.php        存在=true
```

平铺在 `app/` 下的类全部正常，**只有 `app/Storage/` 这一层拿不到**。

## 影响面

- `Asset::adapter()`（`Asset.php:131-138`）—— 存储层唯一入口
  → 上传、重新关联、外链重检、删除释放、对账、巡检 全部致命错误
- `Asset::url()` 第 ③ 层兜底（`Asset.php:63`）→ 存量图片 `direct_url` 为空时致命
- `TttttAdapter::MODEL_PERMANENT` 常量引用（`Asset.php` 6 处 + `admin.php:491`）
- 伪 cron 的 `asset_check` / `expires_watch` 两个任务必然失败

失败**已在数据库留痕**（后台任务页会一直显示异常）：

| task_key | last_result |
|---|---|
| `asset_check` | 异常：Class "TttttAdapter" not found |
| `expires_watch` | 异常：Class "TttttAdapter" not found |

## 方案取舍

| 方案 | 改动面 | 评价 |
|---|---|---|
| (a) 给四个文件加 `namespace Storage;`，17 处调用点改 `Storage\TttttAdapter` | 6 个文件、17 处调用 | 与加载器注释意图一致，但要动 `Asset.php` 全部 `catch (StorageException)` 与返回类型声明，回归面大 |
| **(b) 自动加载器补一层子目录探测** | **1 个文件、3 行** | **选用** —— 调用点与类声明都不动，其余 27 个文件零风险 |

选 (b) 的理由：本项目**整体就是无命名空间的扁平风格**（`app/*.php` 全部平铺），
`Storage/` 只是按职责分了目录，类名保持裸名与全项目一致。让加载器认识子目录，
比为了迁就加载器的注释去给四个文件加命名空间、再改 17 处调用点更贴合现状。

## 修复

`app/bootstrap.php`，自动加载器补兜底探测：

```php
spl_autoload_register(static function (string $class): void {
    // ① 常规映射：'Storage\TttttAdapter' → app/Storage/TttttAdapter.php
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = __DIR__ . '/' . $relative;
    if (is_file($path)) {
        require $path;
        return;
    }

    // ② 兜底：类文件按职责放在子目录、但类名不声明命名空间（app/Storage/ 就是这样）。
    //    全项目都是无命名空间的扁平风格，只有这几层分了目录 —— 与其为迁就加载器
    //    给 4 个类加命名空间、再改 17 处调用点，不如让加载器认识子目录。
    $leaf = basename($relative);
    foreach (['Storage'] as $sub) {
        $alt = __DIR__ . '/' . $sub . '/' . $leaf;
        if (is_file($alt)) { require $alt; return; }
    }
});
```

## 验证

**① 语法与类加载**

```
$ php -l app/bootstrap.php
No syntax errors detected in app/bootstrap.php

StorageAdapter     interface_exists=true     ← class_exists 对接口本就返回 false，属正常
ManualAdapter      class_exists=true
TttttAdapter       class_exists=true
StorageException   class_exists=true
```

**② 存储层入口**

```
Asset::adapter("ttttt")  → ✓ 返回 TttttAdapter，实现 StorageAdapter
Asset::adapter("manual") → ✓ 返回 ManualAdapter
```

**③ 两个必然失败的伪 cron 任务**

```
Asset::cronCheck(..)        → "集合比对 0 条正常，0 条缺失"
Asset::cronExpiresWatch(..) → "未发现非永久模式资产"
Asset::reconcileRefCounts() → 0
```

**④ 真实远端连通（不只是本地不报错）**

```
Asset::sync() → remote_total = 13，missing_local = 0，orphan_remote = 13
```

这一步走的是**真实 HTTP 请求**，等于顺带验证了 SPEC §7.2.2 的接口契约：

- 钛盘 API Key 有效、鉴权通过
- `list_of_direct` 分页循环正常
- `data` 是**数组**这一坑已正确规避（写成 `$r['data']['dkey']` 会静默拿到 null）
- 字段名是 `name` 而非 `filename` —— 13 条文件名全部解析正确（含中文名与空格名）

`orphan_remote = 13` 是**正常且预期**的结果：这 13 个是用户钛盘账号里属于其他项目的存量文件
（SVN 安装包、APK、自己的照片），博客库里没有记录，被正确判为孤立。

**⑤ 伪 cron 整轮**

强制 `Scheduler::runDue(60.0)` → 日志新增 **0 字节**（修复前每次必然新增两条致命错误）。

---

# D-09 · 媒体页「同步」结果文案与后端语义相反

**状态**：🔧 修复中
**发现时间**：2026-10-06（修完 D-07 后跑 `Asset::sync()` 时发现）
**严重度**：中 —— 不阻断运行，但会把排查方向指反

## 现象

后台「媒体」页点「同步」后显示：

```
本地缺失 = 图床有、这边没记录（可能是别处上传的，或数据库回滚过）。
图床孤立 = 这边没引用、图床也没记录，可考虑清理。
```

**这两句都和后端实际语义不符**，第二句本身还自相矛盾（「图床孤立」却说「图床也没记录」）。

## 根因

后端 `Asset::sync()`（`Asset.php:781-805`）的真实定义：

| 返回字段 | 实际含义 | 判定条件 |
|---|---|---|
| `missing_local` | **库有、远端无** —— 本地记录的直链在远端已消失 | `direct_url` 有值，但远端集合里找不到 |
| `orphan_remote` | **远端有、库无** —— 远端直链没有被任何本地资产引用 | 远端 link 不在本地已知集合里 |

前端把 `missing_local` 按字面读成了「本地缺失」（= 本地没有记录），于是写反了。
**根源是字段名本身有歧义** —— `missing_local` 既可读作「本地缺东西」也可读作「本地指的东西缺了」，
实际是后者。

## 影响

- 用户看到「图床孤立 N 个」，文案说「这边没引用、图床也没记录」——**读不出该做什么**
- 真正的孤立直链（`orphan_remote`）没有任何清理入口，文案却说「可考虑清理」

## 附带发现（不单列缺陷）

`Asset.php:808` 把孤立清单写进了 `Settings::set('asset_orphan_links', ...)`，
但**全项目没有任何地方读它**（`grep -rn "asset_orphan_links"` 只有这一处写入）。
属于无效存储，暂不清理以免影响后续要做的「批量撤销」功能。

## 修复

改前端文案对齐后端语义（**不动 API 字段名** —— 改名要连带改前端取值点，
收益不足以抵消接口契约的回归风险）；同时在 `Asset::sync()` 的返回处加注释，把这个歧义钉死。

## 验证

```
$ php -l app/Asset.php
No syntax errors detected in app/Asset.php

$ Asset::sync()
remote_total=13  missing_local=0  orphan_remote=13     ← 字段语义未变，只改文案与注释
```

前端文案已对齐后端语义；`npm run build` 通过（2.15s），产物已同步到站点根，页面全部 200。

---

# D-10 · `admin.export.list` 必定报错，备份页拿不到表清单

**状态**：🔧 修复中
**发现时间**：2026-10-06（修完 D-07 后逐个体检后台接口时发现）
**严重度**：高 —— 后台「备份」页直接不可用

## 现象

```
$ admin.export.list
{"ok":false,"error":{"code":"INTERNAL_ERROR","message":"Undefined array key \"table\""}}
```

同时错误已正确落进日志（`admin.log.list` 可见，级别 `ErrorException`）：

```
[2026-10-06 19:21:32] ErrorException: Undefined array key "table"
  file: app/actions/admin.php
```

## 根因

`admin_list_tables()`（`admin.php:705`）是 `SHOW TABLES` 的薄封装：

```php
function admin_list_tables(): array
{
    return Db::all('SHOW TABLES');
}
```

`SHOW TABLES` 返回的列名是 **`Tables_in_<库名>`**（这里即 `Tables_in_blog_dev`），
不是 `table`。于是 `admin.php:446` 的 `$r['table']` 永远取不到值。

同一个文件里的 `admin_dump_sql()`（`admin.php:714-716`）**写法是对的** —— 用 `reset($row)` 取首列：

```php
foreach (admin_list_tables() as $row) {
    $tables[] = (string) reset($row);   // ← 正确：不依赖列名
}
```

即：同一份数据在两个地方被用，一处写了列名假设，一处写了位置取值。
**只有前者是错的**，说明这是笔误而非设计问题。

## 影响

- 后台「备份」页拿不到表清单，页面无法正常渲染
- 生产环境（`APP_DEBUG=false`）下只会显示「服务器内部错误」，看不出任何线索
- 值得注意：**导出功能本身是好的** —— `admin_dump_sql()` 用 `reset()` 取值，能正常出 SQL。
  坏的只有那个列表接口。

## 修复

`admin.php:446` 改用与 `admin_dump_sql()` 一致的位置取值，不依赖列名：

```php
'tables' => array_map(
    static fn(array $r): string => (string) reset($r),
    admin_list_tables()
),
```

选位置取值而非写死 `Tables_in_blog_dev` 的理由：库名在本地（`blog_dev`）与线上（未定）
**必然不同**，写死列名会在换环境时再炸一次。`SHOW TABLES` 的列名永远带库名后缀，
只有 `reset()` 是跨环境安全的。

## 验证

（修复后回填）

---

# D-11 · `Markdown` 正则分隔符打架，含链接的文章让 share.php / feed.php 双双 500

**状态**：🔧 修复中
**发现时间**：2026-10-06（写进第一篇带链接的测试文章后暴露）
**严重度**：高 —— 两个面向外部的入口直接 500

## 现象

```
share.php?slug=xxx   HTTP 500  {"ok":false,"error":{"code":"INTERNAL_ERROR","message":"preg_match(): Unknown modifier ')'"}}
feed.php             HTTP 500  {"ok":false,"error":{"code":"INTERNAL_ERROR","message":"preg_match(): Unknown modifier ')'"}}
```

**库为空时这两个入口是正常的。** 只有正文里出现 Markdown 链接 `[文字](网址)` 才会触发。

## 根因

`app/Markdown.php:178`：

```php
if (!preg_match('#^(https?://|/|\./|#)#i', $href)) {
//               ↑ 分隔符                                    ↑ 模式内部的 # 把模式提前截断了
```

PHP 解析成：分隔符 `#`，模式 `^(https?://|/|\./|`，然后剩下的 `)` 被当作修饰符
→ `Unknown modifier ')'`。

第三个分支 `#` 本来是想放行「纯片段链接」（`#section` 这种锚点），
但它和分隔符撞了同一个字符。

## 影响

- `Markdown::toHtml()` 的**行内链接**分支，正文里每出现一个 `[文字](网址)` 就抛一次
- `share.php`（OG 分享卡片）与 `feed.php`（RSS）都在服务端调 `toHtml()`，**双双 500**
- 前台 SPA 不受影响 —— 它用 JS 的 `marked`，不走这份 PHP 实现。
  也就是说：**页面上看着好好的，分享到微信/RSS 订阅却全是 500**，正是最不容易自查到的那种

## 为什么 `php -l` 和之前的自测都没发现

1. `php -l` 只查语法，正则内容对不对它管不着
2. 之前库是空的，`toHtml()` 根本没被调用过
3. 只有在 APP_DEBUG 下才会把 `preg_match` 的 warning 升级成异常而暴露；
   **生产环境 `display_errors=0` 时，它会变成一条静默的日志 + 500**，更难查

## 修复

换掉分隔符。`~` 不在模式里出现，改完意图不变：

```php
if (!preg_match('~^(https?://|/|\./|#)~i', $href)) {
```

## 全项目同类排查

扫了所有用 `#` 当分隔符的正则，**只有这一处**模式内部含 `#`：

| 位置 | 模式 | 是否安全 |
|---|---|---|
| `admin.php:700` | `#^[a-z0-9.\-:*/?_~%=+@\[\]]+$#` | ✅ 内部无 `#` |
| `Asset.php:51/232/557/595/789` | `#^https?://#i` | ✅ |
| `Content.php:773/774/778/779` | `#bilibili\.com\|b23\.tv#i` 等 | ✅ |
| `Storage/ManualAdapter.php:39`、`TttttAdapter.php:187` | `#^https?://#i` | ✅ |
| **`Markdown.php:178`** | `#^(https?://\|/\|\./\|#)#i` | ❌ **内部含 `#`** |

## 验证

（修复后回填）

---

# D-12 · 链接正则不认括号，URL 被截断

**状态**：🔧 修复中
**发现时间**：2026-10-06（验证 D-11 时顺带发现）
**严重度**：中 —— 特定 URL 渲染错误，不是崩溃

## 现象

```
入: [维基](https://en.wikipedia.org/wiki/Foo_(bar))
出: <p><a href="https://en.wikipedia.org/wiki/Foo_(bar" rel="noopener noreferrer">维基</a>)</p>
                                                  ↑ href 少了结尾的 )   ↑ 多余的 ) 漏在正文里
```

## 根因

`Markdown.php:175` 的 URL 部分用了 `[^)\s]+` —— **遇到第一个 `)` 就停**：

```php
'/\[([^\]]*)\]\(([^)\s]+)\)/'
```

URL 里含括号时，第一个 `)` 被当成链接的右括号，剩下的 `)` 变成正文。

维基百科的条目 URL（`.../wiki/Foo_(bar)`）是最常见的例子，技术博客里引用很正常。

## 影响

- 含括号的 URL 链接目标错误（少一个字符就 404）
- 正文多出一个 `)`
- 同样只影响服务端渲染（`share.php` / `feed.php`）；前台 SPA 走 JS 的 `marked` 不受影响

## 修复

允许 URL 内部出现**一层**成对括号：

```php
'/\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)\)/'
```

顺带修好了另一个表现：`[x](javascript:alert(1))` 以前会漏出 `)`，现在整段被吃掉，
危险协议照旧退化为纯文本。

---

# D-13 · 行内图片语法未实现

**状态**：🔧 修复中
**发现时间**：2026-10-06（同上）
**严重度**：中 —— 功能缺失，且注释声称有这个功能

## 现象

```
入: ![配图](/uploads/a.jpg)
出: <p>!<a href="/uploads/a.jpg" rel="noopener noreferrer">配图</a></p>
      ↑ 感叹号被留下，图片变成了一个「!」加一条链接
```

## 根因

`Markdown.php` 的函数注释白纸黑字写着：

```php
/** 行内元素：粗体 / 斜体 / 删除线 / 行内代码 / 链接 / 图片 */
```

但函数体里**只有链接分支**，没有任何处理 `![...](...)` 的代码。
`![配图](url)` 于是被链接正则匹配到，`!` 留在原地。

**注释和实现对不上 —— 这类不一致比单纯的「功能没做」更危险**，
因为读代码的人会以为已经支持了，不去验证。

## 影响

- 服务端渲染的正文里图片全部显示不出来（`share.php` 分享页、`feed.php` RSS）
- 前台 SPA 用 JS 的 `marked`，图片正常 —— 所以又是一个「页面上看着好好的」问题
- `share.php` 的 OG 卡片本身用的是 `cover_path`，不受影响；受影响的是分享页的正文部分

## 修复

在链接分支**之前**加图片分支（顺序不能反，否则 `!` 又会被留下）：
放行 `https?://` / `/` / `./` 三种 src，其余（`javascript:` 等）退化为 alt 文本。

## 验证（两条一起）

```
[维基](https://en.wikipedia.org/wiki/Foo_(bar))   → href 完整，无多余 )
![配图](/uploads/a.jpg)                          → <img src="/uploads/a.jpg" alt="配图">
![恶意](javascript:alert(1))                     → 纯文本，无 img、无 href
[x](https://a.b)                                 → 普通链接不受影响
```

---

# D-14 · `Asset::addExternal` 写入不存在的列 `alt_text`

**状态**：🔧 修复中
**发现时间**：2026-10-06（体检媒体库接口时发现）
**严重度**：高 —— 手动登记外链图片的功能完全不可用

## 现象

```
$ admin.asset.add {"url":"https://example.com/pic.jpg"}
{"ok":false,"error":{"code":"INTERNAL_ERROR",
 "message":"SQLSTATE[42S22]: Column not found: 1054 Unknown column 'alt_text' in 'field list'"}}
```

## 根因

`Asset.php:261` 往 `assets` 表插入了 `alt_text` 列：

```php
$id = Db::insert('assets', [
    'rel_path'      => ...,
    'direct_url'    => ...,
    ...
    'alt_text'      => null,      // ← assets 表没有这一列
    'ref_count'     => 0,
    ...
]);
```

`alt_text` 属于 **`image_meta`**（图片类型的扩展表，`schema.sql:78`），不属于 `assets`。
区分这两张表是关键：

| 表 | 含义 | `alt_text` 在这里 |
|---|---|---|
| `assets` | **媒体库**：一个文件就是一个资产，与内容无关 | ❌ 没有 |
| `image_meta` | **内容**：一篇「图片」类型的内容 | ✅ 有 |

代码里其余所有 `alt_text` 的用法都是对的 —— 查证过：
`actions/public.php:292` 用的是 `im.alt_text`（`im` = image_meta 别名）、
`Content.php:658` 读的是 `$meta['alt_text']`、`src/panel/ContentEdit.vue:53/368` 是图片内容的表单字段。
**只有 `Asset.php:261` 这一处张冠李戴。**

## 影响

- 后台媒体库「手动登记外链图片」100% 失败
- 因为 `addExternal` 是「库里有这个 URL 就直接返回已有 id」的去重入口，
  失败会让调用它的地方（外链素材登记）全线不可用

## 修复

删掉那一行。`addExternal` 建的是**媒体库资产**，alt 文本是内容层（`image_meta`）的事，
不该在这里写。

## 验证

（修复后回填）

---

# D-15 · `content.restore` 一律恢复为草稿

**状态**：✅ 已确认 —— **保持现状**，有意设计，非缺陷
**发现时间**：2026-10-06（测回收站时发现）
**结论时间**：2026-10-06（已与需求方确认）
**严重度**：不适用

## 现象

一篇**已发布**的文章 → 移入回收站 → 恢复 → 它的状态变成 `draft`，**从前台静默消失**。

```
$ admin.content.restore {"id":1}
{"ok":true,"data":{"restored":true}}

# 恢复后：
id: 1   slug: debug-selftest   status: draft   ← 恢复前是 published
$ content.list → {"ok":true,"data":[],...}     ← 前台看不到了
```

## 代码

`Content.php:350-357`：

```php
public static function restore(int $id): void
{
    $now = date('Y-m-d H:i:s');
    Db::q(
        "UPDATE contents SET status = 'draft', deleted_at = NULL, updated_at = ?
          WHERE id = ? AND status = 'trashed'",
        [$now, $id]
    );
}
```

`status = 'draft'` 是**写死的**，不是从删除前的状态还原。

## 为什么不直接改

`trash()` 只把 `status` 改成 `'trashed'`，**没有记录原状态**，
所以恢复时也拿不到「原来是什么」—— 要改成「恢复原状」得先让 `trash()` 记住原状态
（加一列或复用 `deleted_at` 之外的地方），那是改表结构，影响面比修一个字段大。

而且这个行为**未必是错的**：很多 CMS 就是这么设计的 —— 恢复回来的东西不该自动重新公开，
应该由人再确认一次。SPEC §320 只说「回收站，前台不可见，后台可见可恢复」，
`TECHNICAL_PLAN.md` 也只写了方法签名，**两处都没有规定恢复后的状态**。

所以这是一个**产品决策**，不是明确违规。

## 结论（已确认）

| 选项 | 后果 | 决定 |
|---|---|---|
| **A. 保持现状**（恢复为草稿） | 安全但反直觉：文章从回收站回来时不会自动上线，得手动再发布一次 | ✅ **采用** |
| B. 用 `published_at` 推断 | 零结构改动，但「曾发布 → 改回草稿 → 删除 → 恢复」会误判成已发布，**把草稿意外公开且不可逆** | 否 |
| C. 加列记住原状态 | 行为最准确，但偏离 SPEC §5 冻结的表结构 | 否 |

**定论**：采用 A。理由是两种代价不对等 —— A 的代价是「多点一次发布」，
可挽回；B/C 一旦猜错，是**不可逆的信息暴露**。SPEC §5 的表结构保持冻结。

代码里已加注释说明这是有意为之（[Content.php:349](ailearning/blog/app/Content.php#L349)），
免得日后有人当 bug 再"修"回去。

**验证**：已在 `restore()` 上方补注释，`php -l app/Content.php` 通过；行为未变（仍恢复为草稿）。

---

# 前序修复（追溯登记）

本文件建立之前已修复的缺陷，补齐登记 —— 目的是留下回归依据，不只是历史。

## D-01 · install.php 第二步建表失败时页面无反应

**现象**：访问 `install.php?step=2` 点按钮后页面毫无变化，DevTools 里只有 Cloudflare 的 499。
**根因**（三处叠加）：

1. step 2 的 `catch` 把错误写进 `$error`，但**模板里从未渲染它** —— 失败时只是重画同一个页面
2. `config.php` 被 `require` 了两次 → `define()` 重复告警 → 输出已开始 → 之后所有 `header('Location:')` 静默失效
3. `$installed` 只看 `settings` 表是否存在，但表在 step 2 建、密码在 step 3 设 —— 中途失败会把自己锁在向导外

**修复**：模板补渲染 `$error`；两处 `require` 改 `require_once`；新增 `$installComplete`（表存在**且** `admin_pass_hash` 非空）取代 `$installed` 作为向导守卫；step 3 成功后直接 `renderDone()` 而不再用可重放的 `?step=4` URL（step 4 会显示随机后台入口，重放即泄露，SPEC §9.1）。

## D-02 · `\R` 正则把 UTF-8 汉字从中间劈开

**现象**：建表报 `SQLSTATE[42000] ... 1064 ... near '?容** ? SPEC §2.2】'`，错误信息里汉字变成乱码。
**根因**：`preg_split('/\R/')` 在非 UTF-8 模式下，`\R` 会匹配字节 `0x85`（NEL）。而 UTF-8 汉字的 3 字节序列里经常含 `0x85` 作为中间字节（关 `E5 85 B3`、照 `E7 85 A7`、全 `E5 85 A8` 等）。于是按行切分时把汉字劈成两半，注释尾部残留下来被当成 SQL 执行。

**修复**：`install.php` 与 `app/Markdown.php` 中全部改为 `preg_split('/\r\n|\n|\r/')`；`runSchema()` 增加 `^[A-Za-z]` 语句合法性自检，让这类错误自己报出来。

> **项目约定：任何地方都不要对 UTF-8 文本用 `\R`。**

## D-03 · `Router::add()` 形参声明为 `string`，调用点全传闭包

**现象**：`Router::add(): Argument #2 ($handler) must be of type string, Closure given, called in app/actions/public.php on line 38` —— 每次请求都是这一条，整个站点只剩这一个报错。
**根因**：形参声明为 `string`，而 45 个调用点全部传闭包。
**修复**：改为 `callable`。附带确认：PHP 不允许给**属性**声明 `callable` 类型，所以 `$routes` 只能靠 `@var` 注解；也正因如此 `dispatch()` 里的 `is_callable()` 是这条类型链上唯一真正生效的检查，不能删。

## D-04 · CSP 缺 `frame-src`，自定义播放器被静默拦成白框

**现象**：`provider: 'custom'` 的视频嵌入是个安静的白框，页面无任何报错，只在控制台留一条 CSP 违规记录。
**根因**：`Response::securityHeaders()` 只输出了 `img-src`，没有 `frame-src`，iframe 一律被拦。
**修复**：新增 `frame_whitelist` 设置项，端到端打通（`schema.sql` / `admin.php` 白名单与归一化 / `Settings.vue` 表单 + 即时校验）。bilibili 与 YouTube 作为内置平台写死。

## D-05 · 白名单域名未过滤即拼进响应头

**现象**：修复 D-04 时自己引入的风险 —— `cdn_whitelist` / `frame_whitelist` 会被直接拼进 `Content-Security-Policy` 响应头。
**根因**：一个 `;` 就能往头里注入任意 CSP 指令，一个换行能分裂出第二个响应头（CRLF 注入）。
**修复**：新增 `admin_normalize_domains()`，按字符白名单 `[a-z0-9.\-:*/?_~%=+@\[\]]` 过滤 + 长度限制 + 用数组键去重；前端 `Settings.vue` 加同规则镜像并实时提示被丢弃的条目。

## D-06 · Vite dev 代理指向错误

**现象**：本地开发时所有 `api.php` 请求 404。
**根因**：代理目标写死 `http://localhost`，而项目部署在子目录 `/blog`。
**修复**：默认改为 `http://localhost/blog`，并支持 `DEV_API_TARGET` 环境变量覆盖。

---

# D-08 · 非 PHP 文件可被公开下载

**状态**：✅ 已修
**发现时间**：2026-10-06 13:0x（自测第二轮）
**严重度**：高（信息泄露）

**现象**：

```
app/schema.sql   HTTP 200  17301B  application/x-sql   ← 全文 DDL
README.md        HTTP 200  5460B
package.json     HTTP 200  511B
```

**根因**：三层防护里只有一层对静态文件有效：

| 文件类型 | 防护机制 | 有效性 |
|---|---|---|
| `*.php` | 文件首行 `if (!defined('APP_BOOT')) { http_response_code(404); exit; }` | ✅ 有效 |
| 目录 | 各目录 `index.html` 守卫 | ✅ 有效（禁列举） |
| **非 PHP 静态文件** | **无** | ❌ 直接取文件绕过目录守卫 |

`index.html` 只防「列出目录」，防不住「指名道姓取文件」。SPEC §2.1 已确认线上**无重写能力**，`.htaccess` 这条路不通。

**影响**：`app/schema.sql` 在「必须上传」清单里，**线上同样会暴露** —— 完整数据库结构公开。
（`README.md` / `docs/` / `package.json` 在「不上传」清单里，线上不会有。）

## 修复

把建表脚本包成 PHP 文件，让它落到已有的那层防护里：

| 文件 | 改动 |
|---|---|
| `app/schema.sql` | → `app/schema.sql.php`，加 `APP_BOOT` 守卫，SQL 装进 `return <<<'SQL' … SQL;` |
| `install.php` | 路径常量改指新文件；读取方式由 `file_get_contents()` 改为 `(string) require` |
| `install.php` | 文案 4 处（第 8/56/172/227 行注释 + 第 435/442/479 行提示）同步改名 |
| `README.md` | 「必须上传」清单里的文件名同步 |
| — | 原 `app/schema.sql` 删除 |

两个容易踩的点，都写进了代码注释：

1. **必须用 `require` 而不是 `file_get_contents`。** 后者读回来的会是
   `<?php` + 守卫 + nowdoc 的**源码**，喂给 `runSchema()` 必炸。
2. **nowdoc 会吃掉结束标识前的那个换行。** 拼接时要多补一个 `\n`，
   否则 `require` 出来的字符串比原文件少 1 字节（实测 17300 vs 17301）。
   对 `runSchema()` 无影响（它靠 `explode(';')` + `trim` 切分），但会破坏
   「逐字节可比对」这个自查手段。

用 nowdoc（`<<<'SQL'`）而非 heredoc，是因为 nowdoc 不解析 `$` 与转义 ——
将来往 SQL 里写变量不会被 PHP 悄悄吃掉。

## 验证

**① 内容搬运无损耗**（生成脚本逐字节比对，不手抄）：

```
原 .sql 长度 : 17301
新 .php 取出 : 17301
✅ 逐字节一致
```

**② 直连已无法下载**：

```
http://localhost/blog/app/schema.sql.php    HTTP 404  0B
http://localhost/blog/app/schema.sql        HTTP 404  295B     ← 文件已删除
```

**③ 建表路径未被破坏** —— 用 `runSchema()` 的原算法切分 `require` 出来的字符串：

```
切出语句数    : 18
CREATE TABLE  : 14
不合法语句    : 0          ← 兜底自检（每条须以字母开头）无一触发
库里现有表数  : 14         ← 与脚本一致
```

✅ 通过。

---

# D-16 · `Asset::revoke` 对外链资产报「撤销失败，待重试」的假错误

**状态**：✅ 已修
**发现时间**：2026-10-06 19:31（自测第五轮，媒体库）
**严重度**：中 —— 不损坏数据，但会给用户一个永远重试不成功的错误提示

## 现象

对一条手动登记的外链资产调 `admin.asset.revoke`：

```
$ php blog_admin_probe.php admin.asset.revoke '{"id":2}'
{"ok":true,"data":{"revoked":false}}

$ SELECT last_error FROM assets WHERE id = 2;
'撤销失败，待重试'
```

但 **`ManualAdapter` 从设计上就不支持撤销**（[ManualAdapter.php:79](ailearning/blog/app/Storage/ManualAdapter.php#L79)
`supportsRevoke()` 返回 `false`，第 24 行注释写得很清楚：「别人的图，无权撤销。返回 false 让调用方记录日志并继续。」）。

也就是说这条 `last_error` 把「**能力缺失**」写成了「**操作失败**」，语义是错的：
用户看到「待重试」会以为再试一次就行，实际上再试一万次也是同样结果。

## 根因

[Asset.php:632-642](ailearning/blog/app/Asset.php#L632-L642) 只区分了 `revoke()` 的返回真假，
**没有查 `supportsRevoke()` 这个能力位**：

```php
$adapter = self::adapter((string) $asset['provider']);
$ok = $adapter->revoke($asset, true);       // ← 能力位在这里被丢掉了

if ($ok) { ... } else {
    Db::q('UPDATE assets SET last_error = ? WHERE id = ?', ['撤销失败，待重试', $id]);
}
```

`supportsRevoke()` 全项目**没有任何调用点**（`supportsUpload()` / `supportsTransform()` 也只在
上传表单里用到）—— 能力位定义了却没人用。

## 影响

- 媒体页对不支持撤销的通道（`manual`）会显示误导性的错误行。
- 用户无法从文案区分「这台存储不支持撤销」「网络抖了一下」「API Key 过期了」三种情况，
  而后两者的处置方式完全不同。

## 修复

`revoke()` 先查能力位，不支持时**不写 last_error**，直接返回 `false`；
并用返回结构把「不支持」和「失败」分开表达。

## 验证

```
$ php blog_admin_probe.php admin.asset.revoke '{"id":3}'     # manual 外链资产
{"ok":true,"data":{"revoked":false}}

$ SELECT id, provider, last_error FROM assets;
id=3  provider=manual  last_error=NULL      ← 修复前是 '撤销失败，待重试'
```

✅ 通过。返回值仍是 `false`（对「有没有真的撤销掉远端」这是诚实的），
但不再伪造一条永远重试不成功的提示。

---

# D-17 · 永久删除内容时资产永远不被释放，远端文件永不撤销

**状态**：✅ 已修
**发现时间**：2026-10-06 19:35（自测第五轮，destroy 端到端）
**严重度**：**高** —— 直接违反 SPEC §7.2.3 的空间释放要求，且症状完全静默

## 现象

走完整的「新建图片内容 → 引用资产 → 永久删除」流程：

```
$ php blog_admin_probe.php admin.content.save \
    '{"type":"image","title":"destroy 用临时图片","slug":"tmp-destroy-probe",
      "status":"published","asset_id":2,"width":800,"height":600}'
{"ok":true,"data":{"id":2}}

$ SELECT id, ref_count FROM assets;      # 引用建立，计数正确
asset id=2 ref_count=1

$ php blog_admin_probe.php admin.content.batch '{"ids":[2],"op":"destroy"}'
{"ok":true,"data":{"affected":1,"op":"destroy"}}

$ php blog_admin_probe.php admin.asset.list    # ← 内容没了，资产却还在
{"ok":true,"data":{"rows":[{"id":2,"provider":"manual", ... "ref_count":0 ...}],"total":1}}
```

内容被彻底删除后，资产记录**原样留在 `assets` 表里**，`ref_count` 停在 0。

按设计，这一步应该：无引用 → `revoke(deleteFile: true)` → 删记录。**一条都没发生。**

## 根因

[Content.php:365-385](ailearning/blog/app/Content.php#L365-L385) 里 `releaseForContent()` 排在
删 `image_meta` **之前**：

```php
Asset::releaseForContent($id);        // ← ①先释放资产

Db::q('DELETE FROM content_tags WHERE content_id = ?', [$id]);
Db::q('DELETE FROM article_meta  WHERE content_id = ?', [$id]);
Db::q('DELETE FROM video_meta    WHERE content_id = ?', [$id]);
Db::q('DELETE FROM image_meta    WHERE content_id = ?', [$id]);   // ← ②后删引用
Db::q('DELETE FROM contents      WHERE id = ?', [$id]);
```

而 [releaseForContent()](ailearning/blog/app/Asset.php#L671-L688) 内部靠
`recomputeRefCount()` 重新数 `image_meta` 来判断该资产还有没有引用：

```php
$n = self::recomputeRefCount($assetId);   // SELECT COUNT(*) FROM image_meta WHERE asset_id = ?
if ($n === 0) { self::revoke($assetId); Db::q('DELETE FROM assets WHERE id = ?', [$assetId]); }
```

①的时刻，②还没执行 —— **那条引用行还好端端地在 `image_meta` 里**，
`COUNT(*)` 至少是 1，于是 `$n === 0` **恒为假**，释放分支永远进不去。

代码上方的注释写的是「释放该内容引用的资产：ref_count 减到 0 的走 `revoke(deleteFile: true)`」，
**注释描述的是期望行为，实现却做不到** —— 这是典型的「注释与代码各说各话」。

## 影响

破坏力比表面看起来大：

1. **`assets` 表只增不减。** 每次永久删除内容都留下孤儿记录，媒体库里越堆越多。
2. **钛盘空间永不释放。** `revoke()` 内部那条 `link_del` + `delete=1`（SPEC §7.2.3：
   「只传 `dkey` 的话，链接失效了、文件却永远占位」）**一次都不会被触发**。
   图片内容删得越勤，图床上堆的废弃文件越多，且系统自身再也无从枚举它们
   （`orphan_remote` 只能看见图床上「本就无记录」的文件，看不见这些「有记录但记录已失效」的）。
3. **完全静默。** `destroy` 返回 `{"ok":true}`，无报错、无日志、无告警。
   只有去查 `assets` 表才会发现资产没少。

这个缺陷与 D-07 属于同类：「页面全绿，功能全废」。

## 修复

把 `Asset::releaseForContent($id)` 移到 `DELETE FROM image_meta WHERE content_id = ?`
**之后**（仍留在同一事务内）。此时再数 `image_meta`，得到的就是
「**这个内容消失之后**该资产还剩多少引用」，语义才与注释一致。

顺序调整后重跑上面三步，预期 `asset.list` 返回 `total: 0`。

## 验证

重建场景（新建引用资产的图片内容 → 引用计数变 1 → trash → destroy）：

```
$ ... content.save {type:image, asset_id:2, ...}   → {"id":3}
$ SELECT id, ref_count FROM assets;
asset id=2 ref_count=1

$ ... content.batch {"ids":[3],"op":"destroy"}     → {"affected":1,"op":"destroy"}

$ php blog_admin_probe.php admin.asset.list
{"ok":true,"data":{"rows":[],"total":0,"page":1,"per_page":24}}
                       ↑ 修复前这里返回 total:1，那条资产赖着不走
```

✅ 通过。修复后资产被正确释放。

> 注：`asset_check` 伪 cron 的集合比对结果不受影响（0 条正常 / 0 条缺失，因为库里已无资产），
> 且该任务本轮真实调用了钛盘 `list_of_direct`（耗时 1896ms），未出现异常。

---

# D-18 · 撤销失败仍删 assets 记录，丢失 UKEY 使远端文件永久占位

**状态**：✅ 已修
**发现时间**：2026-10-06 19:35（读 D-17 同族代码时发现）
**严重度**：中高 —— 与 D-17 同属「远端文件永远占位」，但触发条件是网络/鉴权故障

## 现象

[Asset.php:682-686](ailearning/blog/app/Asset.php#L682-L686)：

```php
if ($n === 0) {
    // 无引用 → 撤销并删除记录（撤销失败不阻塞，SPEC §10.2）
    self::revoke($assetId);                              // ← 返回值被丢弃
    Db::q('DELETE FROM assets WHERE id = ?', [$assetId]); // ← 无论成败都删
}
```

`revoke()` 失败（网络超时、钛盘 API 临时故障、`TTTTT_API_KEY` 失效）时，
**记录照删**。而 `assets.rel_path` 存的正是钛盘的 UKEY —— 重建直链的唯一凭据。

## 影响

记录一删，本系统**再也没有任何办法**对那个远端文件发起 `link_del`：

- 该文件在钛盘上继续占配额；
- 不知道它叫什么、在哪个 dkey；
- `Asset::sync()` 的 `orphan_remote` 只能列出「图床有、本地无记录」的文件，
  但**给不出 UKEY**，也就无法撤销 —— 只能干看着。

`revoke()` 自己刚写了 `last_error = '撤销失败，待重试'`（[Asset.php:640](ailearning/blog/app/Asset.php#L640)），
**紧接着这行记录就被删了** —— 连那条「待重试」的线索都一并抹掉。注释说的
「撤销失败不阻塞（SPEC §10.2）」是对的，但「不阻塞」不等于「销毁重试依据」。

## 修复

撤销失败时**保留记录**，只标记状态（`last_error` 已经写好了），交给
`asset_check` 伪 cron 或「待清理」列表后续重试；撤销成功才删记录。

## 验证

构造「支持撤销、但这次失败」的场景：把一条测试资产的 `provider` 改成 `ttttt`
但 `remote_dkey` 留空。`TttttAdapter::revoke()` 第 107-109 行在无 dkey 时
**直接 `return false`、不发任何请求** —— 所以这个构造零网络调用、零删除风险。

```
$ php blog_admin_probe.php admin.content.batch '{"ids":[4],"op":"destroy"}'
{"ok":true,"data":{"affected":1,"op":"destroy"}}

$ SELECT id, provider, ref_count, last_error FROM assets;
id=3  provider=ttttt  ref_count=0  last_error='撤销失败，待重试'
                                                       ↑ 记录保住了，提示也还在

$ php blog_admin_probe.php admin.asset.delete '{"id":3}'
{"ok":false,"error":{"code":"STORAGE_ERROR",
  "message":"远端直链撤销失败，记录已保留以便重试，请稍后再试","field":"id"}}
$ SELECT id FROM assets;   → id=3 仍在
```

✅ 通过。两种情况（自动释放 / 用户主动删除）都不再销毁重试凭据。

---

# D-19 · install.php 建表页文案称「创建 12 张表」，实际 14 张

**状态**：✅ 已修
**发现时间**：2026-10-06 19:5x（修 D-08 时顺带核对）
**严重度**：低 —— 纯文案错误，不影响建表

## 现象

[install.php:479](ailearning/blog/install.php#L479) 建表步骤的提示写的是：

```
将执行 app/schema.sql，创建 12 张表并写入默认配置与首页区块。
```

实际 `schema.sql.php` 里有 **14** 条 `CREATE TABLE`：

```
contents  article_meta  video_meta  image_meta  tags  content_tags  assets
settings  tasks  login_attempts  view_seen  view_daily  slug_redirects  home_blocks
```

与 SPEC §5 的 14 张表一致，库里实际也建出了 14 张。

## 根因

纯手写文案失修。数字大概是从更早的草案抄来的，schema 后来加表（`slug_redirects`、
`home_blocks` 等）时没同步这行字。

## 影响

用户装完后如果拿这句提示去核对表数（本该是最自然的自查动作），会发现对不上，
进而怀疑「是不是有两张表没建成功」—— 而真正的原因是这行文案本身写错了。
这类「提示语把用户引向错误排查方向」的毛病，和 D-09 是同一类。

## 修复

`install.php:479` 文案改为「创建 14 张表」，文件名同步为 `app/schema.sql.php`（D-08）。

## 验证

用 `runSchema()` 的原算法切分 `schema.sql.php`（见 D-08 验证③）：

```
切出语句数    : 18
CREATE TABLE  : 14         ← 与修正后的文案一致
库里现有表数  : 14
```

✅ 通过。
