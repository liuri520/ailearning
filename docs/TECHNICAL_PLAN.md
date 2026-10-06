# TECHNICAL_PLAN.md — 个人博客站点技术方案（实现蓝图）

> **Written for:** 本项目的开发者（你自己），以及后续接手此代码库的协作者 / AI 助手。
> **本文档的定位**：SPEC.md 是**唯一事实来源**（Single Source of Truth）。本文档是把 SPEC 落地为**可执行实现**的方案，只补充 SPEC 未展开到代码级的决策。**任何与 SPEC.md 冲突之处，以 SPEC.md 为准。**
> 全文以 `§` 引用 SPEC.md 章节号（如 §7.2.2）；以 `M0–M12` 引用 SPEC §13 的实施阶段。

- **版本**：v1.0
- **配套规范**：SPEC.md v1.4（2026-10-06 冻结）
- **代码落位**：`ailearning/` 仓库，站点根为 `blog/`（对应 SPEC §4.1）
- **当前状态**：仅方案，尚未开工

---

## 1. 概述与定位

### 1.1 这个项目是什么

一个部署在**共享 PHP 主机**（PHP 8.1 / MySQL 5.6.51 / 无 Composer / 无重写 / 无 cron / 无 GD）上的个人博客站点：

- 三类内容：文章（Markdown）、视频（外链）、图片（第三方图床）
- 单管理员后台：内容 CRUD、媒体库、标签、批量操作、拖拽排序、导出备份
- 前端 SPA（Vue 3 + Vite），Hash 路由，构建产物上传 `dist/`
- 图床固定为**钛盘（ttttt.link）**，四个接口已实测，规格冻结（§2.3）

### 1.2 本文档解决的三个问题

SPEC 已经冻结了「做什么」（数据模型、API 表、安全规则、图床协议）。本文档补齐「怎么做」：

1. **SPEC 留白的实现级决策** —— 前端状态管理、Vite 多页构建、后台入口随机化的具体做法、图片双尺寸处理实现、拖拽排序技术选型等（见 §9 开放决策项）。
2. **代码骨架** —— 每个类的职责与关键方法签名，让编码阶段只需填充实现，不必重新设计。
3. **阶段到产物的映射** —— M0–M12 每阶段产出哪些文件，验收怎么跑（细化 SPEC §13）。

### 1.3 阅读约定

- 表格中 `§x.y` = SPEC.md 对应章节；`TP §x` = 本文档章节。
- 标注 **【决策】** 的条目是 SPEC 未指定、由本文档拍板的实现选择。
- 标注 **【约束】** 的条目来自 SPEC，**不可改动**，重复列出仅为提示编码时注意。

---

## 2. 总体架构

### 2.1 分层

```
┌─────────────────────────────────────────────────────────────┐
│  浏览器                                                       │
│  ├─ index.html  (公共 SPA: Vue 3, Hash 路由)                  │
│  └─ panel-XXXX.php → panel.html (后台 SPA)                    │
└───────────────────────┬─────────────────────────────────────┘
                        │ 相对路径请求 ./api.php?action=域.方法
┌───────────────────────▼─────────────────────────────────────┐
│  PHP 应用层（零 Composer，手写 autoload）                      │
│  api.php ─► bootstrap.php ─► Router.php ─► actions/*.php      │
│                                    │                          │
│           ┌────────────────────────┼──────────────────────┐   │
│           ▼            ▼           ▼          ▼           ▼   │
│        Content      Asset       Tag       Settings   Scheduler │
│           │            │                                     │
│           ▼            ▼                                     │
│         Db.php     Storage/ (TttttAdapter / ManualAdapter)    │
│           │            │                                     │
└───────────┼────────────┼─────────────────────────────────────┘
            ▼            ▼
        MySQL 5.6     钛盘 API (upload_cli / services/direct)
```

### 2.2 请求数据流

**前台读（对应 SPEC §4.3）**：

```
GET ./api.php?action=content.list&type=article&page=1
  → bootstrap.php：时区 / 错误处理 / 常量 / autoload / 安全头
  → 概率触发伪 cron（random_int(1,100) <= cron_probability，响应后异步）
  → Router：校验 action 在公开白名单内
  → Cache::remember(key, ttl)   ← 默认关闭
  → Content::list() → PDO 预处理查询（强制拼 status='published' AND published_at<=NOW()）
  → Response::json(ok, data, meta)
```

**后台写（对应 SPEC §4.3）**：

```
POST ./api.php?action=admin.content.save
  → Router：admin 分域，统一守卫 Auth::requireLogin() → 401
  → Auth::verifyCsrf()（X-CSRF-Token 头，hash_equals）→ 403
  → 入参校验（类型/长度/枚举/必填）
  → 事务：contents + xxx_meta + content_tags
  → Settings::bumpCacheVersion()  使全部缓存成为孤儿
  → Response::json
```

### 2.3 目录结构（落位到 `ailearning/blog/`）

沿用 SPEC §4.1 的结构，**新增**以下项（本文档补充，SPEC 未列）：

```
blog/
├── index.html                ← Vite 构建产物（公共 SPA 入口）
├── panel.html                ← Vite 构建产物（后台 SPA 外壳）【新增】
├── assets/                   ← Vite 构建产物（JS/CSS，带 hash）
├── api.php
├── share.php
├── feed.php
├── install.php
├── panel-template.php        ← 后台入口模板，install 时重命名为 panel-<随机>.php【新增】
├── config.sample.php         ← 配置模板（§11.1 要求）
├── config.php                ← 实际配置（永不覆盖）
├── app/
│   ├── bootstrap.php
│   ├── Db.php  Router.php  Auth.php  Response.php
│   ├── Content.php  Tag.php  Asset.php
│   ├── Scheduler.php  Cache.php  Settings.php
│   ├── Markdown.php  Str.php
│   ├── schema.sql            ← 全部建表 SQL（§5 + 附录 A 注释）【新增】
│   ├── Storage/
│   │   ├── StorageAdapter.php  TttttAdapter.php
│   │   ├── ManualAdapter.php   StorageException.php
│   └── actions/
│       ├── public.php  admin.php
├── data/
│   ├── cache/  logs/  export/
│   └── index.html            ← 空文件，防目录列举（§9.4）
├── uploads/tmp/              ← 上传中转，用完即删
└── src/                      ← 前端源码（开发期，不部署）
    ├── main.js  panel.js  App.vue  PanelApp.vue
    ├── router/  views/  components/  lib/  styles/
    └── index.html  panel.html   ← Vite 源模板
```

**【决策】`src/` 不部署到线上**：构建产物（`index.html` / `panel.html` / `assets/`）复制到站点根即可，`src/` 与 `node_modules/` 留在本地仓库。

