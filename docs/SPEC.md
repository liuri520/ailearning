# SPEC.md — 个人博客站点技术规范

> **Written for:** 本项目的开发者（你自己），以及后续接手此代码库的协作者 / AI 助手。
> 本文档是实现的唯一事实来源。任何与本文档冲突的实现都视为缺陷，除非先修订本文档。

- **版本**：v1.4
- **定稿日期**：2026-10-06
- **项目状态**：待开发（**环境与图床全部确认完毕，M0 可开始**）

### 修订记录

| 版本 | 日期 | 变更 |
|---|---|---|
| v1.0 | 2026-10-05 | 初版：完成需求访谈与全部架构决策 |
| v1.1 | 2026-10-06 | 填入实测环境参数（MySQL 5.6.51 / 100M / 30s / fastcgi 可用）；图床确定为钛盘并重写 §7.2；新增 §2.2 MySQL 5.6 硬性限制；**删除所有索引定义中的 `DESC`**；`assets` 表新增缩略图与过期字段；重写伪 cron 预算规则；新增风险 R16–R18 |
| v1.2 | 2026-10-06 | 发现钛盘需两次 API 调用（upload_cli → link_add）才能得到直链，§7.2.2 重写；单次 cURL 超时从 25s 收紧到 8s；URL 解析改为三层回退；新增阶段 M6.5 |
| v1.3 | 2026-10-06 | 钛盘联调验证通过，方案冻结。确认 Key 参数名为 `key`；`link_add` 返回 `dkey` + 名称；确认直链支持删除与列表 |
| v1.4 | 2026-10-06 | **钛盘四个接口全部确认，规格冻结**。修正三处错误：接口名为 **`link_del`**（非 `link_delete`）、返回字段为 **`name`**（非 `filename`）、**`link_del` 的 `delete` 参数可连带删除文件本体**；直链模板确认为 `https://download.wuyuge.cn/files/{dkey}/{name}`；`list_of_direct` **分页**返回且带 `link` 字段；T2 / T3 / T9 / T14 全部关闭，R4 撤销，R19 从「中」降为「低」；`assets` 列名 `remote_filename` → `remote_name` |

---

## 1. 项目概述

### 1.1 目标

构建一个部署在**共享 PHP 主机**上的个人博客站点，包含：

- **文章**列表与详情（Markdown 正文）
- **视频**列表与详情（外链嵌入 / 外链直链，不占本机带宽）
- **图片**列表与详情（第三方图床，本机零存储）
- **管理后台**（内容 CRUD、媒体库、标签、批量操作、拖拽排序、导出备份）
- 首页**定制布局**（可拖拽调整区块顺序）
- 标签页、归档时间线、关于我、友链、RSS/JSON Feed、404/空状态页

### 1.2 非目标（Non-Goals）

明确**不做**的事，避免范围蔓延：

| 不做 | 原因 |
|---|---|
| 服务端渲染 / 预渲染 / SSR | 已决策牺牲 SEO（见 §3.1） |
| 评论系统（第一版） | 已决策推迟；预留表结构与接口 |
| 多用户 / 角色权限 | 仅单一管理员 |
| 本站视频转码 / 抽帧 | 无 FFmpeg，空间算力不足 |
| 邮件通知 / 邮件验证码 | 共享主机发信不可靠 |
| 服务端图片处理（缩略/裁剪/水印） | 已决策无 GD/Imagick，全部交给图床与浏览器 |
| 站内全文检索（FULLTEXT） | 共享主机无法启用中文 ngram 分词 |
| 自动备份 | 无 cron；改为「到期提醒 + 手动导出」 |
| 小程序 / App | 但 API 设计为其预留（见 §6） |

---

## 2. 运行环境与硬约束

**这些约束是设计的起点，不是可以商量的细节。**

### 2.1 已实测确认的环境参数

**以下为 2026-10-05 实测确认值，不再是假设。**

| 项目 | 状态 | 对设计的影响 |
|---|---|---|
| PHP | 8.1 | 可用 `match`、构造器属性提升、`readonly`、枚举 |
| `upload_max_filesize` | **100M** | 充裕。中转上传单文件上限按 100M 设计，但前端仍按压缩目标（约 1–3MB）上传 |
| `max_execution_time` | **30s** | **硬约束**：伪 cron 单批预算必须远小于 30s；所有 cURL 超时必须 ≤ 25s |
| `fastcgi_finish_request()` | **可用** | PHP-FPM 环境。伪 cron 可先响应后执行，用户零感知（见 §7.1） |
| MySQL | **5.6.51** | **受限严重，见 §2.2**。比预期更旧，多条设计规则因此收紧 |
| `.htaccess` 重写 | **不支持** | URL 只能 `api.php?action=xxx`、`index.html#/...`；禁止依赖伪静态 |
| Composer | **不支持** | PHP 侧零第三方库。所有代码手写 |
| cron | **不支持** | 定时能力靠「伪 cron」补偿（见 §7.1） |
| GD / Imagick | **不支持** | 不做任何服务端图像处理 |
| SSH | 假定无 | 部署靠 FTP / 面板文件管理器 |
| 部署位置 | **子目录**（如 `example.com/blog/`） | **所有路径必须相对化或走配置**，见 §4.2 |
| HTTPS | 已有 | Cookie 加 `Secure`；无混合内容问题 |
| 图床 | **钛盘 ttttt.link** | **四个接口全部验证通过，方案冻结**。支持上传 / 建直链 / 删直链（可连带删文件）/ 列直链；**无图片处理参数、无防盗链**，见 §7.2 |
| Node.js（服务器） | 不需要 | 前端本地构建后只上传 `dist/` 产物 |
| 本地开发 | XAMPP（Windows 11） | 见 §11.1 |

### 2.2 MySQL 5.6.51 的硬性限制（重要）

MySQL 5.6 已于 2021 年 2 月停止支持（EOL），且能力比 5.7 有明显缺失。以下限制**逐条对应到本项目的具体设计**：

| 限制 | 具体表现 | 本项目应对 |
|---|---|---|
| 无 JSON 列类型 | `JSON` 类型不存在（5.7+ 才有） | 差异字段用**扩展表真实列**（D13 的选择正好规避）；`home_blocks.config` / `settings.value` 用 `TEXT` 存 JSON 字符串，PHP 侧 `json_encode`/`json_decode` |
| **索引前缀上限 767 字节** | utf8mb4 下最多 191 个字符（191×4=764） | **所有可索引的文本列必须 ≤ `VARCHAR(191)`**。本规范中 `slug` / `old_slug` / `rel_path` 均取 191，正好卡在内。**新增字段时务必遵守，超长索引会直接建表失败** |
| 无真正的降序索引 | `DESC` 语法可写但被**静默忽略** | 索引定义中**不再写 `DESC`**（写了也不报错但会误导）。`ORDER BY ... DESC` 仍可走索引的反向扫描，性能不受影响 |
| 无 CTE / 窗口函数 | `WITH`、`ROW_NUMBER()` 不可用 | 排行榜、相关度排序全部用普通 `JOIN` + `GROUP BY` + `ORDER BY` 实现 |
| 无 `SKIP LOCKED` | `SELECT ... FOR UPDATE SKIP LOCKED` 不可用（8.0+） | 伪 cron 取锁已采用 **`UPDATE ... WHERE` 影响行数判断**的方案，天然兼容 5.6，无需改动 |
| 无 `utf8mb4_0900_ai_ci` | 只有 `utf8mb4_unicode_ci` | 排序规则统一用 `utf8mb4_unicode_ci` |
| 默认行格式 `COMPACT` / `Antelope` | 可能未开启 `innodb_large_prefix` | **不依赖大前缀**。若需更长索引，须确认 `innodb_large_prefix=ON` 且表 `ROW_FORMAT=DYNAMIC`，但本设计不需要 |
| `DATETIME` 默认值 | 仅 5.6.5+ 支持 `DEFAULT CURRENT_TIMESTAMP`，且有单列限制 | **不依赖数据库默认值**，`created_at` / `updated_at` 一律由 PHP 显式写入 |
| 默认 `sql_mode` 含 `NO_ENGINE_SUBSTITUTION`，不含 `ONLY_FULL_GROUP_BY` | `GROUP BY` 宽松 | **不利用这个宽松性**。所有 `GROUP BY` 查询写全非聚合列，以便未来迁移到 5.7/8.0 不被 `ONLY_FULL_GROUP_BY` 打断 |
| EOL 无安全补丁 | 已知漏洞不再修复 | 见风险 R16。缓解：数据库不对公网开放、应用层严格参数化查询、定期备份 |

**迁移友好性要求**：所有 SQL 必须能在 **MySQL 5.6 与 8.0 上同时运行**。这既是安全边际，也是日后换主机时的退路。验收方式：本地 XAMPP 若装的是 8.0，需额外在 5.6 环境（或线上 `/dev` 目录）跑一遍建表与关键查询。

### 2.3 图床确认结果（全部关闭）

2026-10-06 实测，**钛盘四个接口全部确认，规格冻结，不再更换**：

| 原编号 | 事项 | 结论 | 对设计的影响 |
|---|---|---|---|
| T1 | 直链怎么来 | ✅ `link_add` 返回 `dkey` + `name`，拼成 `https://download.wuyuge.cn/files/{dkey}/{name}` | 模板写入 `ttttt_direct_template` |
| T11 | Key 的参数名 | ✅ 确认为 **`key`**（一串密钥，非 token） | — |
| T12 | SSL 证书 | ✅ 正常，保持 `CURLOPT_SSL_VERIFYPEER = true` | — |
| **T2** | 单文件上限 | ✅ **未测出极限，大文件（长视频）也能传** | **取消了「前端压缩目标是硬约束」的假设** —— 压缩仍要做（流量考量），但不再担心被拒绝 |
| **T3** | 防盗链 / HEAD | ✅ **无防盗链** | **R4 撤销**；`asset_check` 巡检可用最简单的 `HEAD`，不必写 `Range` 降级链 |
| **T9** | `mrid` 能否绕开日配额 | ✅ **不能绕开** | 删除 `mrid` 相关的缓解设想；`status=5` 只能等次日重置 |
| **T14** | 删直链是否释放空间 | ✅ **可释放** —— `link_del` 的 `delete` 参数会连带删除文件本体 | **R19 从「中」降为「低」**；彻底删除流程必须带 `delete=1`，否则空间白占 |

**仍开放（与图床无关，不影响开工）**：钛盘账户的配额总额与计费方式（T13）—— 影响 R18 的实际严重程度。

**处理原则**：
- 后台「系统信息」页在首次登录时展示已探测的环境值。
- 前端上传组件的限制值来自服务端配置而非硬编码，便于日后调整。

---

## 3. 已确认的技术决策

### 3.1 决策总表

