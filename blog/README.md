# 个人博客 · v1

PHP 8.1 零依赖后端 + Vue 3 SPA 前端。

规范见 [`docs/SPEC.md`](../docs/SPEC.md)，实现蓝图见 [`docs/TECHNICAL_PLAN.md`](../docs/TECHNICAL_PLAN.md)。
**有冲突时以 SPEC.md 为准。**

---

## 上传清单：哪些传、哪些不传

这是部署时最容易出错的一步 —— 传多了会把源码和密钥暴露到公网，传少了页面直接打不开。

### ✅ 必须上传

| 路径 | 说明 |
|---|---|
| `*.php`（站点根） | `index.php` / `api.php` / `share.php` / `feed.php` / `panel-template.php` / `install.php` |
| `app/` | 全部（含 `schema.sql.php`、`Storage/`、`actions/`、各目录的 `index.html` 守卫） |
| `data/` | **目录本身要存在**（`cache/` `logs/` `export/` 及其 `index.html`），里面的运行文件不用传 |
| `uploads/` | 同上，目前只用到 `tmp/`（见 `app/Asset.php` 顶部的差异说明） |
| `assets/` | `npm run build` 产物 |
| `index.html` `panel.html` `theme-boot.js` | `npm run build` 产物，与 `assets/` 一起放在**站点根** |
| `config.php` | 线上自己建（首次安装时由 `install.php` 生成），**不要**把本地的传上去 |

### ❌ 不要上传

| 路径 | 原因 |
|---|---|
| `src/` | 前端源码，浏览器用不到；传上去等于公开源码 |
| `node_modules/` | 体积巨大且无用 |
| `dist/` | 它的**内容**要传，但目录名不用保留 —— 直接把 `dist/*` 铺到站点根 |
| `.gitignore` `README.md` | 无害但没必要 |
| `config.sample.php` | 模板文件，线上不需要 |

> `config.php` 已在 `.gitignore` 里。**它一旦被传到公网，等于数据库密码和钛盘 API Key 一起泄露。**

---

## 本地开发

**第一次装环境（XAMPP、php.ini、Apache Alias、建库、全量 `php -l`）见
[`docs/LOCAL_SETUP.md`](../docs/LOCAL_SETUP.md)** —— 那里是从零开始的完整步骤。

环境装好之后，日常只有两件事：

```bash
# 后端：XAMPP 控制面板启动 Apache + MySQL，常驻即可
#       站点地址 http://localhost/blog/（Apache Alias 指向本目录，代码只保留一份）

# 前端
npm install      # 首次
npm run dev      # Vite dev server：http://localhost:5173
```

Vite 的代理目标默认是 `http://localhost/blog`（见 `vite.config.js`），**与上面的 Alias 对应**。
换过 Alias 路径的话用 `DEV_API_TARGET=http://localhost/你的路径 npm run dev` 覆盖。

发布：

```bash
npm run build    # 产物在 ../dist/，把 dist/* 铺到线上站点根
```

本地 Apache **有** URL 重写，线上**没有**。本项目一律不使用重写（前台走 Hash 路由、
API 走 `api.php?action=xxx`），本地也不要额外开重写 —— 否则测出来的东西线上不成立。

本地 Apache 用 mod_php，没有 `fastcgi_finish_request()`，伪 cron 会走 5 秒预算分支。
这是本地与线上**唯一**需要真机验证的行为差异。

> ⚠️ 本地跑一次 `install.php` 会按设计把 `panel-template.php` 改名成 `panel-<随机名>.php`。
> 跑完记得改回来：`mv panel-*.php panel-template.php` —— 否则下次部署没有入口模板。

---

## 首次安装

1. 按上面的清单上传（**含 `install.php`**）
2. 浏览器访问 `install.php`，跟着向导走：环境探测 → 数据库 → 建表 → 管理员密码 →
   站点信息 → 钛盘 API Key / 直链模板（可跳过，跳过则上传功能不可用并会提示）→
   自动写入 `APP_BASE` → 生成后台随机入口名
3. **立刻从线上删除 `install.php`**
4. 后台地址是 `panel-<8位随机>.php`，名字记在 `config.php` 的 `ADMIN_ENTRY` 常量里。
   忘了就去 `config.php` 里翻。
5. 后台「设置」页补上钛盘 API Key 之外的项；**Key 只能写 `config.php`**，
   因为它会经 `admin.setting.get` 返回给浏览器

> `install.php` 检测到 `settings` 表已存在时会自我禁用，但这只是保险 ——
> 删除它才是正解。

---

## 上线前检查（SPEC §11.2）

- [ ] 每个改动的 PHP 文件都跑过 `php -l`
- [ ] 本地 XAMPP 打开改动过的页面，无报错、无警告
- [ ] 改过表结构的话，先在本地跑一遍完整建表 SQL
- [ ] `APP_DEBUG` 为 `false`
- [ ] 前端是 `npm run build` 的产物，且 `dist/` 内容已铺到站点根
- [ ] `config.php` 没有被本地配置覆盖
- [ ] **线上没有 `install.php`**

---

## 部署后的三个常见故障

**页面白屏、控制台报 CSP 违规。**
CSP 在 `app/Response.php::securityHeaders()` 里。图片来自没登记的域名 → 加到设置页的
「图片域名白名单」；视频嵌入来自没登记的域名 → 加到「视频嵌入域名白名单」。
后者表现为**一个安静的白框**，页面上没有任何报错，只在控制台留一条违规记录。

**上传图片成功但图不显示。**
检查直链模板的两个占位符是不是 `{dkey}` 和 `{name}` —— 不是 `{filename}`。
设置页有即时校验。

**后台 404。**
入口名是随机的，去 `config.php` 找 `ADMIN_ENTRY`。文件被误删的话，
把 `panel-template.php` 改名为 `panel-<新的随机名>.php` 并同步改 `ADMIN_ENTRY`。

---

## 备份

后台「备份」页点导出，得到一份含结构与数据的 SQL。
**下载到本地后另存一份到网盘** —— `data/export/` 里不留文件是故意的，路径会被猜到。

后台首页会显示「距上次导出 N 天」，超过阈值变红。