### 2.4 【约束】子目录部署（SPEC §4.2）

编码时反复自查的五条：

1. `config.php` 定义 `APP_BASE`（如 `/blog`），由 install 探测。
2. PHP 输出 URL 一律经 `site_url()`（补 `APP_BASE`）；图片 URL 走 `asset_url()`（**两套不要混用**）。
3. 前端 `base: './'`，所有请求写 `./api.php?action=...`，**禁止 `/` 开头**。
4. `index.html` / `panel.html` 中不得有绝对路径资源引用。
5. Cookie `path` = `APP_BASE`。

**验收**：站点目录改名为 `/b2/`，仅改 `APP_BASE` 一处，全站正常（SPEC §4.2）。

---

## 3. 后端实现（PHP 零依赖）

### 3.1 引导流程 `bootstrap.php`

**【决策】初始化顺序固定如下**，顺序错会引入难查的问题：

```php
const APP_BOOT = true;                    // 供守卫 `if (!defined('APP_BOOT'))` 判定

date_default_timezone_set('Asia/Shanghai');           // ① 时区最先（§D22）
ini_set('display_errors', '0');                       // ② 生产静默（§9.7）
error_reporting(E_ALL);                               //     但记录全部

require __DIR__ . '/../config.php';                   // ③ 常量：DB_* / APP_BASE / APP_DEBUG / ADMIN_ENTRY / TTTTT_API_KEY / IP_SALT

spl_autoload_register(function (string $class): void { // ④ 手写 autoload（零 Composer）
    // 'Storage\TttttAdapter' → app/Storage/TttttAdapter.php
    $rel = str_replace('\\', '/', $class) . '.php';
    $path = __DIR__ . '/' . $rel;
    if (is_file($path)) require $path;
});

set_exception_handler([ErrorHandler::class, 'handle']); // ⑤ 全局异常 → 日志 + 中性 JSON（§9.7）
set_error_handler([ErrorHandler::class, 'handleError']); //    捕获 Warning 转异常（§10.4「PHP 警告混入输出」）

Auth::startSession();                                 // ⑥ 会话（§9.1 的 cookie 参数）
Response::securityHeaders();                          // ⑦ 安全头（§9.8）

Scheduler::maybeTrigger();                            // ⑧ 概率触发伪 cron（§7.1），必须最后
```

**【约束】** `APP_DEBUG=false` 时，未登录用户的异常只返回 `INTERNAL_ERROR` + 「服务器内部错误」，不暴露路径/SQL/堆栈（§9.7）。

### 3.2 `Db.php`

```php
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO;
    // DSN: mysql:host=..;dbname=..;charset=utf8mb4
    // 选项: ERRMODE_EXCEPTION | FETCH_ASSOC | EMULATE_PREPARES=false（真正的预处理，§9.5）

    /** 预处理查询，$params 顺序绑定或命名绑定 */
    public static function q(string $sql, array $params = []): PDOStatement;

    public static function one(string $sql, array $params = []): ?array;    // 取一行
    public static function all(string $sql, array $params = []): array;     // 取多行
    public static function val(string $sql, array $params = []);           // 取首行首列
    public static function insert(string $table, array $row): int;         // 返回 lastInsertId
    public static function affected(PDOStatement $st): int;
    public static function begin(): void;  public static function commit(): void;  public static function rollback(): void;
}
```

**【约束】**
- 全部 SQL 走 `q()` 预处理，**禁止字符串拼接**（§9.5）。
- 时间字段由 **PHP 显式写入** `date('Y-m-d H:i:s')`，不依赖 DB 默认值（§2.2：MySQL 5.6 的 `DATETIME DEFAULT` 有单列限制）。
- 所有 `GROUP BY` 查询**写全非聚合列**，以便未来迁移 5.7/8.0 不被 `ONLY_FULL_GROUP_BY` 打断（§2.2）。
- 不使用 `WITH` / 窗口函数 / `SKIP LOCKED` / `DESC` 索引 / JSON 列（§2.2）。

### 3.3 `Router.php` 与 action 分发

```php
final class Router
{
    /** @var array<string, callable-string> 形如 'content.list' => 'public_content_list' */
    private static array $routes = [];

    public static function dispatch(): void;
    // ① 读 $_GET['action']，为空 → 400（生产只回 400，开发期附可用 action 列表，§10.4）
    // ② 未命中白名单 → 404，绝不动态调用
    // ③ action 以 'admin.' 开头 → Auth::requireLogin() + Auth::verifyCsrf()
    // ④ 调用 handler，返回值交给 Response::json()
}
```

**【决策】白名单注册**：路由表在 `actions/public.php` / `actions/admin.php` 底部集中声明，**不做基于方法名的反射**（避免任意调用风险）。

```php
// actions/admin.php 底部
Router::add('admin.content.save',    'admin_content_save',    needsLogin: true);
Router::add('admin.asset.upload',    'admin_asset_upload',    needsLogin: true);
// ... 与 SPEC §6.3 表格逐行对应
```

**【决策】handler 命名规范**：`<域>_<资源>_<动作>` 小写下划线函数，一个 action 一个函数。

### 3.4 `Response.php`

```php
final class Response
{
    public static function json($data, ?array $meta = null, int $status = 200): never;
    public static function ok($data = null, ?array $meta = null): never;      // {ok:true,data,meta}
    public static function fail(string $code, string $message, int $status = 400, ?string $field = null): never;
    public static function securityHeaders(): void;                           // §9.8 全套
    public static function noCache(): void;                                   // admin 接口禁缓存
}
```

**【约束】** 错误码枚举固定为 §6.1 的九个：`VALIDATION_FAILED` / `UNAUTHORIZED` / `CSRF_INVALID` / `NOT_FOUND` / `RATE_LIMITED` / `SLUG_TAKEN` / `STORAGE_ERROR` / `UPLOAD_TOO_LARGE` / `INTERNAL_ERROR`；图床补充码 `STORAGE_BUSY` / `STORAGE_FULL` / `STORAGE_QUOTA` / `STORAGE_AUTH`（§7.2.2）。

**安全头（§9.8，逐条实现，`img-src` 域名来自设置）**：

```php
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN          // share.php 也要 SAMEORIGIN，不要 DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy:
  default-src 'self';
  img-src 'self' data: <图床域名白名单，来自 settings.cdn_whitelist>;
  media-src 'self' https:;
  frame-src player.bilibili.com www.youtube.com;
  script-src 'self';
  style-src 'self' 'unsafe-inline';
  connect-src 'self';
```

### 3.5 `Auth.php`