| # | 决策项 | 选择 | 代价（明确接受） |
|---|---|---|---|
| D1 | 技术路线 | 原生 PHP 自研，零 Composer 依赖 | 安全与轮子全部自己兜底 |
| D2 | 渲染方式 | SPA + JSON API | **牺牲 SEO** |
| D3 | 前端技术 | Vue 3 + Vite，本地构建上传 `dist/` | 每次改动需重新 build |
| D4 | 前端路由 | Hash 路由 `/#/post/slug` | 链接较丑；但无重写依赖，刷新不 404 |
| D5 | 后端 API 入口 | 单入口 `api.php?action=xxx` | URL 无 RESTful 美感 |
| D6 | 视频存储 | 混合：元数据统一，源可切换（iframe / mp4 直链） | 两套播放组件 |
| D7 | 图片存储 | 第三方图床，本机零存储 | 外部依赖 |
| D8 | 图床选型 | **钛盘 ttttt.link**（四接口验证通过，方案冻结），经由适配器接入 | **无图片处理参数**（故需双尺寸上传）；**无防盗链**；配额与容量有限 |
| D9 | 上传流程 | PHP 服务端中转上传。每张图 **4 次远程调用**（2 尺寸 × [上传 + 建直链]） | 单次 cURL 8s，受 `max_execution_time` 30s 制约 |
| D10 | 外链寻址 | `rel_path` 存 UKEY 作身份，`direct_url` 存直链；域名与拼接模板走配置 | 需四层回退（§7.2.5） |
| D11 | 编辑器 | Markdown 源存库，前端 `marked` + `DOMPurify` 渲染 | 首屏多两个 JS 库 |
| D12 | 认证 | Session + 后台入口随机化 + 登录限流 | 单账号，无 2FA |
| D13 | 数据模型 | 统一主表 `contents` + 分类型扩展表 | 代码量最大 |
| D14 | 删除语义 | 软删除 + 回收站 30 天；**彻底删除时调 `link_del` 并带 `delete=1`，同时撤销访问与释放空间** | 空间能真正释放（T14 已确认），但**传入 `delete=1` 是不可省略的**：漏传则链接失效而文件永久占位 |
| D15 | 搜索 | `LIKE %kw%` + 标签筛选组合 | 数据量大后变慢 |
| D16 | 伪 cron | 前台概率触发 + 后台登录补跑 | 时机不可控 |
| D17 | 分享卡片 | `share.php?slug=xxx` 中转页输出 OG 标签 | 多一个入口文件 |
| D18 | 图片隐私 | 上传前浏览器剥离 EXIF + 图床 Referer 白名单 | — |
| D19 | 备份 | 后台到期提醒手动导出 | 全靠自律 |
| D20 | 缓存 | 文件缓存，预留开关，默认关闭 | — |
| D21 | 规模预期 | 不确定，按可扩展设计（索引先建好） | — |
| D22 | 时区 | `Asia/Shanghai`，数据库存本地时间 | 不做 UTC 转换，跨国访问时日期以北京时间为准 |

### 3.2 三处张力的化解方式

访谈中出现了三组互相冲突的选择，以下是具体化解机制，**实现时必须严格遵守**：

#### 张力 A：零依赖 + SPA + 无重写

- **SPA 的深链接问题** → 用 **Hash 路由**。`#` 之后的部分不会发给服务器，因此刷新页面永远不会 404，完全不需要重写。
- **API 的路径问题** → 单入口 `api.php`，动词走 `?action=` 查询参数。
- **静态资源路径问题**（子目录部署）→ Vite 构建时设 `base: './'`，**所有资源引用使用相对路径**。前端所有 API 调用使用相对地址 `./api.php?action=...`，禁止以 `/` 开头的绝对路径。
- **代价**：SEO 不可用。这是已接受的决策。

#### 张力 B：无 cron + 大量定时需求

- **定时发布不依赖任务**：`contents.published_at` 存未来时间，前台查询条件恒带 `AND published_at <= NOW()`。时间一到自然可见，零任务依赖。**这是关键设计，不要改成任务驱动。**
- **只能靠任务的**（外链巡检、明细聚合、缓存清理、备份提醒）→ 伪 cron（§7.1）。

#### 张力 C：无 GD + 图床无缩略能力 + 流量成本

**这是本项目最尖锐的一处约束组合，且原方案的退路已被证伪。**

事实链条：

1. 服务端无 GD/Imagick → 无法生成缩略图。
2. 钛盘**不提供任何图片处理参数**（无 `!thumb` 之类的后缀）→ 无法靠 URL 参数取缩略图。
3. 因此，**列表页要显示的小图，必须在浏览器里先做好，再作为独立文件上传**。

**解决方案：浏览器端双尺寸预生成（强制）**

上传一张图片时，前端 Canvas 生成两个版本并**都**上传到钛盘：

| 版本 | 长边 | 格式/质量 | 用途 |
|---|---|---|---|
| 缩略图 `thumb` | 800px | WebP q=0.80 | 列表页、卡片、相关推荐、分享卡片 OG 图 |
| 原图 `full` | 2560px | WebP q=0.85 | 详情页大图 |

- 两个版本各自对应一个 UKEY，都记在**同一条 `assets` 记录**里（`rel_path` + `thumb_rel_path`）。
- 浏览器不支持 WebP 时回退 JPEG。
- Canvas 重绘顺带**剥离全部 EXIF**（含 GPS），一举两得（§9.6）。
- 上传耗时约为单文件的两倍，需在上传组件中显示进度与「正在处理图片…」状态。
- `StorageAdapter::thumbnail()` 对钛盘恒返回缩略版本的直链；若某条记录没有缩略版本（如手动登记的外链），降级返回原图 URL，并在后台标记为「流量风险」。

**若最终放弃双尺寸方案**（见 T10），则列表页将加载原图，流量约为双尺寸方案的 2–3 倍，需相应调高 R5 的风险等级。

---

## 4. 系统架构

### 4.1 目录结构

```
blog/                          ← 站点子目录（example.com/blog/）
├── index.html                 ← SPA 外壳（Vite 构建产物，入口）
├── assets/                    ← Vite 构建产物（JS/CSS，文件名带 hash）
├── api.php                    ← 唯一 API 入口
├── share.php                  ← 分享中转页（输出 OG 标签后跳转）
├── feed.php                   ← RSS / JSON Feed 输出
├── install.php                ← 一次性安装向导（建表、设管理员密码、生成后台入口名）
├── panel-<8位随机串>.php      ← 后台前端入口（install 时重命名生成）
├── config.php                 ← 环境配置（唯一含密钥的文件，永不覆盖）
├── app/                       ← PHP 应用代码（不对外）
│   ├── bootstrap.php          ← 引导：时区、错误处理、常量、autoload
│   ├── Db.php                 ← PDO 单例 + 查询辅助
│   ├── Router.php             ← action 分发
│   ├── Auth.php               ← 会话、CSRF、限流
│   ├── Response.php           ← 统一 JSON 响应
│   ├── Content.php            ← 内容服务（三类共用）
│   ├── Tag.php
│   ├── Asset.php              ← 媒体库
│   ├── Scheduler.php          ← 伪 cron
│   ├── Cache.php              ← 文件缓存
│   ├── Settings.php
│   ├── Markdown.php           ← 极简 MD 解析（仅用于 RSS 输出与纯文本摘要）
│   ├── Str.php                ← slug 生成、截断、转义
│   ├── Storage/               ← 图床适配器
│   │   ├── StorageAdapter.php ← 接口
│   │   ├── TttttAdapter.php   ← 钛盘（主力，支持上传；不支持删除与图片变换）
│   │   ├── ManualAdapter.php  ← 手动登记外链（支持巡检，不支持上传）
│   │   └── StorageException.php ← 统一异常，携带 status 码映射后的错误码
│   └── actions/               ← 按域拆分的 action 处理器
│       ├── public.php         ← 公开接口
│       └── admin.php          ← 后台接口
├── data/                      ← 运行数据（必须防直接访问，见 §9.4）
│   ├── cache/
│   ├── logs/
│   └── export/
└── uploads/tmp/               ← 上传中转临时目录（用完即删）
```

### 4.2 路径策略（子目录部署的强制约定）

**问题**：站点在 `/blog/` 下，任何以 `/` 开头的路径都会指到域名根，导致全站 404。

**强制规则**：

1. `config.php` 中定义 `APP_BASE`（如 `/blog`），由安装向导自动探测写入。
2. PHP 输出给前端的**所有站点 URL** 都经过 `site_url()` 处理，自动补 `APP_BASE`。（图片 URL 走另一套 `asset_url()`，见 §7.2.5 —— 两者不要混淆）
3. 前端构建配置 `base: './'`。前端所有请求写 `./api.php?action=...` 而非 `/api.php`。
4. `index.html` 中不得出现绝对路径的资源引用。
5. Cookie 的 `path` 设为 `APP_BASE`。

**验收方式**：把站点目录整体改名（`/blog/` → `/b2/`），仅改 `APP_BASE` 一处，全站应完全正常。

### 4.3 请求流程

**前台读取**：

```
浏览器访问 /blog/                     → index.html（SPA 外壳，无内容）
  ↓ Vue 初始化，解析 hash 路由
  ↓ GET ./api.php?action=content.list&type=article&page=1
    ↓ bootstrap.php（时区/错误处理/常量）
    ↓ 概率触发伪 cron（2%，响应后异步执行）
    ↓ Cache::remember(key)
    ↓ Content::list() → PDO 查询
    ↓ Response::json()
  ↓ Vue 渲染列表
```

**后台写入**：

```
POST ./api.php?action=admin.content.save
  ↓ 检查 Session（未登录 → 401）
  ↓ 检查 CSRF Token（不匹配 → 403）
  ↓ 校验入参（类型/长度/必填）
  ↓ 事务内写 contents + xxx_meta + content_tags
  ↓ bump cache_version（使全部缓存失效）
  ↓ Response::json()
```

---

## 5. 数据库设计

**字符集**：`utf8mb4` / `utf8mb4_unicode_ci`（支持 emoji）
**引擎**：InnoDB
**所有表前缀**：无（单站单库）

### 5.1 `contents`（统一主表）

```sql
CREATE TABLE contents (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('article','video','image') NOT NULL,
  slug          VARCHAR(191) NOT NULL COMMENT 'URL 标识，英文，唯一',
  title         VARCHAR(255) NOT NULL,
  summary       VARCHAR(500) NULL COMMENT '列表页摘要，留空则从正文截取',
  cover_path    VARCHAR(500) NULL COMMENT '封面，相对路径或外链 URL',
  status        ENUM('draft','published','trashed') NOT NULL DEFAULT 'draft',
  published_at  DATETIME NULL COMMENT '未来时间即定时发布，查询条件自动生效',
  is_featured   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首页精选',
  sort_weight   INT NOT NULL DEFAULT 0 COMMENT '拖拽排序权重，越大越靠前',
  deleted_at    DATETIME NULL COMMENT '进入回收站的时间',
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_slug (slug),
  KEY idx_list_article (type, status, published_at),
  KEY idx_list_general (status, published_at),
  KEY idx_trash (status, deleted_at),
  KEY idx_featured (is_featured, status, published_at),
  KEY idx_title (title(64)) COMMENT '供 LIKE 前缀匹配，全文 LIKE 仍走扫描'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 注：索引不写 DESC。MySQL 5.6 会静默忽略该关键字，写了反而误导。
--     ORDER BY ... DESC 仍可利用 InnoDB 的反向索引扫描，性能不受影响。
```

**`status` 三态语义**：

- `draft` — 草稿，前台不可见
- `published` — 已发布（**是否可见还取决于 `published_at <= NOW()`**）
- `trashed` — 回收站，前台不可见，后台可见可恢复

**前台可见性的唯一条件**（所有公开查询必须拼接）：

```sql
status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
```

### 5.2 扩展表

