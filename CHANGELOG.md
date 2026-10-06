# 更新日志

本项目遵循[语义化版本](https://semver.org/lang/zh-CN/)，格式参考
[Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

## 版本号怎么用

| 位 | 什么时候进位 | 例子 |
|---|---|---|
| **主版本** | 数据库表结构变更、API 契约破坏性调整、部署方式改变（老库需要手工迁移） | `1.x` → `2.0.0` |
| **次版本** | 新增功能或新增接口，向后兼容 | `1.0` → `1.1.0` |
| **修订号** | 缺陷修复、文案与样式调整，不改变对外契约 | `1.0.0` → `1.0.1` |

**每次发版前必做**：先在 `docs/DEBUG.md` 里补登记这一轮修掉的缺陷（编号只增不改），
再把摘要挪到本文件对应版本下。DEBUG 记「怎么坏的」，CHANGELOG 记「什么时候好的」。

---

## [1.0.0] — 2026-10-06

首个正式版本。功能范围以 `docs/SPEC.md`（v1.4，已冻结）为准，实现蓝图见
`docs/TECHNICAL_PLAN.md`。

### 新增

**内容**
- 三类内容：文章（Markdown）/ 视频（B站、YouTube 嵌入或 MP4）/ 图片，共用 `contents` 主表 + 三张扩展表
- Slug 唯一约束与历史 Slug 重定向（`slug_redirects`），改标题不会断链
- 标签系统：多对多、合并、删除前显示影响面
- 回收站：软删除 + 30 天保留，超期只提醒不自动清理
- 定时发布（`published_at` 取未来时间即生效）

**媒体**
- 钛盘图床四接口接入（`upload_cli` / `link_add` / `link_del` / `list_of_direct`）
- 双尺寸图片：浏览器端 Canvas 重绘两次产出原图 + 缩略图，**同时剥离 EXIF**（钛盘 `stripsExif()` 为 `false`，这一步是隐私必需项而非优化）
- 手动登记外链通道（`ManualAdapter`）
- 资产巡检与引用计数对账

**站点**
- 前台 SPA：首页 / 文章列表 / 详情 / 归档 / 标签 / 图库 / 视频 / 搜索
- Hash 路由 —— 线上无 URL 重写能力，故全程不使用 rewrite
- RSS 与 JSON Feed、独立的分享页（`share.php`）
- 首页布局可拖拽排序（`home_blocks`）
- 亮/暗主题切换

**运维**
- 伪 cron：8 个后台任务（资产巡检、过期提醒、浏览聚合与清理、缓存清理、备份提醒、登录记录清理、回收站提醒），概率触发 + 12 秒预算分批 + `register_shutdown_function` 兜底
- 数据库导出备份
- 错误日志按天分文件（首行 `<?php exit; ?>` 防直接读取）
- 后台仪表盘：系统信息、任务状态、备份时效

**安全**
- 会话 + CSRF 双校验，登录失败限流（5 次 / 15 分钟窗口）
- 后台入口随机化：`panel-template.php` 安装时重命名为 `panel-<8位随机>.php`
- CSP 含图片域名与视频嵌入域名白名单，白名单值经 `admin_normalize_domains()` 净化（防 CSP 指令注入与 CRLF）
- 全量 PDO 预处理，无字符串拼接
- 密钥只存 `config.php`（`settings` 表会经接口回传前端，故 API Key 不入表）
- 钛盘 `link_del` 内部固定带 `delete=1`，`CURLOPT_SSL_VERIFYPEER` 恒为 `true`

### 修复

自测阶段共发现并修复 **18 个缺陷**，全部登记在 `docs/DEBUG.md`。按严重度归类：

**阻断级（功能完全不可用）**
- `app/Storage/` 下 4 个类无法自动加载 —— 自动加载器把类名映射成 `app/<类名>.php`，而这几个类按职责放在子目录且项目全无命名空间。表现为页面全绿、存储层全废，错误只进日志
- `install.php` 第二步建表失败时页面无反应（三处叠加：`$error` 从未渲染、`config.php` 被 require 两次导致 header 失效、向导守卫把中途失败的用户锁在外面）
- `\R` 正则把 UTF-8 汉字从中间劈开导致建表报 1064（`\R` 在非 UTF-8 模式下匹配字节 `0x85`，而它是汉字三字节序列的常见中间字节）
- `Router::add()` 形参声明为 `string`，而 45 个调用点全传闭包

**高级**
- **永久删除内容时资产永远不被释放**（`releaseForContent()` 排在删 `image_meta` 之前，导致引用计数恒 ≥1，释放分支永不进入）—— 综合影响最严重的一个：钛盘的 `link_del(delete=1)` 一次都不发，空间永不释放，且全程静默
- `app/schema.sql` 可被公开下载，完整 DDL 泄露（线上同样暴露）
- `Asset::revoke` 不查 `supportsRevoke()` 能力位，对外链资产报「撤销失败，待重试」的假错误
- 撤销失败仍删 `assets` 记录，丢失 UKEY 使远端文件永久占位且无从重试
- Markdown 正则分隔符冲突（`#` 同时做分隔符与模式内容），含链接的文章让 `share.php` / `feed.php` 双双 500
- `admin.export.list` 必定报错（`SHOW TABLES` 的列名是 `Tables_in_<库名>`，代码却按 `$r['table']` 取）
- `Asset::addExternal` 往 `assets` 表写入不存在的 `alt_text` 列（该列属于 `image_meta`）
- CSP 缺 `frame-src`，自定义播放器 iframe 被静默拦成白框
- 白名单域名未过滤即拼进响应头，可注入 CSP 指令 / CRLF

**中低级**
- 链接正则不认括号，维基百科这类 URL 被截断
- 行内图片语法 `![alt](url)` 未实现，渲染成 `!<a>`
- 媒体页「同步」结果文案与后端语义相反，误导排查方向
- Vite dev 代理指向 `http://localhost`，子目录部署下全部 404
- `content.restore` 一律恢复为草稿 —— **经确认属有意设计，非缺陷**，理由见 `docs/DEBUG.md` D-15
- 建表页文案称「创建 12 张表」，实际 14 张

### 已知限制

如实记录，避免下一版重复排查：

- **前端 SPA 未在浏览器中实机验证** —— 自测环境没有可用浏览器。JS/CSS 均可正常加载、构建产物中每个引用都能解析，但「Vue 是否真的渲染、页面内 API 调用是否符合预期」未经确认。**这是本版本最大的未覆盖面**，建议上线前人工过一遍所有页面。
- **图片上传链路未端到端测试** —— `admin.asset.upload` 需要 multipart 表单与真实图片文件，测试脚手架不支持。其下游（`Asset::addExternal`、资产引用计数、释放流程）已单独验证。
- **`admin.asset.revoke` 对钛盘通道只做了代码审查** —— 该操作会真带 `delete=1` 删除图床文件，测试账号存有 13 个无关真实文件，不宜在自测中触发。
- **评论功能未实现** —— SPEC §5.6 预留（`comments_enabled` 开关与蜜罐字段已建），v1 不启用。
- **代码高亮默认关闭** —— 未引入 highlight.js，SPEC T5 留待实测后再定。
- **伪 cron 在本地无 `fastcgi_finish_request()`**，走 5 秒预算分支；线上行为未实测。这是本地与线上唯一需要真机验证的差异。

### 部署注意

- 部署前必须本地执行 `npm run build`（构建产物不入库），并把 `dist/*` 铺到站点根
- `panel-template.php` 是本仓库中后台入口的**唯一来源**；本地跑过 `install.php` 后它会被重命名，记得改回来
- 首次安装后**立刻从线上删除 `install.php`**
- `config.php` 含数据库密码与钛盘 API Key，已在 `.gitignore` 中，**永不提交、部署时永不覆盖**