```php
final class Auth
{
    public static function startSession(): void;
    // session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax','path'=>APP_BASE])

    public static function login(string $password): array;   // 成功返回 ['csrf_token'=>..]；失败抛 RATE_LIMITED / 通用错误文案
    public static function logout(): void;
    public static function isLoggedIn(): bool;
    public static function requireLogin(): void;             // 未登录 → 401 UNAUTHORIZED
    public static function csrfToken(): string;              // 登录成功时生成，存 Session
    public static function verifyCsrf(): void;               // 头 X-CSRF-Token，hash_equals → 403 CSRF_INVALID
    public static function rateLimit(string $ipHash): void;  // 15 分钟失败 ≥5 → 429（§9.2）
}
```

**【约束】** 登录成功后必须 `session_regenerate_id(true)`（防会话固定，§9.1）。登录接口额外固定 300ms 延时（§9.2）。密码错误不论账号是否存在，文案一致（§9.2）。

### 3.6 `Content.php` / `Tag.php` / `Asset.php`

```php
final class Content
{
    public static function list(array $f): array;          // 返回 [rows, total]；强制前台可见性条件
    public static function detailBySlug(string $slug): ?array;  // 命中 slug_redirects 时带 redirect_to
    public static function related(string $slug, int $limit = 6): array;  // 共同标签 → JOIN + GROUP BY + ORDER BY
    // 后台：
    public static function adminList(array $f): array;
    public static function save(array $in, ?int $id = null): int;   // 事务写 contents + 扩展表 + content_tags
    public static function trash(int $id): void;
    public static function restore(int $id): void;
    public static function destroy(int $id): void;         // 先 ref_count 检查，再 revoke(asset, deleteFile:true)
    public static function batch(array $ids, string $op, array $args = []): array;
    public static function reorder(array $ids): void;      // 按数组顺序写 sort_weight
}
```

**【约束】前台可见性条件是唯一一处，`list()` / `detail()` / `related()` / 归档 / 标签计数都必须拼**：

```sql
status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
```

**【约束】** 定时发布靠这个查询条件实现，**不建任务**（§3.2 张力 B，明确写了「不要改成任务驱动」）。

```php
final class Tag
{
    public static function list(?string $type = null): array;  // 含每个标签的内容计数
    public static function save(array $in, ?int $id = null): int;
    public static function rename(int $id, string $name, string $slug): void;
    public static function merge(int $fromId, int $intoId): void;  // content_tags 重指向 + 去重 + 删源标签
    public static function delete(int $id): void;
}
```

```php
final class Asset
{
    public static function list(array $f): array;                 // 支持 check_status 筛选
    public static function addExternal(string $url): int;         // 手动登记，拒绝非 https（§10.2）
    public static function upload(array $files, array $meta): array;  // 双 blob → 4 次远程调用 → 写 assets
    public static function recheck(int $id): array;
    public static function relink(array $ids): array;             // 用存量 UKEY 重跑 link_add
    public static function revoke(int $id): bool;                 // 调 adapter->revoke($asset, deleteFile:true)
    public static function delete(int $id): bool;                 // ref_count>0 拒绝；=0 先 revoke 再删记录
    public static function sync(): array;                         // list_of_direct 翻页比对，返回两份差异清单
    public static function findBySha256(string $sha): ?array;     // 上传去重
}
```

### 3.7 `Scheduler.php`（伪 cron）

**SPEC §7.1 明确警告「这是本项目最容易写崩的地方」**，实现须逐条照做。

```php
final class Scheduler
{
    public static function maybeTrigger(): void;
    // ① random_int(1,100) <= (int)Settings::get('cron_probability', 2) 才进入
    // ② ignore_user_abort(true)
    // ③ register_shutdown_function([self::class, 'releaseAllLocks'])   ← 兜底释放锁
    // ④ 若 function_exists('fastcgi_finish_request')：
    //      先 flush 响应 → fastcgi_finish_request() → 客户端断开，用户零感知
    //      然后 set_time_limit(0)
    //    否则（本地 Apache mod_php，§11.1）：走 5 秒预算分支
    // ⑤ self::runDue()  ← 全程 try/catch，异常只写日志，绝不向上抛

    public static function runDue(): void;
    public static function runOne(string $taskKey): array;    // admin.task.run 手动触发
    public static function releaseAllLocks(): void;
}
```

**【约束】取锁用「影响行数判断」，不用 `SELECT ... FOR UPDATE SKIP LOCKED`**（MySQL 5.6 不支持，§2.2、§7.1）：

```sql
UPDATE tasks SET locked_at = NOW(), lock_owner = ?
 WHERE task_key = ?
   AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL 60 SECOND);
-- affected_rows = 0 → 跳过（别人在跑）
```

**【约束】预算控制靠分批，不靠超时**：PHP `max_execution_time` 可被 `set_time_limit(0)` 解除，但 PHP-FPM 的 `request_terminate_timeout` 无法从脚本内解除 → **单次任务总墙钟硬性 < 20 秒**，每处理一条检查是否已用掉 12 秒预算，用尽则保存 cursor 立即退出（§7.1）。

**任务清单（§7.1，逐条实现）**：

| task_key | 周期 | 单批 | 实现要点 |
|---|---|---|---|
| `asset_check` | 12h | 20 | 优先 `list_of_direct` 翻页集合比对（一次请求胜过 N 次探测）；失败降级逐条 `HEAD`（**T3 已确认无防盗链，不需要 Range 兼容层**）；IFrame 视频不巡检 |
| `expires_watch` | 1d | 200 | 查 `storage_model != 99`，`expires_at` 已过 → 标 `expired` + 后台告警（**保险丝**） |
| `view_aggregate` | 1h | 500 | `view_seen` → `view_daily` |
| `view_gc` | 1d | 1000 | 删 7 天前 `view_seen` |
| `cache_gc` | 1d | — | 删过期缓存（含 `cache_version` 产生的孤儿） |
| `backup_remind` | 1d | — | 距上次导出天数 → 写 settings |
| `login_gc` | 1d | — | 删 7 天前 `login_attempts` |
| `trash_gc` | 1d | — | 回收站满 30 天 → 标「待彻底删除」（**只提醒，不自动删**） |

### 3.8 `Cache.php`

```php
final class Cache
{
    public static function remember(string $action, array $params, callable $fn);
    // 存储：data/cache/<sha1(key)>.<cache_version>.json
    // key：cache_version | action | 排序后的参数
    // TTL：默认 300s，读时比对 mtime
    // 关闭时不生效（settings.cache_enabled=0，默认，§7.4）

    public static function forgetAll(): void;   // 由 bumpCacheVersion 替代，不做逐个删除
    public static function gc(): void;
}
```