```sql
-- 文章（1:1）
CREATE TABLE article_meta (
  content_id  BIGINT UNSIGNED NOT NULL,
  body_md     MEDIUMTEXT NOT NULL COMMENT 'Markdown 原文，前端渲染',
  body_text   MEDIUMTEXT NOT NULL COMMENT '剥离标记的纯文本，供 LIKE 搜索与摘要生成',
  word_count  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (content_id),
  KEY idx_bodytext (body_text(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 视频（1:1）
CREATE TABLE video_meta (
  content_id       BIGINT UNSIGNED NOT NULL,
  source_type      ENUM('embed','mp4') NOT NULL COMMENT 'embed=iframe 嵌入, mp4=直链',
  provider         VARCHAR(32) NULL COMMENT 'bilibili / youtube / custom',
  source_key       VARCHAR(255) NOT NULL COMMENT 'BV 号 / YouTube videoId / mp4 完整 URL',
  poster_path      VARCHAR(500) NULL COMMENT '封面图，必填（无 FFmpeg 无法自动抽帧）',
  duration_seconds INT UNSIGNED NULL COMMENT '手填时长，可为空',
  aspect_ratio     VARCHAR(10) NOT NULL DEFAULT '16:9' COMMENT '预留占位，防布局跳动',
  PRIMARY KEY (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 图片（1:1）
CREATE TABLE image_meta (
  content_id   BIGINT UNSIGNED NOT NULL,
  asset_id     BIGINT UNSIGNED NULL COMMENT '关联媒体库',
  asset_path   VARCHAR(500) NOT NULL COMMENT '冗余自 assets.rel_path：钛盘为 UKEY，外链为完整 URL',
  width        INT UNSIGNED NULL COMMENT '必填，防布局跳动',
  height       INT UNSIGNED NULL,
  alt_text     VARCHAR(255) NULL COMMENT '无障碍与图片失效时的替代文本',
  camera_note  VARCHAR(255) NULL COMMENT '拍摄信息，可选',
  PRIMARY KEY (content_id),
  KEY idx_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 标签

```sql
CREATE TABLE tags (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(64) NOT NULL COMMENT '显示名，可中文',
  slug       VARCHAR(64) NOT NULL COMMENT 'URL 用，英文或拼音',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_name (name),
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_tags (
  content_id BIGINT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (content_id, tag_id),
  KEY idx_tag (tag_id, content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 媒体库与资产

```sql
CREATE TABLE assets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rel_path        VARCHAR(500) NOT NULL
                  COMMENT '主标识。provider=ttttt 时存 UKEY（不变的身份）；外链/manual 时存完整 URL',
  thumb_rel_path  VARCHAR(500) NULL
                  COMMENT '缩略版本的 UKEY / URL。钛盘无缩略参数，必须独立上传',
  remote_dkey     VARCHAR(255) NULL COMMENT 'link_add 返回的 dkey。link_del 时必需',
  remote_name     VARCHAR(255) NULL COMMENT 'link_add 返回的 name（文件名，注意不是 filename）',
  direct_url      VARCHAR(1000) NULL
                  COMMENT '由 dkey+name 按 ttttt_direct_template 拼出的直链（物化存储，避免每次拼接）',
  thumb_dkey      VARCHAR(255) NULL COMMENT '缩略版本的 dkey',
  thumb_name      VARCHAR(255) NULL COMMENT '缩略版本的 name',
  thumb_direct_url VARCHAR(1000) NULL COMMENT '缩略版本的直链',
  origin_url      VARCHAR(1000) NULL COMMENT '完整原始 URL，审计与迁移用',
  provider        VARCHAR(32) NOT NULL DEFAULT 'manual' COMMENT 'ttttt/manual',
  is_external     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=完全外部，换图床时不动它',
  storage_model   TINYINT UNSIGNED NOT NULL DEFAULT 99
                  COMMENT '钛盘存储模式：99=永久, 0=24h, 1=3天, 2=7天。默认必须为 99',
  expires_at      DATETIME NULL COMMENT '非永久模式的过期时间；99=永久 时为 NULL',
  mime            VARCHAR(64) NULL,
  width           INT UNSIGNED NULL  COMMENT '原图宽（必填，防布局跳动）',
  height          INT UNSIGNED NULL  COMMENT '原图高（必填）',
  thumb_width     INT UNSIGNED NULL,
  thumb_height    INT UNSIGNED NULL,
  file_size       INT UNSIGNED NULL COMMENT '原图字节数',
  sha256          CHAR(64) NULL COMMENT '去重：相同图片不重复上传',
  ref_count       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '被引用次数，0 才允许删除记录',
  check_status    ENUM('unknown','ok','missing','expired') NOT NULL DEFAULT 'unknown',
  last_check_at   DATETIME NULL,
  last_http_code  SMALLINT NULL,
  last_error      VARCHAR(255) NULL COMMENT '巡检失败原因；钛盘返回的 status 码也记这里',
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rel_path (rel_path(191)),
  KEY idx_check (check_status, last_check_at),
  KEY idx_expire (storage_model, expires_at),
  KEY idx_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 注意：rel_path 索引前缀取 191 字符 —— MySQL 5.6 + utf8mb4 的上限是 767 字节，
--       191×4 = 764 字节正好卡在内。不要改成 255。
```

### 5.5 系统表

```sql
-- 键值配置
CREATE TABLE settings (
  `key`      VARCHAR(64) NOT NULL,
  `value`    TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 伪 cron 任务状态
CREATE TABLE tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_key     VARCHAR(64) NOT NULL,
  last_run_at  DATETIME NULL,
  duration_ms  INT UNSIGNED NULL,
  cursor       VARCHAR(255) NULL COMMENT '分批处理进度',
  locked_at    DATETIME NULL COMMENT '超过 60 秒视为死锁，可被抢占',
  lock_owner   VARCHAR(64) NULL,
  last_result  VARCHAR(255) NULL COMMENT '用于后台展示，如「巡检 20 条，2 条失效」',
  PRIMARY KEY (id),
  UNIQUE KEY uk_task (task_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 登录限流
CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash      CHAR(64) NOT NULL COMMENT 'sha256(ip + 盐)，不存明文 IP',
  attempted_at DATETIME NOT NULL,
  success      TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 浏览去重明细（会被聚合任务清理）
CREATE TABLE view_seen (
  content_id   BIGINT UNSIGNED NOT NULL,
  visitor_hash CHAR(64) NOT NULL COMMENT 'sha256(ip + UA + 日盐)',
  stat_date    DATE NOT NULL,
  PRIMARY KEY (content_id, visitor_hash, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 浏览日聚合
CREATE TABLE view_daily (
  content_id BIGINT UNSIGNED NOT NULL,
  stat_date  DATE NOT NULL,
  pv         INT UNSIGNED NOT NULL DEFAULT 0,
  uv         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (content_id, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- slug 变更历史（旧链接重定向）
CREATE TABLE slug_redirects (
  old_slug   VARCHAR(191) NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (old_slug),
  KEY idx_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 首页布局区块（拖拽排序）
CREATE TABLE home_blocks (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  block_type VARCHAR(32) NOT NULL COMMENT 'banner/featured/recent_article/recent_video/gallery/tag_cloud/about',
  title      VARCHAR(128) NULL COMMENT '区块自定义标题',
  config     TEXT NULL COMMENT 'JSON 字符串：条数、筛选条件等。MySQL 5.6 无 JSON 列，用 TEXT 存',
  sort_weight INT NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_order (is_enabled, sort_weight)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.6 `comments`（第一版不建表）

决策为「第一版先不做评论」。**保留接口位阶**：

- 表结构设计冻结在附录 A，第一版**不执行建表**。
- 前端详情页预留 `<CommentArea>` 组件位，由 `settings.comments_enabled = 0` 控制不渲染。
- 后续启用时只需执行建表 SQL + 一个 action 处理器 + 打开开关。

---

## 6. API 规范

### 6.1 约定

- **入口**：`./api.php?action=<域>.<方法>`
- **方法**：读用 `GET`，写用 `POST`（`application/json` 或 `application/x-www-form-urlencoded`）
- **响应**：统一 JSON

```json
// 成功
{ "ok": true, "data": { ... }, "meta": { "page": 1, "per_page": 10, "total": 128 } }

// 失败
{ "ok": false, "error": { "code": "VALIDATION_FAILED", "message": "标题不能为空", "field": "title" } }
```

- **HTTP 状态码**：200 成功；400 参数错；401 未登录；403 CSRF/权限；404 资源不存在；429 限流；500 服务器错误
- **时间格式**：`Y-m-d H:i:s`（Asia/Shanghai）
- **错误码枚举**：`VALIDATION_FAILED` / `UNAUTHORIZED` / `CSRF_INVALID` / `NOT_FOUND` / `RATE_LIMITED` / `SLUG_TAKEN` / `STORAGE_ERROR` / `UPLOAD_TOO_LARGE` / `INTERNAL_ERROR`

### 6.2 公开接口（无需登录）

| action | 参数 | 说明 |
|---|---|---|
| `content.list` | `type`(article/video/image) `page` `per_page` `tag` `q` `year` `month` | 列表，强制只返回前台可见内容 |
| `content.detail` | `slug` 或 `id` | 详情；若 slug 命中 `slug_redirects` 则返回 `redirect_to` 字段 |
| `content.related` | `slug` `limit`(默认 6) | 基于共同标签的相关内容 |
| `tag.list` | `type`(可选) | 标签及每个标签的内容计数 |
| `archive.list` | `type`(可选) | 按年月聚合的归档 |
| `site.home` | — | 首页所需全部数据：区块顺序 + 每块内容 |
| `site.meta` | — | 站点名、描述、备案号、导航、是否启用评论 |
| `view.hit` | `slug` | 上报浏览，服务端去重 |
| `search.suggest` | `q` | 标题前缀建议（`title LIKE 'kw%'`，走索引） |

### 6.3 后台接口（需 Session + CSRF）

| action | 说明 |
|---|---|
| `auth.login` | `{password}`；限流；成功返回 `csrf_token` |
| `auth.logout` | 销毁会话 |
| `auth.check` | 校验会话有效性，返回是否已登录 |
| `admin.content.list` | 含草稿/回收站，支持 `status` `type` `tag` `q` |
| `admin.content.get` | 编辑用，返回全部字段（含 Markdown 原文） |
| `admin.content.save` | 新建/更新；`id` 为空则新建 |
| `admin.content.trash` | 移入回收站（软删除） |
| `admin.content.restore` | 从回收站恢复 |
| `admin.content.destroy` | 彻底删除（连带清理图床，先查 `ref_count`） |
| `admin.content.batch` | `{ids[], op: trash/restore/destroy/publish/set_tags}` |
| `admin.content.reorder` | `{ids[]}` 按数组顺序写 `sort_weight` |
| `admin.tag.*` | `list` `save` `rename` `merge` `delete` |
| `admin.asset.list` | 媒体库列表，支持 `check_status` 筛选 |
| `admin.asset.add` | `{url}` 手动登记外链图片 |
| `admin.asset.upload` | `multipart/form-data`，服务端中转上传至钛盘。**前端须同时提交原图与缩略图两个 blob**；服务端返回两个 UKEY。未配置 Key/`cdn_base` 时返回 `STORAGE_ERROR` 并引导到设置页 |
| `admin.asset.recheck` | 手动触发单条外链复检 |
| `admin.asset.relink` | **用存量 UKEY 重跑 `link_add` 重建直链**（模板变更或直链丢失时的自愈入口，支持批量） |
| `admin.asset.revoke` | 撤销单条资产的直链（调用 `link_del`，**内部固定带 `delete=1`**），用于清理孤立资源 |
| `admin.asset.delete` | 删记录。`ref_count > 0` 时拒绝；`ref_count = 0` 时先 `revoke()` 再删记录 |
| `admin.asset.sync` | 调 `list_of_direct` **循环翻页**拉取远端全部直链，与库比对，产出「库有远端无」与「远端有库无」两份差异清单 |
| `admin.home.get` / `admin.home.save` | 首页区块配置 |
| `admin.setting.get` / `admin.setting.save` | 站点设置 |
| `admin.task.list` / `admin.task.run` | 伪 cron 任务状态与手动执行 |
| `admin.export.json` | 导出全部内容为 JSON |
| `admin.export.sql` | 导出数据库结构 + 数据 SQL |
| `admin.sysinfo` | 环境探测结果（§2.1 的四项 + 容量） |
| `admin.log.list` | 错误日志（分页） |

### 6.4 独立入口

| 文件 | 说明 |
|---|---|
| `share.php?slug=xxx` | 输出带 OG/Twitter Card 的 HTML，`<script>` 立即跳转到 `index.html#/post/xxx`。**必须支持 `curl -A "MicroMessenger"` 也能拿到 meta**（服务端渲染，不依赖 JS） |
| `feed.php?type=rss` | RSS 2.0 XML |
| `feed.php?type=json` | JSON Feed 1.1 |

---

## 7. 核心机制

### 7.1 伪 cron 调度器（`Scheduler.php`）

**这是本项目最容易写崩的地方，务必按此实现。**

#### 触发点

1. **前台概率触发**：`api.php` 引导阶段，`random_int(1, 100) <= settings.cron_probability`（默认 2）时触发
2. **后台登录补跑**：登录成功后同步执行一轮
3. **后台手动**：`admin.task.run`

#### 执行规则

```
0. 前置：ignore_user_abort(true)
   先输出响应 → fastcgi_finish_request()（已确认可用）→ 客户端连接立即关闭，用户零感知
   然后 set_time_limit(0)

   注意：max_execution_time = 30s 是 PHP 层的限制，set_time_limit(0) 可解除；
   但 PHP-FPM 的 request_terminate_timeout 是 PHP 之上的硬墙，无法从脚本内解除。
   因此单次任务总墙钟时间仍硬性控制在 20 秒内 —— 靠分批而不是靠超时。
   这也意味着 cURL 的 CURLOPT_TIMEOUT 必须 ≤ 25s 且需与剩余预算比对。

1. 遍历任务列表，对每个任务：
   a. 取锁：UPDATE tasks SET locked_at=NOW(), lock_owner=?
      WHERE task_key=? AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL 60 SECOND)
      影响行数 = 0 → 跳过（别人在跑）
      —— 此方案用「影响行数判断」而非 SELECT ... FOR UPDATE SKIP LOCKED，
         因为 MySQL 5.6 不支持后者（见 §2.2）。不要改动这个实现。
   b. 检查间隔：last_run_at + 任务周期 > NOW() → 跳过
   c. 从 cursor 处继续处理，单次最多 N 条（各任务自定）
   d. 每处理完一条就检查是否已用掉 12 秒预算，用尽则保存 cursor 后立即退出
   e. 记录 last_run_at / duration_ms / cursor / last_result
   f. 释放锁
2. register_shutdown_function() 兜底释放所有锁（防致命错误留死锁）
3. 全程 try/catch，任何异常只写日志，绝不向上抛（不能因为定时任务导致 API 500）
```

#### 任务清单

| task_key | 周期 | 单批 | 作用 |
|---|---|---|---|
| `asset_check` | 12 小时 | 20 条 | 巡检图片直链存活。**优先走 `list_of_direct` 接口循环翻页拉取远端全部直链做集合比对**——一次请求胜过 N 次探测；接口不可用时降级为逐条 `HEAD`（已确认无防盗链，T3 关闭，无需 `Range` 兼容层）。IFrame 视频不巡检（反爬风险） |
| `expires_watch` | 1 天 | 200 条 | 检查 `storage_model != 99` 的资产，`expires_at` 已过则标 `expired` 并后台告警。**这是防止误用非永久模式导致图片集体消失的保险丝** |
| `view_aggregate` | 1 小时 | 500 条 | 把 `view_seen` 明细聚合进 `view_daily` |
| `view_gc` | 1 天 | 1000 条 | 删除 7 天前的 `view_seen` 明细 |
| `cache_gc` | 1 天 | — | 删除过期缓存文件（含因 `cache_version` 递增产生的孤儿文件） |
| `backup_remind` | 1 天 | — | 计算距上次导出的天数，写进 settings 供后台首页展示 |
| `login_gc` | 1 天 | — | 清理 7 天前的 `login_attempts` |
| `trash_gc` | 1 天 | — | 把回收站满 30 天的内容标记为待彻底删除（**只提醒，不自动删**） |

#### 明确不做成任务的

- **定时发布**：靠查询条件实现（§5.1），不建任务。

### 7.2 图床适配器（`Storage/`）

> 图床已确定为**钛盘（ttttt.link）**。它提供上传 API 与直链，但**不提供图片处理参数，也不提供删除接口**。这两条缺口直接决定了本节的实现方式。

#### 7.2.1 接口

```php
interface StorageAdapter
{
    /**
     * 上传本地临时文件，并取回可用直链。
     * 对钛盘而言内部是两步（upload_cli → link_add），调用方无需感知。
     * @return array{rel_path:string, remote_dkey:string, remote_name:string,
     *               direct_url:string, model:int}
     */
    public function put(string $tmpPath, string $remoteName, string $mime): array;

    /**
     * 撤销访问权限，可选同时删除文件本体。
     *
     * @param bool $deleteFile true = 连带删除文件，释放空间（对应钛盘的 delete 参数）。
     *                         彻底删除内容时必须传 true，否则链接失效但空间永久占位。
     * @return bool 成功与否。失败不抛异常 —— 调用方记录日志并继续（内容已不可见，
     *              残留链接是次要问题，不应阻塞删除流程）
     */
    public function revoke(array $asset, bool $deleteFile = false): bool;

    /**
     * 列出远端现存直链，用于自检与批量重建。
     * 注意钛盘此接口**分页**，实现内部须循环翻页直至取完。
     * @return array|null 每项含 dkey / name / link / size / etime；不支持时返回 null
     */
    public function listLinks(): ?array;

    /** 存活探测。返回 ['ok'=>bool, 'http_code'=>int|null, 'error'=>string|null] */
    public function probe(string $relPath): array;

    /**
     * 拼出可对外访问的完整 URL。
     * 优先取 $asset['direct_url']；为空则回退 cdn_base + rel_path 拼接。
     */
    public function url(array $asset, bool $thumb = false): ?string;

    public function supportsUpload(): bool;
    /** 能否撤销访问权限（钛盘为 true） */
    public function supportsRevoke(): bool;
    /** 撤销时能否连带删除文件本体以释放空间（钛盘为 true，靠 delete 参数） */
    public function supportsHardDelete(): bool;
    /** 图床是否自带缩略图能力（钛盘为 false） */
    public function supportsTransform(): bool;
    /** 图床是否自动剥离 EXIF（钛盘为 false → 前端必须剥离） */
    public function stripsExif(): bool;
}
```

#### 7.2.2 `TttttAdapter` 实现要点

> **⚠️ 关键事实：钛盘上传只返回 UKEY，不是一个能放进 `<img src>` 的链接。**
> 要拿到直链，**必须再调第二个接口**（`services/direct` 的 `link_add` 动作）。
> 这是原设计（假设"上传即得直链"）之外的一步，遗漏它会导致所有图片都拼不出可用 URL。

**一张图片的完整流程是 4 次远程调用**（2 个版本 × 2 步）：

```
缩略图 blob ──① upload_cli──> UKEY_thumb ──② link_add──> 直链A
原图   blob ──③ upload_cli──> UKEY_full  ──④ link_add──> 直链B
```

**步骤 1：上传文件**

| 项 | 值 |
|---|---|
| Endpoint | `POST https://tmp-cli.vx-cdn.com/app/upload_cli` |
| Content-Type | `multipart/form-data` |
| 参数 | `key`（API Key）、`file`（文件内容）、`model`（存储模式）、`mrid`（可选，目标文件夹 ID） |
| 成功返回 | `status: 1`，`data` 为**文件 UKEY** |

**步骤 2：直链管理**（同一个 Endpoint，用 `action` 区分三种操作）

| 项 | 值 |
|---|---|
| Endpoint | `POST https://tmp-api.vx-cdn.com/services/direct` |
| Content-Type | `application/x-www-form-urlencoded` |

| action | 参数 | 成功返回 | 本项目用途 |
|---|---|---|---|
| `link_add` | `key`、`ukey`、`valid_time=0`、`download_limit=0` | `status:1`，`data[0]` 含 `dkey` 与 `name` | 上传后立即调用，拿到构成直链的两段 |
| `link_del` | `key`、`dkey`、**`delete=1`** | `status:1` | **彻底删除内容时撤销访问 + 释放空间**（D14） |
| `list_of_direct` | `key`、**`page`** | `status:1`，`data[]` 每项含 `dkey`/`link`/`name`/`size`/`etime` | 直链自检与批量重建 |

**直链拼接规则（已实测确认）**：

```
https://download.wuyuge.cn/files/{dkey}/{name}
```

`link_add` 的真实返回长这样：

```json
{"data":[{"dkey":"6ac3d281f0221","name":"testpic.jpg"}],"status":1,"debug":["set_valid_time:0"]}
```

因此模板写入配置项 `ttttt_direct_template`，默认值即上面的字符串。

**三个必须留意的解析细节**：

1. **`data` 是一个数组，不是对象。** 必须取 `data[0].dkey` 与 `data[0].name`。写成 `$r['data']['dkey']` 会得到 `null` 且不报错 —— 表现为「上传成功但图片全是占位图」，极难排查。
2. **字段名是 `name`，不是 `filename`。** 数据表列名相应为 `remote_name`（不是 `remote_filename`）。
3. **响应里有 `debug` 字段**（如 `["set_valid_time:0"]`）。开发期可用于确认参数是否被正确接收；**生产环境不要把它回显给前端**，只写日志。

**其余要点**：

- 拼接结果**物化**存进 `assets.direct_url` / `thumb_direct_url`，同时把 `dkey`、`name` 原样存库 —— 这样即使模板写错或日后域名变更，也能用存量数据批量重建，**无需重新上传图片**。
- `valid_time` 与 `download_limit` **必须显式传 `0`**（0 = 永久 / 不限次数）。虽然实测显示不传时默认也是 0（`debug` 中的 `set_valid_time:0`），但依赖默认值是脆弱的 —— 显式传参，并把这个决定写死在代码里。
- **`list_of_direct` 是分页的**（参数含 `page`）。`listLinks()` 实现内部必须循环翻页直到取完，否则「同步检查」会漏掉大部分直链，产生大量假阳性。
- `list_of_direct` 返回的每项**自带 `link` 字段**（完整直链），因此同步检查时可直接与库中 `direct_url` 比对，无需自己拼接。

**`model` 必须恒为 `99`（永久）。** 其他模式（0=24h / 1=3天 / 2=7天）会让图片到期消失，不可接受：

- 写死为常量 `TttttAdapter::MODEL_PERMANENT = 99`，**不在后台 UI 暴露为可选项**。
- 上传后把 `storage_model = 99`、`expires_at = NULL` 写入 `assets`。
- `expires_watch` 任务（§7.1）作为保险丝，防止将来有人误改这个常量。

**`mrid`（目标文件夹）—— 已确认无用，不要传**

社区资料中 `status=5` 的原文曾写作「当日上传总量超出配额（**存储至私有空间不受限制**）」，暗示传 `mrid` 存入私有空间可以绕开日配额。

> **实测结论（T9，已关闭）**：**不能绕开**。日配额是账户级的，与存储位置无关。
>
> 因此 `ttttt_mrid` 保留为空，`TttttAdapter` **不发送** `mrid` 参数。`status=5` 发生时唯一正确的处理是等次日重置（见 §10.2）。不要再试着用这个参数做优化。

**步骤 1（上传）的 `status` 映射**：

| status | 含义 | 本项目行为 |
|---|---|---|
| `1` | 成功 | 取 `data` 作为 UKEY，写入 `assets.rel_path` |
| `0` | 无效请求 | 抛 `STORAGE_ERROR` 并写日志（多半是参数拼装 bug，属需修复的缺陷） |
| `2` | 文件过大 | 抛 `UPLOAD_TOO_LARGE`。前端提示「请压缩后重试」。**T2 未测出钛盘上限**，实际闸门是本机 `upload_max_filesize`（100M，§2.1），故 `ttttt_max_bytes` 与之取齐 |
| `3` | 服务器繁忙 | 抛 `STORAGE_BUSY`，自动重试 2 次（退避 1s / 3s） |
| `4` | 空间不足 | 抛 `STORAGE_FULL`，**后台首页红色告警**。配合「孤立资源清理」入口（用 `delete=1` 释放空间） |
| `5` | 配额不足 | 抛 `STORAGE_QUOTA`，**后台首页红色告警**。**按天重置，次日自动恢复** —— 告警文案必须写清这点，否则会被误判为永久故障。**已确认无绕开手段**（T9：`mrid` 无效），只能等重置 |
| `6` | API Key 已失效 | 抛 `STORAGE_AUTH`，提示去设置页检查 Key |
| 其他 / 无法解析 | 未知 | 抛 `STORAGE_ERROR`，日志记录**原始响应前 500 字节** |

**步骤 2 各 action 的 `status` 映射**：

`link_add`：

| status | 含义 | 本项目行为 |
|---|---|---|
| `1` | 成功 | 取 `data[0].dkey` 与 `data[0].name`，按模板拼出直链后写库 |
| `0` | 失败 | 抛 `STORAGE_ERROR` 并记录完整响应 |
| `1001` | 文件不存在 | 抛 `STORAGE_ERROR` 并告警 —— UKEY 有效但文件没了，属严重异常 |
| `1002` | 内部错误 | 重试 2 次，仍失败抛 `STORAGE_BUSY` |
| `1005` | 文件未就绪 | **上传后有短暂异步就绪期**。退避 1s / 2s / 4s 重试 3 次；仍失败则抛 `STORAGE_BUSY`，但**保留已上传的 UKEY**，下次只需补建直链、不必重传 |

`link_del`：

| status | 含义 | 本项目行为 |
|---|---|---|
| `1` | 成功 | 记录已释放空间（若 `delete=1`） |
| `0` | 失败 | 写日志 + 进「待清理」列表，**不阻塞删除流程** |
| `1003` | 直链不存在 | **视为成功**（幂等）—— 目标状态已达成，重复删除不应报错 |

`list_of_direct`：

| status | 含义 | 本项目行为 |
|---|---|---|
| `1` | 成功 | 累积本页 `data[]`，按 `page` 继续翻页直到取完 |
| 其他 | 失败 | `asset_check` 降级为逐条 `HEAD` 探测（已确认无防盗链，可用最简单的 HEAD） |

**cURL 配置**（受 `max_execution_time = 30s` 制约）：

```php
CURLOPT_TIMEOUT        => 8,    // 单次 8 秒。4 次调用 + 重试累计必须留在 25 秒内
CURLOPT_CONNECTTIMEOUT => 5,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_SSL_VERIFYPEER => true, // 不得关闭，见下方风险说明
CURLOPT_POSTFIELDS     => [
    'key'   => Config::get('ttttt_api_key'),      // 只在 config.php，绝不进数据库/前端
    'file'  => new CURLFile($tmpPath, $mime, $remoteName),
    'model' => (string) self::MODEL_PERMANENT,
],
```

> **原设计作废**：v1.1 中「单次 cURL 超时 25 秒」在两步流程下不再可行 —— 4 次调用必须共享 25 秒预算，故单次收紧到 8 秒。这也意味着**上传大图有超时风险**，前端压缩目标不宜放宽（这也是双尺寸方案的另一重理由：单文件更小、更快）。

**接口细节（已于 2026-10-06 验证确认）**：

1. ~~Key 的参数名可能是 `key` 也可能是 `token`~~ → **确认为 `key`**（一串密钥，非 token）。`ttttt_key_param` 配置项保留作为兜底，默认 `key`，正常情况下无需改动。
2. ~~社区示例带 `-k`~~ → **证书正常，已跑通**。保持 `CURLOPT_SSL_VERIFYPEER = true`。**不要因为任何原因关闭它** —— 那等于把 API Key 和图片内容暴露给中间人。

**API Key 的存放**：`config.php`（不在 `settings` 表）。理由是 `settings` 表会经由 `admin.setting.get` 返回给前端，而 Key 泄露 = 别人盗刷你的配额。

#### 7.2.3 能力边界

钛盘四个接口确认后，**只剩一个真正的边界**：

| 边界 | 后果 | 本项目的应对 |
|---|---|---|
| **无图片处理参数** | 列表页无法靠 URL 参数取小图 | 浏览器端生成**两个尺寸并分别上传**（§3.2 张力 C）。`assets` 用 `rel_path`（原图 UKEY）+ `thumb_rel_path`（缩略图 UKEY）两条并存 |

**其余能力均已具备**：

| 能力 | 状态 | 说明 |
|---|---|---|
| 上传 | ✅ | 单文件上限实测未触顶，大文件（长视频）也能传（T2） |
| 建直链 | ✅ | `link_add`，模板拼接 |
| 删直链 | ✅ | `link_del` |
| **释放空间** | ✅ | `link_del` 的 `delete=1` 会**连带删除文件本体**（T14 已确认） |
| 列直链 | ✅ | `list_of_direct`（分页），返回项自带 `link` 字段 |
| 防盗链 | ✅ 无 | 前台图片不会被 Referer 拦截（T3）→ R4 撤销 |

> **⚠️ 唯一的下限陷阱**：`link_del` **必须显式传 `delete=1`** 才会释放空间。只传 `dkey` 的话，链接失效了、文件却永远占位，`status=4`（空间不足）会缓慢累积且难以察觉。**这个参数不可省略**，实现时在 `revoke()` 里写死，不暴露给调用方选择。

#### 7.2.4 `ManualAdapter`（保留）

第一版同时保留手动登记通道，用于处理「你已经手动传好的图」：

- `supportsUpload()` → `false`
- `supportsRevoke()` → `false`（别人的图，无权撤销）
- `supportsTransform()` → `false`
- `stripsExif()` → `false`（**因此手动登记的图不会被剥离 EXIF，后台需给出隐私提示**）
- `listLinks()` → `null`
- `probe()` 正常实现（外链也能巡检）

#### 7.2.5 URL 解析与归一化（关键细节）

虽然直链格式已确认，URL 解析仍设计成**四层回退** —— 前三层覆盖正常路径，第四层保证「宁可显示占位图，也不吐出半截 URL」：

```php
/**
 * 由 asset 记录解析出可用的图片 URL。
 * @param array  $asset  assets 表的一行
 * @param string $size   'thumb' | 'full'
 */
function asset_url(array $asset, string $size = 'full'): ?string {
    $isThumb = ($size === 'thumb');

    $key    = $isThumb ? ($asset['thumb_rel_path']   ?? null) : ($asset['rel_path']     ?? null);
    $direct = $isThumb ? ($asset['thumb_direct_url'] ?? null) : ($asset['direct_url']   ?? null);
    $dkey   = $isThumb ? ($asset['thumb_dkey']       ?? null) : ($asset['remote_dkey']  ?? null);
    $name   = $isThumb ? ($asset['thumb_name']       ?? null) : ($asset['remote_name']  ?? null);

    if ($key === null || $key === '') return null;

    // ① 手动登记的外链 = 完整 URL，原样返回
    if (preg_match('#^https?://#i', $key)) return $key;

    // ② 已物化的直链 → 直接用（正常路径，零拼接开销）
    if ($direct !== null && $direct !== '') return $direct;

    // ③ 用 dkey + name 按模板现场重建
    //    存在的意义：模板写错、或日后直链域名变更时，存量数据可批量自愈
    if ($dkey !== null && $name !== null) {
        $tpl = Settings::get('ttttt_direct_template');
        if ($tpl) {
            return str_replace(['{dkey}', '{name}'], [$dkey, $name], $tpl);
        }
    }

    // ④ 兜不住 → null，前台走占位图。绝不拼出半截 URL
    return null;
}

/** 缩略版 URL；无缩略版时回退原图（同时应在后台标记为流量风险） */
function thumb_url(array $asset): ?string {
    return asset_url($asset, 'thumb') ?? asset_url($asset, 'full');
}
```

> **相比 v1.2 的变化**：原先第 ③ 层是「`cdn_base` + UKEY 猜测拼接」。现在直链的真实构成（`dkey` + `name` + 模板）已经确认，因此第 ③ 层改为**基于已存字段的确定性重建**，不再是猜测。`cdn_base` 保留但**用途收窄为「识别 URL 是否属于本图床」**（供 `normalize_asset_key()` 判断），不再参与 URL 构造。

**存库时的归一化**（处理手动粘贴直链的场景）：

```php
function normalize_asset_key(string $input): array {
    $base = Settings::get('cdn_base');
    if ($base && str_starts_with($input, $base)) {
        // 自己的图床直链 → 剥出 UKEY 存 rel_path，同时把完整 URL 存进 direct_url
        return [
            'rel_path'    => ltrim(substr($input, strlen($base)), '/'),
            'direct_url'  => $input,
            'is_external' => 0,
        ];
    }
    // 别人的图 → 原样存，永不批量替换
    return ['rel_path' => $input, 'direct_url' => null, 'is_external' => 1];
}
```

**这套设计解决三个问题**：

1. **直链拼接模板可变更** —— 模板只存在于配置项与重建逻辑中，改一处即可让全部存量图片跟上。
2. **换图床成本低** —— 自有图片以 UKEY 为身份，Adapter 换实现即可；手动登记的外链（`is_external = 1`）完全不受影响。
3. **直链失效可自愈** —— 用 `list_of_direct` 翻页比对远端实际存在的直链（返回的 `link` 字段就是完整直链，可直接与库中 `direct_url` 比对），缺失的用存量 UKEY 批量重跑 `link_add` 恢复，**不必重新上传图片**。这正是 `rel_path` 存 UKEY 而非存直链的原因。

### 7.3 视频播放组件

两种 `source_type`，前端两个组件：

**`embed`（iframe）**

```php
// provider=bilibili, source_key=BV1xx411c7mD
// → https://player.bilibili.com/player.html?bvid=BV1xx411c7mD&autoplay=0
// provider=youtube, source_key=dQw4w9WgXcQ
// → https://www.youtube.com/embed/dQw4w9WgXcQ
```

必须遵守：
1. **懒加载**：列表页首屏只渲染封面图 + 播放按钮，**点击后才注入 iframe**。否则 N 个 iframe 会拖死首屏。
2. **宽高比占位**：容器用 `aspect-ratio` 撑开，避免加载后布局跳动。
3. **`referrerpolicy="no-referrer"`** 与 `allowfullscreen`。
4. CSP 必须允许 `frame-src player.bilibili.com www.youtube.com`。

**`mp4`（原生 video）**

- 直链必须支持 HTTP Range。外链 CDN 通常自带；**若将来改为本地存储，PHP 直出视频必须手写 Range 响应（206 Partial Content），否则进度条不可拖动**。这条写在这里，届时务必实现。
- `preload="none"` + 封面图。

### 7.4 文件缓存（`Cache.php`）

- **默认关闭**（`settings.cache_enabled = 0`），符合「按可扩展设计」的决策
- 存储：`data/cache/<sha1(key)>.<cache_version>.json`
- Key 组成：`cache_version | action | 排序后的参数`
- 失效：任何内容写入时 `cache_version` 自增 → 旧缓存自然成为孤儿文件，由 `cache_gc` 清理。**不做逐个删除，避免失效逻辑出错。**
- TTL：默认 300 秒，读时比对文件 mtime
- 不缓存：详情页（浏览量实时）、后台接口

### 7.5 浏览量去重

- `visitor_hash = sha256(ip + user_agent + 当日盐)`，盐存在 settings 里每日轮换
- 命中 `view_seen` 主键冲突则忽略（`INSERT IGNORE`）
- 过滤：`User-Agent` 匹配常见爬虫关键字（bot/spider/crawl/curl/wget/headless）直接丢弃
- 页面停留 < 1 秒的上报一并丢弃（前端在 `visibilitychange` 后上报）
- 写库失败静默忽略（浏览统计不能影响正常浏览）

### 7.6 分享中转页

```php
// share.php?slug=my-first-post
// 1. 查询内容，不存在 → 404 页面
// 2. 输出完整 HTML：
//    <meta property="og:title" ...>
//    <meta property="og:description" content="{summary}">
//    <meta property="og:image" content="{asset_url($asset, 'thumb')}">
//    <meta property="og:url" content="{绝对URL of index.html#/post/slug}">
//    <meta name="twitter:card" content="summary_large_image">
//    <script>location.replace('index.html#/post/{slug}')</script>
// 3. 真人访问：秒跳转；爬虫/微信：读到 meta 即返回
```

---

## 8. 前端设计

### 8.1 技术栈

- Vue 3（Composition API）+ Vite
- `vue-router` — **`createWebHashHistory`**（强制，hash 路由）
- `marked` — Markdown 渲染
- `DOMPurify` — 渲染后清洗（**Markdown 源存库不代表渲染安全，用户可写内联 HTML**）
- `@vueuse/core` — 可选（暗色模式、观察者）
- 不引入 UI 框架，手写 CSS + CSS 变量

### 8.2 路由表

避免 hash 冲突，用 `#/<类型>/<slug>`：

| 路由 | 页面 |
|---|---|
| `#/` | 首页（定制布局） |
| `#/post/:slug` | 文章详情 |
| `#/video/:slug` | 视频详情 |
| `#/photo/:slug` | 图片详情 |
| `#/articles` `#/videos` `#/photos` | 三类列表（分页/筛选） |
| `#/tag/:slug` | 标签聚合（按 `?type=` 筛选） |
| `#/archive` | 归档时间线 |
| `#/search?q=&type=` | 搜索结果 |
| `#/about` `#/links` | 静态页（复用 Markdown 渲染） |
| `#{adminEntry}` | 后台 |
| `#/:pathMatch(.*)*` | 404 |

### 8.3 关键交互

| 场景 | 要求 |
|---|---|
| 列表加载 | 骨架屏占位，不用全屏 loading |
| 分页 | 无限滚动 + 「加载更多」按钮兜底；滚动位置在返回列表时恢复 |
| 图片 | `loading="lazy"` + 显式 `width`/`height`（防跳动）+ `object-fit: cover` |
| 图片失效 | `@error` 回退到占位图，并显示 `alt_text` |
| Markdown | `marked` → `DOMPurify.sanitize` → `v-html`。**顺序不可颠倒** |
| 代码块 | 高亮可选（引入 highlight.js 会增加体积，评估后决定） |
| 暗色模式 | CSS 变量 + `prefers-color-scheme` 自动 + 手动切换存 localStorage |
| 移动端 | 断点 768px；导航折叠为抽屉；视频 iframe 全宽 |
| 首屏 | 首页接口一次返回全部区块数据，避免瀑布式请求 |
| 错误 | 接口失败显示可重试的错误态，不白屏 |

### 8.4 后台（`panel-<随机串>.php` 内嵌的 SPA）

- 登录页 / 仪表盘（含环境探测 + 备份提醒 + 任务状态）
- 内容管理：三类分开的列表页，支持筛选、批量操作、拖拽排序（`vuedraggable` 或手写 HTML5 DnD）
- 编辑器：左侧 Markdown 源码 / 右侧实时预览；工具栏插入图片（从媒体库选或填 URL）
- 媒体库：网格展示、筛选失效项、手动登记 URL、复检；**「待清理」列表**（撤销失败的直链，可重试）、**「孤立资源」列表**（`ref_count = 0`）、**「同步检查」**（调 `list_of_direct` 翻页比对库与远端差异）、**批量重建直链**
- 标签管理：重命名、合并、删除（删除前提示影响条数）
- 首页布局：区块增删 + 拖拽排序 + 每块的参数配置
- 设置：站点信息、图床配置、伪 cron 概率、缓存开关
- 导出与备份：一键导出 JSON / SQL，显示距上次导出的天数

---

## 9. 安全规范

### 9.1 认证

- 密码用 `password_hash($pw, PASSWORD_DEFAULT)` 存储，`password_verify` 校验
- 仅单一管理员，账号存在 `settings` 表（`admin_user` / `admin_pass_hash`）
- Session：`session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax', 'path' => APP_BASE])`
- 登录成功后 `session_regenerate_id(true)`（防会话固定）
- **后台入口随机化**：`install.php` 生成 8 位随机串，把 `panel-template.php` 重命名为 `panel-<随机串>.php`。访问 `panel.php` / `admin.php` 一律 404。该串存在 `config.php`（不在数据库，避免设置页泄露）
- 不提供「找回密码」，忘记密码需直接改数据库

### 9.2 限流

- `login_attempts` 按 `ip_hash` 统计：15 分钟内失败 ≥ 5 次 → 返回 429 并锁定 15 分钟
- 登录接口额外加固定 300ms 延时（拖慢暴力破解，成本可忽略）
- 密码错误无论账号是否存在都返回同一文案

### 9.3 CSRF

- 登录成功时生成 `csrf_token` 存 Session，随登录响应返回给前端
- 所有 `admin.*` 写操作必须带 `X-CSRF-Token` 请求头，服务端 `hash_equals` 比对
- 缺失/不匹配 → 403

### 9.4 文件与目录防护（无 `.htaccess` 时的替代方案）

`.htaccess` 不可用，因此**不能依赖它保护目录**。替代方案：

1. **`data/` 下的所有数据文件使用 `.php` 扩展名**，首行写 `<?php exit; ?>`，其后才是 JSON/SQL 内容。即使被直接访问，PHP 解释器在首行就退出。
2. **`config.php` 首行守卫**：`if (!defined('APP_BOOT')) { http_response_code(404); exit; }`
3. **`app/` 目录下所有 PHP 文件同样加守卫**，防止被直接请求执行。
4. **`data/` 与 `app/` 内各放一个 `index.html`（空文件）**，防止目录列举（部分服务器默认开启）。
5. **上传临时目录 `uploads/tmp/`** 用完立即删除文件；文件名强制随机，扩展名白名单。
6. 导出文件在 `data/export/` 下同样用 `.php` 守卫头。导出后建议立即下载并删除远端文件。

### 9.5 输入校验与输出

- 所有写接口显式校验：类型、长度、枚举值、必填
- SQL 全部用 PDO 预处理，**禁止字符串拼接**
- `slug` 白名单：`/^[a-z0-9]+(-[a-z0-9]+)*$/`，长度 ≤ 191
- 标题/标签等输出到 HTML 时转义；Markdown 渲染结果必须过 DOMPurify
- `DOMPurify` 白名单：禁止 `script` / `iframe` / `on*` / `style` 中的 `expression`；允许 `img` `a` `pre` `code` `table` `h1-h6` `ul` `ol` `blockquote` `hr` `br` `strong` `em` `del` `input[type=checkbox]`

### 9.6 图片隐私（EXIF）

**问题**：手机原图带 GPS 坐标，上传后任何人可读出你的位置。

**双防线**：

1. **前端剥离（无条件强制）**：钛盘的 `stripsExif()` 为 `false`，即图床**不会**帮你剥离元数据，因此前端剥离不是可选优化而是必需步骤。
   - 选图后立刻用 Canvas 重绘 → `toBlob('image/webp', q)`，元数据在一次重绘中全部丢失。
   - 由于双尺寸方案（§3.2 张力 C）本就要在浏览器里画两次，**剥离 EXIF 是顺带完成的，没有额外成本**。
   - 缩略版：长边 800px，q=0.80；原图版：长边 2560px，q=0.85。
   - 浏览器不支持 WebP 时回退 JPEG。
   - **例外**：走 `ManualAdapter` 手动登记的外链图片**不会**被剥离。后台登记表单下方必须显示提示：「手动登记的图片未经过隐私处理，若含地理位置信息请先自行清理」。
2. **图床防盗链**：✅ **已确认钛盘无防盗链**（T3）。前台图片不会被 Referer 拦截，无需配置白名单。原 R4 风险撤销。

   > **反过来说**：这也意味着**你的图片可以被任何人盗链**，流量与配额消耗算在你头上。若日后发现异常流量，`list_of_direct` + 按 `size` 排查是可行的手段。当前不做处理（个人博客规模下不值得为它增加复杂度），仅记录。

### 9.7 错误处理与信息泄露

- `config.php` 中 `APP_DEBUG` 默认 `false`
- **未登录用户**：任何异常只返回 `{"ok":false,"error":{"code":"INTERNAL_ERROR","message":"服务器内部错误"}}`，不暴露文件路径、SQL、堆栈
- **已登录管理员**：可额外看到错误详情
- 所有异常写 `data/logs/error-YYYY-MM.log.php`（带守卫头）
- 生产环境 `display_errors=0`，在 `bootstrap.php` 中强制设置

### 9.8 安全响应头

```php
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN          // share.php 需 SAMEORIGIN，不要 DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy:
  default-src 'self';
  img-src 'self' data: https://download.wuyuge.cn;   // 钛盘直链域名已确认，不再放宽到 https:
  media-src 'self' https:;                           // 视频源可切换，暂时放宽
  frame-src player.bilibili.com www.youtube.com;
  script-src 'self';
  style-src 'self' 'unsafe-inline';   // Vue 内联样式需要
  connect-src 'self';
```

> **收紧建议**：`img-src` 原本因「图床域名未知」而放宽到 `https:`，现在直链域名确认为 `download.wuyuge.cn`，已收窄为白名单。但**手动登记的外链图片（`is_external = 1`）会被这条 CSP 拦掉** —— 若你要用外部图片，需把对应域名加进白名单，或退回 `https:`。这是安全性与灵活性的取舍点，实现时在设置页提供「图床域名白名单」配置项。

> CSP 中的 `unsafe-inline` 对 style 是妥协项。若日后觉得过宽，可改为 nonce 方案。

---

## 10. 边界情况与错误处理清单

实现时逐条对照，每一项都必须有明确行为。

### 10.1 内容

| 情况 | 行为 |
|---|---|
| slug 重复 | 返回 `SLUG_TAKEN`，前端提示并建议 `-2` 后缀 |
| slug 变更 | 旧 slug 写入 `slug_redirects`；访问旧 slug 返回 `redirect_to` |
| 标题为空 | 校验失败 |
| 标题超长 | > 255 字符拒绝 |
| 正文为空 | 文章允许（可能只是占位），但后台提示 |
| `published_at` 为未来 | 保存为 `published`，前台不可见；后台列表标记「定时中」 |
| 发布时未填 `published_at` | 自动填 `NOW()` |
| 摘要留空 | 自动从 `body_text` 截取前 120 字 |
| 封面缺失 | 列表页用类型对应的默认占位图 |
| 标签数量 | 单篇上限 10 个，超出拒绝 |
| 标签被删除 | 先提示「影响 N 篇内容」，确认后仅删关联，不删内容 |
| 标签重名合并 | 把 `content_tags` 指向保留标签，去重后删除源标签 |
| 删除正在被引用的标签 | 允许，`content_tags` 级联删除 |

### 10.2 媒体

| 情况 | 行为 |
|---|---|
| 上传超 `upload_max_filesize`（100M） | 前端按服务端下发的限制值预检并阻止；后端二次校验，返回 `UPLOAD_TOO_LARGE` |
| 触发钛盘 `status=2`（文件过大） | 抛出 `UPLOAD_TOO_LARGE`，提示「文件超出上限，请压缩后重试」。**预期极少触发**（T2 未测出钛盘上限），但文案仍须存在，不做静默失败 |
| 触发 `status=3`（服务器繁忙） | 自动退避重试 2 次（1s / 3s），仍失败提示稍后重试。**不写错误日志**（是对方问题，非我方缺陷） |
| 触发 `status=4`（空间不足） | 后台首页红色告警 + **引导去「孤立资源」清理**（调用 `revoke($asset, deleteFile: true)` 释放空间）。上传失败并提示 |
| 触发 `status=5`（配额不足） | 后台首页红色告警，文案必须写明**「按天重置，次日自动恢复」**。已确认无绕开手段（T9），只能等 |
| 触发 `status=6`（API Key 无效） | 后台提示去设置页检查 Key；日志记录但**不回显 Key 本身** |
| 触发 `status=0`（无效请求） | 视为**代码缺陷**，日志记录完整请求参数（去除 key）与原始响应，便于排查 |
| 双尺寸上传中，第一张成功第二张失败 | 不写库。回滚已上传的记录标记为「孤立资源」，下次上传时按 `sha256` 复用。**不允许出现只有缩略图没有原图的记录** |
| **上传总耗时接近 30 秒** | 钛盘是两步流程且双尺寸 → **单张图 4 次远程调用**。单次 cURL 超时 8 秒、总计约 25 秒上限。前端在 25 秒主动 abort 并提示；服务端每次调用前比对剩余预算，不足则立即中止并返回已完成的进度 |
| `link_add` 返回 `1005`（尚未就绪） | 退避 1s / 2s / 4s 重试 3 次。仍失败则**保留 UKEY 抛 `STORAGE_BUSY`**，后台提供「补建直链」按钮，避免重传整张图 |
| 4 次调用中前 3 次成功、第 4 次失败 | 不写库。已上传的 UKEY 记录到日志，后台「孤立资源」可见；`sha256` 去重可让下次上传复用它们 |
| 图床未配置（`cdn_base` 或 Key 为空） | `admin.asset.upload` 返回明确提示，UI 引导到设置页；`asset_url()` 返回 `null` → 前台走占位图 |
| 相同图片重复上传 | 用 `sha256` 命中已有记录，直接复用不重传（命中时同时复用缩略版） |
| 图片直链失效（404/410） | 巡检标记 `missing`，后台标红，前台 `@error` 回退占位图 |
| 巡检返回 403 | **T3 已确认钛盘无防盗链**，故 403 不再是「预期内」的状态，应写 `last_error = 'HTTP 403'` 并作为异常暴露出来（可能是直链被平台封禁或域名策略变更），而不是静默归入 `missing` |
| 巡检网络超时 | 超时视为 `unknown`（不是 `missing`），不误判为失效 |
| 外链是 `http://` | 站点是 HTTPS → 会混合内容报警。**登记时即拒绝非 https 的图片 URL** |
| **误用非永久存储模式** | `expires_watch` 任务发现 `storage_model != 99` 且临近/已过期 → 后台红色告警。这是保险丝，正常流程不会触发 |
| 图床直链域名变更 | 改 `ttttt_direct_template` 一处，再用「批量重建直链」覆盖存量 `direct_url`；手动登记的外链（`is_external=1`）不受影响 |
| 删除被引用的图片 | `ref_count > 0` 时拒绝删除记录，提示引用位置 |
| **彻底删除内容时** | 依次对原图与缩略图调用 `revoke($asset, deleteFile: true)`（即 `link_del` + `delete=1`，**真正释放空间**）→ 再删记录。**撤销失败不阻塞删除**：记录照删，失败项写日志并进后台「待清理」列表 |
| **漏传 `delete=1`** | 这是本模块最隐蔽的坑：链接失效、前台正常、**但空间永远不释放**，直到某天 `status=4` 突然爆出。因此该参数在 `revoke()` 内部写死，**不作为调用方可选项** |
| `link_del` 返回 `1003`（直链不存在） | **视为成功**（幂等）。重复删除、并发删除都不应报错 |
| `revoke()` 返回失败 | 不抛异常、不阻塞主流程。写日志 + 进后台「待清理」列表，提供「重试撤销」按钮 |
| `list_of_direct` 分页未取完 | `listLinks()` 内部循环翻页直到某页返回空。**只取第一页会导致「远端缺失」的假阳性**，进而误判大量图片失效 |
| `list_of_direct` 发现远端有、库里没有的直链 | 进后台「孤立直链」列表，提供批量撤销（带 `delete=1`）入口 |
| `list_of_direct` 请求失败 | 记为 `unknown`，降级为逐条 `HEAD` 探测（已确认无防盗链，无需 `Range` 兼容层） |
| `asset_url()` 收到 UKEY 但 `cdn_base` 为空 | 返回 `null` 而非拼出错误 URL，前台显式走占位图 |
| 图片只有 `thumb_rel_path` 没有 `rel_path` | 只可能出现在异常中断时（见上「4 次调用中前 3 次成功」）。`asset_url()` 回退到缩略版并标红，**不允许前台空图** |

### 10.3 视频

| 情况 | 行为 |
|---|---|
| B站 BV 号填成完整 URL | 服务端自动提取 BV 号 |
| 视频被 UP 主删除 | iframe 显示 B站官方提示；后台巡检**不检查** iframe（反爬），改为人工感知 |
| YouTube 国内不可访问 | 详情页对 `provider=youtube` 显示「需科学上网」提示 |
| 未填封面 | 强制必填（无 FFmpeg 无法自动抽帧），校验拒绝 |
| 未填宽高比 | 默认 `16:9` |
| mp4 直链不支持 Range | 表现为进度条不可拖；后台「视频自检」给出提示 |

### 10.4 系统

| 情况 | 行为 |
|---|---|
| 数据库连接失败 | 返回中性错误页，不暴露连接串 |
| 伪 cron 任务抛异常 | 只写日志，绝不影响当前请求 |
| 伪 cron 锁未释放 | 60 秒后可被抢占 |
| 缓存文件损坏 | JSON 解析失败 → 视为未命中，重新生成 |
| 空间配额满 | 写入前检查，失败写日志并返回 `STORAGE_ERROR` |
| 时段跨天 | 一律用服务器 `Asia/Shanghai` 时间 |
| 前端路由未命中 | 渲染 404 页 |
| API 返回非 JSON（如 PHP 警告混入输出） | 前端捕获解析失败并提示，同时该情况记为需修复的缺陷 |
| 用户直接访问 `api.php`（无 action） | 返回 400 并列出可用 action（开发期）/ 仅返回 400（生产） |

---

## 11. 开发与部署

### 11.1 本地开发环境（XAMPP）

1. 安装 XAMPP（PHP 8.1 版本），项目放 `C:\xampp\htdocs\blog\`
2. 建库 `blog_dev`，导入 `app/schema.sql`
3. 复制 `config.sample.php` → `config.php`，填本地数据库信息，`APP_DEBUG = true`
4. 前端：`npm install` → `npm run dev`（Vite dev server 代理 `api.php`）
5. 发布前：`npm run build`，把 `dist/` 内容复制到站点根

**本地与线上的差异必须注意**：
- 本地 Apache **有**重写，线上**没有** → 因此本项目**不使用任何重写**，本地也保持一致。若本地为了图省事开了重写，务必在无重写模式下再测一遍。
- 本地 `fastcgi_finish_request()` 不存在（Apache mod_php）→ 伪 cron 走 5 秒预算分支，这是线上以外唯一需要真机验证的差异。

### 11.2 上传前检查清单（强制）

每次部署前逐条执行：

- [ ] `php -l` 检查所有改动的 PHP 文件（XAMPP 的 php.exe 可直接调）
- [ ] 本地 XAMPP 打开被改动的页面，确认无报错、无警告
- [ ] 若改动了数据库结构，先在本地跑一遍完整建表 SQL
- [ ] **先传 `/dev/` 子目录**（若已配置），在线上验证通过后再覆盖主目录
- [ ] 改动涉及 `config.php` 时，确认没有把本地配置覆盖上去
- [ ] 确认 `APP_DEBUG` 为 `false`
- [ ] 若涉及前端，确认 `npm run build` 产物已生成且 `base` 为 `./`

### 11.3 首次安装流程

1. 上传全部文件到 `/blog/`
2. 浏览器访问 `install.php`
3. 向导依次：环境探测（展示 §2.1 的实测值并核对是否与文档一致）→ 填数据库信息 → 建表 → 设管理员密码 → 设站点名 → **填写钛盘 API Key、`cdn_base` 与 `ttttt_direct_template`（可跳过，后续在设置页补；跳过时上传功能不可用并给出提示）** → 自动探测并写入 `APP_BASE` → 生成后台入口随机名 → **提示删除 `install.php`**
4. `install.php` 在检测到已安装（`settings` 表存在）时自我禁用

### 11.4 备份流程

- 后台首页显示「距上次导出 N 天」，> 30 天变红
- 导出 `admin.export.sql`（结构与数据）→ 本地保存 → **存一份到网盘**
- 导出成功后服务端记录时间（写 `settings.last_export_at`）
- `data/export/` 中的文件在下载后建议手动删除，避免被猜测路径

---

## 12. 风险登记表

按严重程度排序。

| # | 风险 | 影响 | 缓解措施 | 残余风险 |
|---|---|---|---|---|
| R1 | **数据库/内容丢失**（无自动备份） | 致命：文章全没 | 到期提醒导出 + 备份习惯 | 中：依赖自律 |
| R2 | **直接在线上改代码导致白屏** | 高：访客可见 | `php -l` 自检 + XAMPP 本地跑 + `/dev` 目录 + 错误详情只对管理员可见 | 中 |
| R3 | **钛盘是免费服务，长期可用性不受你控制** | 高：全站图片裂 | 方案已跑通并冻结，不再考虑更换。缓解靠**可恢复性**而非预防：`model=99` 永久 + `expires_watch` 保险丝 + `list_of_direct` 自检 + 存量 UKEY 可批量重建直链 + 适配器抽象（真出事时换实现即可，业务代码不动） | 中：做了能做的 |
| ~~R4~~ | ~~图床 Referer 防盗链~~ | — | **已撤销**：T3 实测确认钛盘无防盗链，前台图片不受 Referer 影响。反向影响见 §9.6（图片可被任意盗链） |
| R5 | **空间流量超限被停站** | 高 | 图片走图床、视频全部外链、本机零存储。**注意：若放弃双尺寸方案，列表页将拉原图，此项风险等级上升** | 低 |
| R6 | **SEO 缺失导致无自然流量** | 中：已接受 | `share.php` 分享卡片 + RSS + 主动分发 | 高（决策代价） |
| R7 | **钛盘 API Key 泄露** | 中高：被盗刷配额 | PHP 中转上传，Key 只在 `config.php`（**不入 settings 表，因该表会返回给前端**）；错误详情不暴露 | 低 |
| R8 | **EXIF 泄露家庭住址** | 中高：隐私 | 前端 Canvas 剥离（**钛盘不剥离，故为强制步骤**）。例外：手动登记的外链不剥离，后台需提示 | 低 |
| R9 | **钛盘单文件上限**（`status=2` 会触发） | 低：已确认可传长视频，未触顶 | T2 实测未能触顶上限，正常图片/视频不会触发。仍保留 `status=2` 的明确文案映射（§7.2.2），不做静默失败 | 低 |
| R10 | **LIKE 搜索随数据量变慢** | 中 | 标题走前缀索引；摘要/正文 LIKE 可接受；预留缓存开关 | 中（数据量小时无关） |
| R11 | **伪 cron 从未执行**（无访客无登录） | 中：外链巡检停摆 | 后台登录补跑 + 手动触发按钮 + 后台显示「上次运行时间」 | 低 |
| R12 | **PHP 单点无重写导致 URL 难看** | 低 | 已接受；`share.php` 提供短链接 | 低 |
| R13 | **Markdown 内联 HTML 造成 XSS** | 中 | `marked` → **DOMPurify 强制清洗** | 低 |
| R14 | **MySQL 5.6 兼容性**（已确认版本，风险从"未知"变为"受限"） | 中 | 全部索引前缀 ≤ 191 字符；不用 JSON 列 / 降序索引 / CTE / 窗口函数 / `SKIP LOCKED`；不依赖 DB 默认时间值。**新增字段时必须核对 767 字节前缀上限** | 低 |
| R15 | **子目录部署路径写死** | 中：迁移即全站 404 | `APP_BASE` 单点 + 全相对路径 + 改名验收测试 | 低 |
| R16 | **MySQL 5.6 已于 2021-02 EOL，不再有安全补丁** | 中高：已知漏洞永不复修 | 数据库不对公网开放；全部查询走 PDO 预处理；应用层严格校验入参；定期导出备份。**换主机时应优先选 MySQL 8.0** | 中：取决于主机商 |
| R17 | **双尺寸上传使上传耗时翻倍** | 中 | 前端显示进度与「正在处理图片…」；25 秒超时兜底；第二张失败时不写库（见 §10.2）。**若实测体验不可接受，退回单尺寸方案并接受流量上升** | 低 |
| R18 | **钛盘配额用尽**（`status=4` 私有空间不足 / `status=5` 当日上传量超配额） | 中高：无法再发布带图内容 | 后台首页告警；已发布内容不受影响；**`status=5` 按天重置，次日自动恢复**（告警文案必须写明，否则会误判为永久故障）；`status=4` 引导去「孤立资源」清理以腾空间。**`mrid` 绕行已被 T9 证伪，无其他规避手段** | 中 |
| R19 | **删直链不释放文件本体空间，`status=4` 缓慢累积** | 低：已确认可连带删除 | **T14 已关闭**：`link_del` 带 `delete=1` 会连带删除文件本体，空间即时释放。该参数在 `revoke()` 内部写死，不从调用方透出。残余风险仅剩「历史遗留资产」，由 `list_of_direct` + 「孤立资源」批量清理覆盖 | 低 |

---

## 13. 实施顺序

按依赖关系排列，每一步都可独立验证。

| 阶段 | 内容 | 验收标准 |
|---|---|---|
| **M0** | 骨架：`config.php` / `bootstrap.php` / `Db` / `Router` / `Response` / 错误处理 / 安全头 | 访问 `api.php?action=site.meta` 返回合法 JSON |
| **M1** | `install.php` 安装向导 + 建表 + 环境探测 | 本地全新库能一键装完 |
| **M2** | 认证：登录 / 限流 / Session / CSRF / 后台入口随机化 | 失败 5 次被锁；无 CSRF 的写请求 403 |
| **M3** | 内容 CRUD（三类）+ slug + 软删除 + 回收站 | 增删改查全部可用，回收站可恢复 |
| **M4** | 标签体系 + `content_tags` + 相关推荐 | 标签可增删改合并，相关推荐合理 |
| **M5** | 前台 SPA 骨架：路由 / 布局 / 列表 / 详情 / Markdown 渲染 | 三类内容都能正常浏览 |
| **M6** | 搜索 / 标签页 / 归档 / 分页 / 无限滚动 | 组合筛选正确 |
| **M6.5** | ~~钛盘剩余项确认~~ | ✅ **已完成**（2026-10-06）：T2 / T3 / T9 / T14 全部关闭，规格冻结。M6.5 从计划中移除，直接进 M7 |
| **M7** | 媒体库 + `TttttAdapter`（4 次调用流程 + `revoke()` + `listLinks()` 翻页）+ `ManualAdapter` + 双尺寸上传 + URL 四层解析 | 上传两个版本并各自建直链成功；`dkey`/`remote_name` 正确入库且直链按模板拼对；彻底删除时 `link_del` **带上了 `delete=1`** 且直链失效；`link_del` 返回 `1003` 不报错；`listLinks()` 能取到第二页；去重、引用计数、全部 `status` 码映射正确；4 次调用总耗时实测 < 20 秒 |
| **M8** | 伪 cron 调度器 + 外链巡检 + 浏览统计 + 缓存层 | 锁与分批正确，任务异常不影响主流程 |
| **M9** | 首页定制布局 + 拖拽排序 + 批量操作 | 拖拽结果持久化，首页正确渲染 |
| **M10** | `share.php` / `feed.php` / 404 / 暗色模式 / 移动端适配 | 微信分享有卡片；手机端可用 |
| **M11** | 导出备份 + 备份提醒 + 日志查看 + 系统信息页 | 能导出可用的 SQL 并在本地恢复 |
| **M12** | 部署上线 + 全量验收（含 §4.2 改名测试） | 见下 |

### 13.1 上线验收清单

- [ ] 站点目录改名后全站正常（仅改 `APP_BASE`）
- [ ] 无重写环境下，任何页面刷新都不 404
- [ ] 前台看不到任何草稿、回收站、未来时间的内容
- [ ] 未登录时任何异常都不泄露路径/SQL/密钥
- [ ] 后台入口随机名生效，`/admin.php` 返回 404
- [ ] 密码错误 5 次被锁 15 分钟
- [ ] 用**真实域名**（非 localhost）访问前台，所有图片正常显示
- [ ] 数据库中不存在 `storage_model != 99` 的资产（防止图片到期消失）
- [ ] 上传一张图，确认列表页加载的是缩略版（Network 面板核对文件大小）
- [ ] 上传后 `assets.remote_dkey` / `remote_name` / `direct_url` 三者都已正确写入
- [ ] 彻底删除一篇带图内容后，原图与缩略图的直链**均已失效**（浏览器直接访问应 404 或 403）
- [ ] **删除前后在钛盘网页端核对已用空间确实下降** —— 证明 `delete=1` 生效（T14 的操作性验收）
- [ ] `list_of_direct` 返回的直链集合与库中记录一致（无「库有远端无」的缺失项）
- [ ] 手机端（真实设备）三类列表与详情均可读可播
- [ ] 分享链接到微信，卡片有标题/摘要/封面
- [ ] `install.php` 已从线上删除
- [ ] `APP_DEBUG = false` 已确认
- [ ] 至少完成一次导出备份并存到本地

---

## 14. 待定事项（开发前需确认）

### 14.1 已关闭的阻塞项（保留记录）

2026-10-06 实测验证，**图床方案已跑通并冻结，不再更换**：

| 原编号 | 事项 | 结论 |
|---|---|---|
| T1 | `link_add` 返回的直链构成 | ✅ 返回 **`dkey` + `name`**（**不是 `filename`**），拼成 `https://download.wuyuge.cn/files/{dkey}/{name}`，模板写入 `ttttt_direct_template` |
| T11 | Key 的参数名 | ✅ 确认为 **`key`**（一串密钥，非 token） |
| T12 | SSL 证书是否有效 | ✅ 已跑通。保持 `CURLOPT_SSL_VERIFYPEER = true` |
| T2 | 钛盘单文件大小上限（`status=2` 阈值） | ✅ **未测出上限**，长视频也能传。压缩仍做，但按流量考量而非为了过闸 |
| T3 | 钛盘是否有防盗链 / 是否支持 HEAD | ✅ **无防盗链**。前台图片不受 Referer 影响；巡检可用最朴素的 `HEAD`。**R4 撤销** |
| T9 | 传 `mrid`（私有空间）是否绕开日配额 | ✅ **不能绕开**。日配额是账户级的，与存储位置无关。不发送 `mrid` |
| T14 | 删直链是否释放文件本体空间 | ✅ **可以释放**：`link_del` 的 `delete=1` 会连带删除文件本体。**R19 降为低**，但 `delete=1` 不可漏传 |

### 14.2 仍需实测的项

**无阻塞项。** 原 T2 / T3 / T9 / T14 已于 2026-10-06 全部关闭，M6.5 阶段取消（见 §13）。

唯一开放的是 **T13（钛盘账户的配额总额与计费方式）** —— 它只影响 R18 的严重程度，不影响任何设计决策，列为 §14.3 的非阻塞项。

### 14.3 非阻塞待定项

| # | 事项 | 影响范围 | 决策时点 |
|---|---|---|---|
| T4 | 空间总容量与月流量额度 | 是否要限制媒体数量 | M1 安装时手动填写 |
| T5 | 是否启用代码块语法高亮（+约 30KB 体积） | 前端体积与阅读体验 | M5 时决定 |
| T6 | 是否真的配置 `/dev/` 开发子目录 | 部署流程与上线风险 | M12 之前 |
| T7 | 站点名 / 域名 / 备案号 | 站点头部与页脚展示 | M1 时填写 |
| T8 | 是否启用评论（若有变） | 触发附录 A 的建表 | 随时 |
| T13 | 钛盘账户的配额总额与计费方式 | R18 的实际严重程度 | M7 之前 |
| **T10** | **是否保留双尺寸方案**（§3.2 张力 C） | 上传耗时 vs 列表页流量。放弃则 R5 等级上升 | M7 时按实测体验决定 |

---

## 附录 A：`comments` 表设计（冻结，第一版不建表）

```sql
CREATE TABLE comments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_id  BIGINT UNSIGNED NOT NULL,
  parent_id   BIGINT UNSIGNED NULL COMMENT '支持一级嵌套回复',
  author_name VARCHAR(64) NOT NULL,
  author_email_hash CHAR(64) NULL COMMENT '仅存哈希，用于头像，不存明文邮箱',
  author_site VARCHAR(255) NULL,
  body_text   TEXT NOT NULL COMMENT '纯文本，禁止 HTML',
  status      ENUM('pending','approved','spam') NOT NULL DEFAULT 'pending',
  ip_hash     CHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_content_status (content_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

启用时需同步实现：蜜罐字段、频率限制（同 IP 每分钟 1 条）、关键词黑名单、`settings.comments_enabled` 开关、后台审核页。

---

## 附录 B：配置项清单（`settings` 表）

| key | 默认值 | 说明 |
|---|---|---|
| `site_name` | — | 站点名 |
| `site_desc` | — | 站点描述（用于 OG 与 RSS） |
| `site_icp` | 空 | 备案号，留空则不展示 |
| `cdn_base` | 空 | 钛盘直链的域名前缀。**用途已收窄为「识别 URL 是否属于本图床」**（供 `normalize_asset_key()` 判断），不再参与 URL 构造 |
| `ttttt_direct_template` | `https://download.wuyuge.cn/files/{dkey}/{name}` | **直链拼接模板**，含 `{dkey}` 与 `{name}` 两个占位符（**不是 `{filename}`**）。已实测确认，正常无需改动；仅当钛盘更换直链域名时才需要在此改一处 |
| `admin_user` | `admin` | 管理员用户名 |
| `admin_pass_hash` | — | 密码哈希 |
| `cache_enabled` | `0` | 文件缓存开关 |
| `cache_version` | `1` | 内容变更时自增 |
| `cron_probability` | `2` | 前台触发伪 cron 的百分比 |
| `comments_enabled` | `0` | 评论开关（第一版恒为 0） |
| `view_dedup_salt` | 每日轮换 | 浏览去重盐 |
| `last_export_at` | 空 | 上次导出时间，用于备份提醒 |
| `storage_driver` | `ttttt` | 图床适配器：`ttttt` / `manual` |
| `ttttt_model` | `99` | 钛盘存储模式。**恒为 99（永久），不在后台暴露为可选项** |
| `ttttt_key_param` | `key` | API Key 的参数名。**已确认就是 `key`**；保留此项仅作兜底，正常无需改动 |
| `ttttt_mrid` | 空 | 目标文件夹 ID。**T9 已证伪绕日配额的作用，`TttttAdapter` 不发送该参数**。仅保留作将来钛盘开放文件夹隔离能力时的开关 |
| `ttttt_max_bytes` | `104857600`（100M） | 钛盘单文件上限。**T2 未测出上限**，此值改按本机 `upload_max_filesize` 取齐（§2.1），真正的闸门是 PHP 而非钛盘 |
| `image_thumb_edge` | `800` | 浏览器端生成的缩略图长边（px） |
| `image_full_edge` | `2560` | 浏览器端生成的原图长边（px） |
| `page_size` | `10` | 列表默认每页条数 |

`config.php`（不入库、不上传覆盖）单独持有：

| 常量 | 说明 |
|---|---|
| `DB_*` | 数据库连接信息 |
| `APP_BASE` | 子目录前缀，如 `/blog` |
| `APP_DEBUG` | 生产环境必须为 `false` |
| `ADMIN_ENTRY` | 后台入口随机名（8 位字符串） |
| **`TTTTT_API_KEY`** | 钛盘 API Key。**必须在这里而不是 `settings` 表** —— `settings` 会经 `admin.setting.get` 返回给前端，而 Key 泄露 = 别人盗刷你的配额（R7） |
| `IP_SALT` | 浏览去重与登录限流的 IP 哈希盐 |