**【约束】** 任何内容写入时 `cache_version` 自增，旧缓存自然变孤儿，由 `cache_gc` 清理；**不做逐个删除，避免失效逻辑出错**（§7.4）。不缓存详情页（浏览量实时）与后台接口。

### 3.9 `Settings.php` / `Markdown.php` / `Str.php`

```php
final class Settings
{
    public static function get(string $key, $default = null);
    public static function set(string $key, $value): void;
    public static function all(): array;              // 供 site.meta / admin.setting.get
    public static function bumpCacheVersion(): void;  // cache_version++
}
```

**【约束】** 配置项 key 清单见 SPEC 附录 B，实现时不得增删改名。`TTTTT_API_KEY` / `IP_SALT` / `DB_*` / `APP_BASE` / `APP_DEBUG` / `ADMIN_ENTRY` 只在 `config.php`，**不入 `settings` 表**（§7.2.2：该表会返回给前端）。

```php
final class Markdown
{
    public static function toText(string $md): string;    // 剥离标记，供 body_text 搜索与摘要
    public static function excerpt(string $text, int $len = 120): string;
}
// 【约束】仅服务端用于 RSS 输出与纯文本摘要。前台渲染由 marked + DOMPurify 在浏览器完成（§D11）。
// 【约束】禁止把 Markdown 解析结果直接当 HTML 输出 —— 内联 HTML 有 XSS 风险（§R13）。
```

```php
final class Str
{
    public static function slug(string $input, string $fallback = 'post'): string;
    // 白名单 /^[a-z0-9]+(-[a-z0-9]+)*$/，长度 ≤ 191（§9.5）
    public static function uniqueSlug(string $base, ?int $excludeId = null): string;  // 冲突 → -2 / -3
    public static function e(?string $s): string;         // htmlspecialchars
    public static function siteUrl(string $path): string; // APP_BASE + path
}
```

### 3.10 图床适配器 `Storage/`

接口按 SPEC §7.2.1 **原样冻结**，不增删方法。

#### `TttttAdapter` —— 关键实现

**【决策】四步流程封装为一个私有方法 `uploadAndLink()`**：

```php
private function uploadAndLink(string $tmpPath, string $remoteName, string $mime): array;
// ① POST https://tmp-cli.vx-cdn.com/app/upload_cli   (multipart: key/file/model=99)
//    ↑ 不发送 mrid（T9 已证伪，§7.2.2）
// ② POST https://tmp-api.vx-cdn.com/services/direct  (action=link_add, key, ukey, valid_time=0, download_limit=0)
// 返回 ['ukey'=>.., 'dkey'=>.., 'name'=>.., 'direct_url'=>.., 'model'=>99]
```

**三个必须留意的解析细节（§7.2.2，写错会「上传成功但图片全是占位图」，极难排查）**：

1. **`data` 是数组** → 取 `data[0]['dkey']` / `data[0]['name']`。写成 `$r['data']['dkey']` 会得 `null` 且**不报错**。
2. **字段名是 `name`，不是 `filename`**；表列名 `remote_name`。
3. 响应含 `debug` 字段 → **生产不回显给前端，只写日志**。

**【约束】拼接模板**：`https://download.wuyuge.cn/files/{dkey}/{name}`，写入 `settings.ttttt_direct_template`，拼接结果**物化**存 `assets.direct_url` / `thumb_direct_url`。

**【约束】`model` 写死 `MODEL_PERMANENT = 99`**，**不在后台 UI 暴露为可选项**；上传后写 `storage_model=99, expires_at=NULL`。

**【约束】`revoke()` 内部写死 `delete=1`**，不作为调用方可选项 —— 漏传会「链接失效但空间永久占位」，是「本模块最隐蔽的坑」（§7.2.3、§10.2）：

```php
public function revoke(array $asset, bool $deleteFile = false): bool;
// 【实现注意】本适配器的 supportsHardDelete() 为 true，内部固定带 delete=1。
//   参见 §7.2.3「唯一的下限陷阱」。
```

**【约束】`listLinks()` 必须循环翻页**，直到某页返回空 —— 只取第一页会产生大量「远端缺失」假阳性（§10.2、§7.2.2）。返回项自带 `link` 字段（完整直链），可直接与库中 `direct_url` 比对。

**status 码映射（§7.2.2 全表，逐条实现）**

上传 `status`：

| status | 行为 |
|---|---|
| `1` | 取 `data` 作 UKEY 写 `assets.rel_path` |
| `0` | 抛 `STORAGE_ERROR` + 记完整请求参数（去 key）与原始响应（**视为代码缺陷**） |
| `2` | 抛 `UPLOAD_TOO_LARGE`，提示压缩后重试（**T2 未触顶，实际闸门是 PHP 的 100M**） |
| `3` | 抛 `STORAGE_BUSY`，退避重试 2 次（1s / 3s）；**不写错误日志**（对方问题） |
| `4` | 抛 `STORAGE_FULL`，后台红色告警 + 引导「孤立资源」清理 |
| `5` | 抛 `STORAGE_QUOTA`，告警文案**必须写明「按天重置，次日自动恢复」**（T9 确认无绕开手段） |
| `6` | 抛 `STORAGE_AUTH`，提示去设置页检查 Key（**日志不回显 Key**） |
| 其他 | `STORAGE_ERROR`，记原始响应**前 500 字节** |

`link_add`：`1` 成功；`0` 抛 `STORAGE_ERROR`；`1001` 文件不存在 → 抛错并告警（严重异常）；`1002` 内部错误 → 重试 2 次后抛 `STORAGE_BUSY`；`1005` 尚未就绪 → **退避 1s/2s/4s 重试 3 次**，仍失败抛 `STORAGE_BUSY` 但**保留已上传 UKEY**（下次只需补建直链，不重传）。

`link_del`：`1` 成功；`0` 写日志 + 进「待清理」，**不阻塞删除流程**；`1003` 直链不存在 → **视为成功（幂等）**。

`list_of_direct`：`1` 累积本页 `data[]` 按 `page` 翻页；其他 → 降级逐条 `HEAD`。

**cURL 配置（§7.2.2，受 30s 制约）**：

```php
CURLOPT_TIMEOUT        => 8,     // 单次 8 秒；4 次调用 + 重试累计须留在 25 秒内
CURLOPT_CONNECTTIMEOUT => 5,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_SSL_VERIFYPEER => true,  // 【约束】不得因任何原因关闭
CURLOPT_POSTFIELDS     => ['key' => TTTTT_API_KEY, 'file' => new CURLFile(...), 'model' => '99'],
```

**【约束】** 每次远程调用前比对剩余预算，不足则立即中止并返回已完成进度（§10.2「上传总耗时接近 30 秒」）。

#### `ManualAdapter`（§7.2.4）

- `supportsUpload()` → `false`；`supportsRevoke()` → `false`；`supportsTransform()` → `false`
- `stripsExif()` → `false` → **后台登记表单下方必须显示隐私提示**（§9.6 例外条款）
- `listLinks()` → `null`；`probe()` 正常实现（外链也能巡检）

#### URL 解析（§7.2.5，四层回退，实现原样照抄）

```php
function asset_url(array $asset, string $size = 'full'): ?string
// ① 手动外链（^https?://）→ 原样返回
// ② 已物化 direct_url → 直接用（正常路径，零拼接开销）
// ③ 用 dkey + name 按 ttttt_direct_template 现场重建（模板变更 / 域名变更时自愈）
// ④ 兜不住 → null，前台走占位图，绝不拼出半截 URL

function thumb_url(array $asset): ?string
// asset_url(thumb) ?? asset_url(full)；无缩略版时降级原图，后台标记「流量风险」
```

**【约束】`cdn_base` 用途已收窄**：仅供 `normalize_asset_key()` 判断 URL 是否属于本图床，**不再参与 URL 构造**（§7.2.5）。

### 3.11 action 清单

**必须与 SPEC §6.2 / §6.3 表格逐行一致**。实现时以表格为 checklist：

- 公开（§6.2）：`content.list` / `content.detail` / `content.related` / `tag.list` / `archive.list` / `site.home` / `site.meta` / `view.hit` / `search.suggest`
- 后台（§6.3）：`auth.login` / `auth.logout` / `auth.check`；`admin.content.*`（list/get/save/trash/restore/destroy/batch/reorder）；`admin.tag.*`；`admin.asset.*`（list/add/upload/recheck/relink/revoke/delete/sync）；`admin.home.get` / `admin.home.save`；`admin.setting.get` / `admin.setting.save`；`admin.task.list` / `admin.task.run`；`admin.export.json` / `admin.export.sql`；`admin.sysinfo` / `admin.log.list`

---

## 4. 数据库落地

### 4.1 `app/schema.sql` 组织

单一文件，按 SPEC §5 顺序建表，末尾附附录 A 的 `comments` **以注释形式保留**（第一版不执行，§5.6）：

```
SET NAMES utf8mb4;
-- 1. contents                  (§5.1)
-- 2. article_meta / video_meta / image_meta   (§5.2)
-- 3. tags / content_tags       (§5.3)
-- 4. assets                    (§5.4)
-- 5. settings / tasks / login_attempts / view_seen / view_daily / slug_redirects / home_blocks  (§5.5)
-- 6. -- [附录 A] comments 表（第一版不建，注释保留）
-- 7. INSERT 默认 settings 行（附录 B 的默认值）
```

### 4.2 【约束】MySQL 5.6 检查清单（§2.2 逐条对应）

写任何建表/查询语句前对照：

| 限制 | 落地动作 |
|---|---|
| 索引前缀 ≤ 767 字节（utf8mb4 → 191 字符） | 所有可索引文本列 ≤ `VARCHAR(191)`；`rel_path` 索引用 `rel_path(191)`；`title(64)`、`body_text(191)` |
| 无 JSON 列 | `home_blocks.config` / `settings.value` 用 `TEXT` 存 JSON 字符串，PHP 侧 `json_encode/decode` |
| 无降序索引（`DESC` 被静默忽略） | **索引定义不写 `DESC`**；`ORDER BY ... DESC` 仍可走反向扫描 |
| 无 CTE / 窗口函数 | 相关推荐、排行榜用普通 `JOIN` + `GROUP BY` + `ORDER BY` |
| 无 `SKIP LOCKED` | 伪 cron 用 `UPDATE ... WHERE` 影响行数判断（见 TP §3.7） |
| 无 `utf8mb4_0900_ai_ci` | 统一 `utf8mb4_unicode_ci` |
| `DATETIME` 默认值受限 | `created_at` / `updated_at` 由 PHP 显式写 |
| 默认 sql_mode 宽松 | 不利用，`GROUP BY` 写全非聚合列 |
| EOL 无补丁 | 数据库不对外；全 PDO 预处理；严格入参校验；定期导出（R16） |

**【决策】迁移友好性验收**：所有 SQL 须同时跑在 MySQL 5.6 与 8.0 上（§2.2）。本地 XAMPP 若为 8.0，需另在 5.6 环境（或线上 `/dev/` 目录）跑一遍建表与关键查询。

### 4.3 字段名易错点（SPEC 修订记录 v1.4 明确纠正过）

| 正确 | 错误写法 | 出处 |
|---|---|---|
| `assets.remote_name` | ~~`remote_filename`~~ | §5.4、v1.4 |
| 接口字段 `name` | ~~`filename`~~ | §7.2.2 |
| action `link_del` | ~~`link_delete`~~ | §7.2.2 |
| 模板占位 `{name}` | ~~`{filename}`~~ | 附录 B |
| `assets.thumb_rel_path` | — | §5.4 |
| 直链模板 key `ttttt_direct_template` | — | 附录 B |

---

## 5. 前端实现（Vue 3 + Vite）

### 5.1 技术栈与依赖（§8.1）

```
vue@3 (Composition API)   vue-router@4 (createWebHashHistory)   ← 强制 hash 路由
marked                    DOMPurify                              ← 顺序不可颠倒
@vueuse/core (可选)
```
**【约束】不引入 UI 框架**，手写 CSS + CSS 变量（§8.1）。

**【决策】不引入 `vuedraggable`**，拖拽用手写 HTML5 DnD（契合零依赖精神，且 DnD 需求简单：列表排序 + 首页区块排序）。

**【决策】T5 代码高亮默认不做**（不引 highlight.js，省约 30KB）；在 `MarkdownView.vue` 预留 `v-if="settings.highlight"` 分支，M5 时按需再定（§14.3）。

### 5.2 目录结构

```
src/
├── main.js                  ← 公共 SPA 入口
├── panel.js                 ← 后台 SPA 入口
├── App.vue                  ← 公共根：导航 + <router-view> + 页脚
├── PanelApp.vue             ← 后台根：登录守卫 + 侧边栏 + 内容区
├── router/
│   ├── index.js             ← 公共路由表（TP §5.4）
│   └── panel.js             ← 后台路由表
├── views/                   ← 页面级组件（见 §5.3）
├── components/              ← 可复用组件（见 §5.5）
├── lib/
│   ├── api.js               ← 统一请求封装（自动带 CSRF、错误归一化）
│   ├── image.js             ← 双尺寸 Canvas 处理（TP §5.6）
│   ├── markdown.js          ← marked + DOMPurify 管道
│   ├── theme.js             ← 暗色模式
│   └── store.js             ← 轻量响应式 store【决策】
├── styles/
│   ├── reset.css  variables.css  main.css
└── index.html  panel.html   ← Vite 源模板（base './'）
```

### 5.3 【决策】状态管理方案

SPEC 未指定。方案：**不引入 Pinia**，用组合式函数 + 一个 `reactive` 单例 store：

```js
// lib/store.js
export const store = reactive({
  site: {},          // site.meta 结果
  theme: 'auto',
  scrollPositions: {},   // 列表返回时恢复滚动位置（§8.3）
});
```

理由：站点状态极简（站点信息 + 主题 + 几个 UI 开关），Pinia 属于过度设计。若后台状态变复杂（M9 拖拽编辑 + 多表筛选），再局部升级为 Pinia，不影响公共区。

### 5.4 路由表（§8.2，原样实现）

Hash 路由，格式 `#/<类型>/<slug>`：

| 路由 | 视图组件 |
|---|---|
| `#/` | `HomeView.vue`（定制布局） |
| `#/post/:slug` | `PostView.vue` |
| `#/video/:slug` | `VideoView.vue` |
| `#/photo/:slug` | `PhotoView.vue` |
| `#/articles` `#/videos` `#/photos` | `ListByTypeView.vue`（复用，`props.type`） |
| `#/tag/:slug` | `TagView.vue` |
| `#/archive` | `ArchiveView.vue` |
| `#/search` | `SearchView.vue` |
| `#/about` `#/links` | `StaticPageView.vue`（复用 Markdown 渲染） |
| `#{adminEntry}` | 后台（`fn adminEntry()`）：公共 SPA **不**处理，实际入口是 `panel-<随机>.php` |
| `#/:pathMatch(.*)*` | `NotFoundView.vue` |

### 5.5 关键组件与交互（§8.3 逐条）

| 组件 | 职责 | 约束 |
|---|---|---|
| `PostCard.vue` | 列表卡片 | 图片 `loading="lazy"` + 显式 `width/height` + `object-fit:cover` |
| `SmartImage.vue` | 图片封装 | `@error` 回退占位图并显示 `alt_text`；用 `thumb_url` 逻辑取缩略版 |
| `VideoPlayer.vue` | embed/mp4 两态 | embed：**懒加载，点击后才注入 iframe**；`aspect-ratio` 占位；`referrerpolicy="no-referrer"` + `allowfullscreen`；YouTube 显示「需科学上网」提示。mp4：`preload="none"` + 封面 |
| `MarkdownView.vue` | 正文渲染 | `marked` → `DOMPurify.sanitize` → `v-html`，**顺序不可颠倒**（§9.5） |
| `SkeletonList.vue` | 骨架屏 | 列表加载用骨架屏，不用全屏 loading |
| `LoadMore.vue` | 无限滚动 | IntersectionObserver + 「加载更多」按钮兜底 |
| `CommentArea.vue` | 评论占位 | 由 `settings.comments_enabled` 控制不渲染（§5.6） |
| `TagCloud.vue` / `ArchiveTimeline.vue` | 标签云 / 归档 | 归档按年月分组 |
| `ErrorState.vue` | 错误态 | 接口失败显示可重试，不白屏 |

**【决策】滚动位置恢复**：存入 `store.scrollPositions[route.fullPath]`，返回列表时 `restore`（§8.3「滚动位置在返回列表时恢复」）。

**后台组件**：`ContentEditor.vue`（左 Markdown 源 / 右实时预览 + 工具栏插入图片）、`MediaGrid.vue`（网格 + 筛选失效项 + 「待清理」/「孤立资源」/「同步检查」/「批量重建直链」四个入口）、`TagManager.vue`、`HomeLayoutEditor.vue`（区块增删 + DnD 排序 + 每块参数）、`SettingsForm.vue`、`ExportPanel.vue`、`Dashboard.vue`（环境探测 + 备份提醒 + 任务状态）。

### 5.6 【约束】双尺寸图片处理（§3.2 张力 C，强制）

SPEC 称这是「本项目最尖锐的一处约束组合」：无 GD + 图床无缩略能力 + 流量成本 → **必须在浏览器端生成两个尺寸并分别上传**。

```js
// lib/image.js
/**
 * 把用户选择的图片处理成缩略版 + 原图版两个 Blob。
 * Canvas 重绘顺带剥离全部 EXIF（含 GPS）—— 钛盘 stripsExif() 为 false，故此步为必需而非优化。
 * @returns {{thumb: Blob, full: Blob, thumbW, thumbH, fullW, fullH, mime}}
 */
export async function makeTwoSizes(file, { thumbEdge = 800, fullEdge = 2560 } = {}) {
  // ① createImageBitmap(file)（或 <img> + objectURL）
  // ② 分别按长边缩放：800px / 2560px（不放大，保持宽高比）
  // ③ OffscreenCanvas/Canvas 重绘两次 → toBlob('image/webp', 0.80 / 0.85)
  // ④ WebP 不支持（toBlob 回调得 null）→ 回退 'image/jpeg'
  // ⑤ 返回两个 Blob 与各自宽高
}
```

| 版本 | 长边 | 格式/质量 | 用途 |
|---|---|---|---|
| `thumb` | 800px | WebP q=0.80 | 列表、卡片、相关推荐、OG 图 |
| `full` | 2560px | WebP q=0.85 | 详情页大图 |

**【约束】两个 blobs 同传，服务端返回两个 UKEY，记在**同一条 `assets`**（`rel_path` + `thumb_rel_path`）。不允许出现「只有缩略图没有原图」的记录（§10.2）。

**【约束】上传体验**：显示进度与「正在处理图片…」状态；前端 25 秒主动 abort；第二张失败不写库（§10.2、R17）。

### 5.7 API 封装

```js
// lib/api.js
export async function api(action, params = {}, { method = 'GET', body = null } = {}) {
  // 统一拼 ./api.php?action=<action>（相对路径，§4.2）
  // POST 自动加 header X-CSRF-Token: store.csrf
  // 解析 JSON；解析失败 → 提示并标记为缺陷（§10.4「API 返回非 JSON」）
  // 非 2xx → 抛 {code, message, field}，交由调用方展示
}
```

### 5.8 Vite 构建配置

**【决策】多页构建（公共 SPA + 后台 SPA 分离）**：

```js
// vite.config.js
export default defineConfig({
  base: './',                                   // 【约束】相对路径，子目录部署（§4.2）
  build: {
    rollupOptions: {
      input: {
        main:  resolve(__dirname, 'src/index.html'),    // → dist/index.html
        panel: resolve(__dirname, 'src/panel.html'),    // → dist/panel.html
      },
    },
  },
  server: {
    proxy: { '/api.php': 'http://localhost/blog' },      // 本地开发代理（§11.1）
  },
});
```

**后台入口随机化的落地（§9.1）**：

- `panel.html` 是构建产物外壳（与 `index.html` 同理）。
- `panel-template.php` 内容：守卫（`if (!defined('APP_BOOT')) 404`）→ `readfile(__DIR__ . '/panel.html')`。
- `install.php` 生成 8 位随机串，把 `panel-template.php` **重命名**为 `panel-<随机>.php`，随机串写入 `config.php` 的 `ADMIN_ENTRY`。
- 访问 `panel.php` / `admin.php` → 404（文件不存在即为 404）。

### 5.9 暗色模式与移动端（§8.3）

- 暗色：CSS 变量 + `prefers-color-scheme` 自动 + 手动切换存 `localStorage`。
- 移动端：断点 768px；导航折叠为抽屉；视频 iframe 全宽。
- 首屏：`site.home` 一次返回全部区块数据，**避免瀑布式请求**（§8.3）。

---

## 6. 安全落地（§9 逐条对应）

| 规范项 | 实现位置 | 要点 |
|---|---|---|
| 认证 §9.1 | `Auth.php` | `password_hash`/`password_verify`；单账号存 `settings`（`admin_user`/`admin_pass_hash`）；`session_regenerate_id(true)`；不提供找回密码 |
| 后台入口随机化 §9.1 | `install.php` | 8 位随机串 → 重命名 `panel-template.php`；串存 `config.php`（**不入库**，避免设置页泄露） |
| 限流 §9.2 | `Auth::rateLimit` | `login_attempts` 按 `ip_hash`（sha256(ip+盐)）；15 分钟失败 ≥5 → 429 锁 15 分钟；额外固定 300ms 延时 |
| CSRF §9.3 | `Auth::verifyCsrf` | `csrf_token` 登录时生成存 Session；写操作带 `X-CSRF-Token`；`hash_equals`；失败 403 |
| 目录防护 §9.4 | 全体 | `data/` 数据文件用 `.php` 扩展名 + 首行 `<?php exit; ?>`；`config.php` 与 `app/*.php` 加 `APP_BOOT` 守卫；`data/` `app/` 放空 `index.html`；`uploads/tmp/` 随机名 + 扩展名白名单 + 用完即删；导出文件同样守卫头 |
| 输入/输出 §9.5 | 全体 | 显式校验类型/长度/枚举/必填；全 PDO 预处理；slug 白名单正则；Markdown 结果**必须过 DOMPurify** |
| EXIF §9.6 | `lib/image.js` + 后台提示 | 前端 Canvas 剥离（强制）；`ManualAdapter` 不剥离 → 后台登记表单必须提示「未经过隐私处理」 |
| 错误处理 §9.7 | `ErrorHandler` | 未登录只回中性错误；已登录管理员可见详情；日志写 `data/logs/error-YYYY-MM.log.php`（带守卫头）；强制 `display_errors=0` |
| 安全头 §9.8 | `Response::securityHeaders` | 见 TP §3.4；`img-src` 收紧为图床域名白名单（设置项 `cdn_whitelist`），手动外链域名需加入白名单 |

**DOMPurify 白名单（§9.5）**：禁止 `script` / `iframe` / `on*` / `style` 中的 `expression`；允许 `img a pre code table h1-h6 ul ol blockquote hr br strong em del input[type=checkbox]`。

---

## 7. 部署与安装

### 7.1 `config.sample.php` → `config.php`

```php
<?php if (!defined('APP_BOOT')) { http_response_code(404); exit; }   // 首行守卫（§9.4）

define('DB_HOST', '');  define('DB_NAME', '');
define('DB_USER', '');  define('DB_PASS', '');
define('APP_BASE',  '/blog');        // install 自动探测写入（§4.2）
define('APP_DEBUG', false);          // 【约束】生产必须 false
define('ADMIN_ENTRY', '');           // install 生成的 8 位随机串
define('TTTTT_API_KEY', '');         // 【约束】必须在此，不入 settings 表（R7）
define('IP_SALT', '');               // 浏览去重与登录限流的哈希盐
```

### 7.2 `install.php` 向导（§11.3）

1. 环境探测（展示 §2.1 实测值并与文档核对）
2. 填数据库信息 → 建表（执行 `app/schema.sql`）
3. 设管理员密码 / 站点名
4. 填钛盘 API Key、`cdn_base`、`ttttt_direct_template`（**可跳过**，跳过时上传功能不可用并给出提示）
5. 自动探测并写入 `APP_BASE`
6. 生成后台入口随机名（重命名 `panel-template.php`）
7. **提示删除 `install.php`**
8. 已安装（`settings` 表存在）时自我禁用

### 7.3 部署前检查清单（§11.2，强制）

- [ ] `php -l` 检查所有改动的 PHP 文件
- [ ] 本地 XAMPP 打开被改动页面，无报错无警告
- [ ] 改了数据库结构 → 本地先跑一遍完整建表 SQL
- [ ] 先传 `/dev/` 子目录验证，再覆盖主目录
- [ ] 涉及 `config.php` 时确认没把本地配置传上去
- [ ] 确认 `APP_DEBUG === false`
- [ ] 前端确认 `npm run build` 产物已生成且 `base` 为 `./`

### 7.4 本地开发差异（§11.1，务必注意）

- 本地 Apache **有**重写，线上**没有** → 本项目**不使用任何重写**，本地保持一致；若本地偷懒开了重写，须在无重写模式下复测。
- 本地无 `fastcgi_finish_request()`（mod_php）→ 伪 cron 走 5 秒预算分支。**这是本地与线上唯一需要真机验证的差异。**

---

## 8. 实施路线图（M0–M12 产物映射）

| 阶段 | 产出文件 / 组件 | 验收（SPEC §13 细化） |
|---|---|---|
| **M0** 骨架 | `bootstrap.php` `Db.php` `Router.php` `Response.php` `ErrorHandler` `config.sample.php` | `api.php?action=site.meta` 返回合法 JSON；安全头齐全 |
| **M1** 安装 | `install.php` `app/schema.sql` `Settings.php` | 本地全新库一键装完；环境探测值与 §2.1 一致 |
| **M2** 认证 | `Auth.php` `panel-template.php` | 失败 5 次被锁 15 分钟；无 CSRF 写请求 403；`/admin.php` 404 |
| **M3** 内容 | `Content.php` `Str.php` `actions/admin.php`（content.*） | 三类内容增删改查可用；回收站可恢复；slug 冲突返回 `SLUG_TAKEN` |
| **M4** 标签 | `Tag.php` `content_tags` 逻辑 | 标签增删改合并；相关推荐合理 |
| **M5** 前台骨架 | `src/` 全套骨架 + `router/index.js` + 列表/详情 + `MarkdownView.vue` | 三类内容正常浏览；Markdown 渲染且过 DOMPurify |
| **M6** 检索 | `ListByTypeView` `TagView` `ArchiveView` `SearchView` + `LoadMore` | 组合筛选正确；无限滚动 + 滚动恢复 |
| **M6.5** | ~~钛盘确认~~ | ✅ 已完成，**从计划移除，直接进 M7**（§13） |
| **M7** 媒体库 | `Asset.php` `Storage/*` `lib/image.js` `MediaGrid.vue` | 双版本各自建直链成功；`dkey`/`remote_name`/`direct_url` 正确入库；**彻底删除时 `link_del` 带上 `delete=1`** 且直链失效；`1003` 不报错；`listLinks()` 能取第二页；4 次调用实测 < 20 秒 |
| **M8** 伪 cron | `Scheduler.php` `Cache.php` + `tasks` 表 | 锁与分批正确；任务异常不影响主流程 |
| **M9** 首页布局 | `HomeLayoutEditor.vue` + `home_blocks` 逻辑 | 拖拽结果持久化；首页正确渲染 |
| **M10** 分享与适配 | `share.php` `feed.php` `NotFoundView` + 暗色 + 移动端 | 微信分享有卡片（`curl -A "MicroMessenger"` 能拿到 meta）；手机端可用 |
| **M11** 备份 | `ExportPanel.vue` + `admin.export.*` + `admin.sysinfo` + `admin.log.list` | 能导出可用 SQL 并在本地恢复 |
| **M12** 上线 | 部署 + SPEC §13.1 全量验收 | 见 SPEC §13.1 |

### 8.1 上线验收补充（SPEC §13.1 中与图床强相关的项）

这些项**必须逐条手动验证**，是 M7 的核心验收：

- [ ] 上传一张图，Network 面板核对**列表页加载的是缩略版**（文件大小明显更小）
- [ ] 上传后 `assets.remote_dkey` / `remote_name` / `direct_url` 三者均已正确写入
- [ ] 彻底删除一篇带图内容后，原图与缩略图直链**均已失效**
- [ ] **删除前后在钛盘网页端核对已用空间确实下降** —— 证明 `delete=1` 生效（T14 操作性验收）
- [ ] `list_of_direct` 返回的直链集合与库中记录一致（无「库有远端无」缺失项）
- [ ] 数据库中不存在 `storage_model != 99` 的资产
- [ ] 用**真实域名**（非 localhost）访问，所有图片正常显示

---

## 9. 开放决策项的默认取值

SPEC §14.3 的待定项，方案给出默认值，编码时按此执行，需要变更时改这一处即可。

| # | 事项 | 默认取值 | 理由 / 决策时点 |
|---|---|---|---|
| **T4** | 空间总容量与月流量额度 | 安装时手动填写，仅用于后台展示 | M1 时填 |
| **T5** | 代码块语法高亮 | **关闭**，不引 highlight.js（省 ~30KB），`MarkdownView.vue` 留开关 | §14.3，M5 可再定 |
| **T6** | 是否配 `/dev/` 开发子目录 | **建议配置**（降低 R2 上线白屏风险） | M12 前 |
| **T7** | 站点名 / 域名 / 备案号 | 安装向导填写，`site_icp` 留空则不展示 | M1 时填 |
| **T8** | 是否启用评论 | **不启用**（`comments_enabled=0`），`comments` 表冻结不建 | 附录 A |
| **T10** | 是否保留双尺寸方案 | **保留**（SPEC 强推，§3.2 张力 C） | M7 按实测体验再定；放弃则 R5 等级上升 |
| **T13** | 钛盘配额总额与计费 | 未知，M7 前实测；仅影响 R18 严重程度 | M7 前 |

### 9.1 本文档新增的决策汇总

| 决策点 | 取值 | 对应章节 |
|---|---|---|
| 前端状态管理 | 不引 Pinia，用 `reactive` 单例 + composables | TP §5.3 |
| 拖拽排序 | 手写 HTML5 DnD，不引 vuedraggable | TP §5.1 |
| Vite 构建 | 多页：`index.html` + `panel.html`，`base:'./'` | TP §5.8 |
| 后台入口 | `panel-template.php` → `readfile(panel.html)`，install 重命名 | TP §5.8 |
| 双尺寸处理 | `lib/image.js` Canvas 重绘两次，WebP 回退 JPEG | TP §5.6 |
| 类方法签名 | 见 TP §3 各节 | TP §3 |
| 站点代码落位 | `ailearning/blog/`，`src/` 不部署 | TP §2.3 |

---

## 10. 风险与本方案的应对

SPEC §12 的风险登记表已完整。本方案需要额外强调的三条：

| 风险 | 本方案的应对 |
|---|---|
| **R2 线上改代码白屏** | 严格的 M0–M12 阶段化 + §7.3 上传检查清单 + 建议配 `/dev/` 目录（T6） |
| **R14/R16 MySQL 5.6 兼容** | TP §4.2 逐条检查清单；schema.sql 全字段 ≤191 可索引；不依赖 DB 默认时间值 |
| **R17 双尺寸上传耗时翻倍** | `lib/image.js` 处理异步化 + 进度 UI + 25 秒 abort；后端每次远程调用前比对剩余预算；第二张失败不写库 |

---

## 附录：编码时的十条「不可忘」

1. `link_del` 必须带 `delete=1`（写死在 `revoke()` 内）—— 漏传会「链接失效但空间永久占位」。
2. `link_add` 返回的 `data` 是**数组**，取 `data[0].dkey` / `data[0].name`；字段是 `name` 不是 `filename`。
3. `model` 恒为 `99`，不暴露为后台选项。
4. `listLinks()` 必须**循环翻页**到空页，否则大量假阳性。
5. 前台可见性条件唯一：`status='published' AND published_at <= NOW()`，所有公开查询必拼。
6. 定时发布靠查询条件，**不建任务**。
7. 伪 cron 取锁用 `UPDATE...WHERE` 影响行数，**不用 `SKIP LOCKED`**；单次墙钟 < 20s。
8. 索引前缀 ≤ 191 字符（utf8mb4 + 767 字节上限）；不写 `DESC`。
9. `TTTTT_API_KEY` / `IP_SALT` 只在 `config.php`，**不入 `settings` 表**。
10. Markdown 渲染链**顺序固定**：`marked` → `DOMPurify.sanitize` → `v-html`。
