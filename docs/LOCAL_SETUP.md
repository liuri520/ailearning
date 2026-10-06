# 本地开发环境搭建（Windows + XAMPP）

对应 SPEC §11.1。目标：能跑 `php -l`、能在本地把站点跑起来、能改完立刻看到效果。

**结论先放这儿**：装 XAMPP 8.1，站点用 Apache `Alias` 指向本仓库，前端另起 Vite。
全过程约 30 分钟，其中大部分是下载时间。

---

## 0. 装之前先知道的三件事

**① 安装路径不能有中文和空格。**
Windows 用户名是中文（`C:\Users\十三\`）的话尤其注意 —— 装进 `C:\xampp` 就没事，别改。
MySQL 对非 ASCII 路径的处理时好时坏，踩上就是一堆莫名其妙的 IO 错误。

**② 本地是 MariaDB，不是 MySQL。**
XAMPP 8.1 自带 MariaDB 10.4。它能覆盖绝大部分 SQL，但**测不出 MySQL 5.6 特有的限制**
（索引前缀 767 字节、DATETIME 默认值、无 JSON 列等，见 SPEC §2.2）。
真正的 5.6 兼容性只能在线上验证 —— 本地能过不代表线上能过，这一点不要记反。

**③ 本地报错会比线上多，这是设计如此，不是环境坏了。**
- 线上：`bootstrap.php` 强制 `display_errors=0`，警告只进 `data/logs/`
- 本地：`APP_DEBUG = true` 时 `ErrorHandler` 把**每一条 PHP 警告升级成异常**抛出来

所以本地看到 notice/deprecation 是好事，那正是要修的东西（SPEC §10.4 把「PHP 警告混入输出」列为缺陷）。
反过来，本地跑得干干净净，基本就能确定线上也不会有警告。

---

## 1. 依赖清单

代码只用这三个扩展，XAMPP 默认全开，**无需改动**：

| 扩展 | 用在哪 |
|---|---|
| `pdo_mysql` | 全部数据库访问（`app/Db.php`） |
| `curl` | 钛盘图床（`app/Storage/TttttAdapter.php`） |
| `mbstring` | `mb_strlen` / `mb_substr`（标题截断、标签校验收） |

不需要 `gd` / `imagick`（SPEC D8：图片处理全在浏览器里做）、不需要 `dom` / `simplexml`
（`feed.php` 的 XML 是手写字符串）。
`json` 和 `hash` 在 PHP 8 里是内置的。

**PHP 最低版本 8.1** —— `Response::ok()` / `fail()` 用了 `never` 返回类型，那是 8.1 才有的语法。

---

## 2. 下载 XAMPP

官方页：<https://www.apachefriends.org/download.html>

- **首选 8.1.x**，和线上版本一致。
- 官方页只列新版（8.2 / 8.3 / 8.4）时，两个选择：
  - 直接用最新版 —— 代码在 8.1 以上都能跑。差别只是 8.2+ 会对动态属性报 deprecation，
    那些警告线上 8.1 不会出现，属于噪音。
  - 严格对齐：去 <https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/> 找 `8.1.x` 目录，
    下 `xampp-windows-x64-8.1.x-...-installer.exe`。

> 国内访问 SourceForge 可能很慢。卡住的话去 XAMPP 官网看它列的镜像站。

---

## 3. 安装

- 装到 **`C:\xampp`**（默认路径，别改）
- 组件选择时**取消勾选**：`FileZilla FTP Server`、`Mercury Mail Server`、`Tomcat`、`Webalizer`
- **保留**：`Apache`、`MySQL`、`PHP`、`phpMyAdmin`

装完打开 XAMPP Control Panel，启动两个服务：

| 服务 | 端口 | 预期 |
|---|---|---|
| Apache | 80 / 443 | 变绿 |
| MySQL | 3306 | 变绿 |

**验证**：浏览器打开 <http://localhost/> 出现 XAMPP 欢迎页。

**起不来怎么办**

```bash
# 看端口被谁占了（Windows 上通常是 VMware / 迅雷 / 其他 Web 服务器）
netstat -ano | findstr ":80 "
tasklist | findstr "<上一步查到的 PID>"
```

Apache 起不来的话，也可以只改 `httpd.conf` 里的 `Listen 80` → `Listen 8080`，
但那样下面所有 URL 都要跟着加端口，不推荐。

---

## 4. 调 php.ini

编辑 `C:\xampp\php\php.ini`，改完**重启 Apache**：

```ini
upload_max_filesize = 100M     ; 对齐 SPEC §2.1 的线上实测值
post_max_size = 100M
max_execution_time = 30
display_errors = On            ; install.php 不走 bootstrap，靠这个才看得见报错
error_reporting = E_ALL
memory_limit = 256M
```

`display_errors` 只对 `install.php` 有意义 —— 应用代码走 `bootstrap.php`，
那里会强制关掉它（第 19 行），再靠 `ErrorHandler` 按 `APP_DEBUG` 决定是否抛出。
两套机制不冲突。

---

## 5. 让 Apache 指向本仓库

站点用 `Alias` 而不是复制到 `htdocs\` —— **代码只保留一份**，
不会出现「改了 D 盘、测的是 C 盘」这种最难查的问题。

编辑 `C:\xampp\apache\conf\httpd.conf`，在**文件末尾**追加：

```apache
# ── 博客开发站点：直接指向 D 盘工作目录 ──────────────────────
Alias /blog "D:/ailearning/ailearning/blog"

<Directory "D:/ailearning/ailearning/blog">
    Options FollowSymLinks
    AllowOverride None
    DirectoryIndex index.php index.html
    Require local
</Directory>
```

重启 Apache。

| 指令 | 为什么 |
|---|---|
| `AllowOverride None` | 我们**不使用任何 .htaccess 重写**（SPEC §2.1：线上重写不可用）。本地也保持一致，否则会测出线上不成立的结论。 |
| `Require local` | 只允许本机访问。连着公共 WiFi 时，局域网里的别人打不开你的半成品站点。 |
| `Options FollowSymLinks`（不带 `Indexes`） | 关掉目录列举，贴近线上假设。各目录里的 `index.html` 守卫仍然有效。 |

**验证**：打开 <http://localhost/blog/install.php>，应看到安装向导第①步的环境探测表。
这一步出来了，就说明 Apache + PHP + 路径全通了。

---

## 6. 建库

<http://localhost/phpmyadmin> → 左侧「新建」：

- 数据库名：**`blog_dev`**
- 排序规则：**`utf8mb4_unicode_ci`**

排序规则必须选对，不要用 `utf8mb4_general_ci` —— 全文搜索与去重的中文行为会不一样。

---

## 7. 跑一遍安装向导

<http://localhost/blog/install.php>

1. 环境探测 → 对照 SPEC §2.1 的实测值核对差异
2. 数据库：主机 `127.0.0.1`、端口 `3306`、库 `blog_dev`、用户 `root`、**密码留空**
3. **勾上「开启调试模式」**（写入 `APP_DEBUG = true`）
4. 建表 → 站点信息 + 管理员密码（记好）→ 图床配置可全部跳过
5. 完成页会显示后台入口名 `panel-<随机名>.php`

### ⚠️ 跑完必须做的一件事

`install.php` 按设计会把 **`panel-template.php` 改名**成 `panel-<随机名>.php`
（这是线上 SPEC §9.1 要的随机入口）。但仓库里那个文件是**模板**，
不改回来，下次部署到线上就没有入口模板了。

```bash
cd /d/ailearning/ailearning/blog && mv panel-*.php panel-template.php
```

同时会生成 `config.php`。它在 `.gitignore` 里，不会误提交 ——
**也绝不要把它传到线上**（它含数据库密码和钛盘 API Key）。

---

## 8. 全量 `php -l`

这就是 SPEC §11.2 里那条一直做不了的事。Git Bash：

```bash
export PATH="/c/xampp/php:$PATH"          # 让 php 命令可用（XAMPP 默认不进 PATH）
cd /d/ailearning/ailearning/blog
find . -name "*.php" -not -path "./node_modules/*" -not -path "./dist/*" -print0 \
  | xargs -0 -n1 php -l
```

PowerShell 版本：

```powershell
cd D:\ailearning\ailearning\blog
Get-ChildItem -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch 'node_modules|\\dist\\' } |
  ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```

期望结果：**28 个文件全部 `No syntax errors detected`**。

> `php -l` 只能查语法。它查不出 `\R` 匹配到汉字中间字节、形参声明成了 `string`
> 却传闭包这类**运行时**错误 —— 那两类只能靠真跑一遍。别把「-l 全过」当成「没问题」。

---

## 9. 日常开发

前后端**分开跑**（SPEC §11.1 就是这么设计的）：

```bash
# 终端 1：后端 —— Apache 常驻，不用管它
# 终端 2：前端
cd /d/ailearning/ailearning/blog/src
npm run dev
```

| | 地址 |
|---|---|
| 前台 | <http://localhost:5173> |
| 后台 | <http://localhost:5173/panel.html> |

Vite 的代理默认就是 `http://localhost/blog`（见 `vite.config.js`），**和上面的 Alias 正好对上**，
不用改配置 —— `api.php` 的请求会自动转发给 Apache。

改 PHP 即时生效；改 `.vue` 热更新。两边都不用重启。

只有一个例外：如果改了 `php.ini` 或 `httpd.conf`，要重启 Apache。

---

## 10. 排错

| 现象 | 原因 / 处理 |
|---|---|
| 打开页面一片空白，控制台报 CSP 违规 | CSP 在 `app/Response.php::securityHeaders()`。图片域名加「图片域名白名单」；**视频嵌入域名没登记会变成一个安静的白框**（页面无报错，只在控制台留一条违规）。 |
| 页面 403 | `Require local` 生效了，或访问的不是 `localhost`。也可能目录里没有 `index.*`。 |
| 所有接口返回 `服务器内部错误` | `APP_DEBUG` 没开。改成 `true` 就能看到详情；同时去看 `data/logs/error-YYYY-MM.log.php`。 |
| 上传图片后图不显示 | 检查直链模板是不是 `{dkey}` + `{name}`（不是 `{filename}`）。设置页有即时校验。 |
| 本地能跑、线上 500 | 八成是 MySQL 5.6 的限制（本地 MariaDB 更宽松）。看 SPEC §2.2。 |
| 后台 404 | 入口名是随机的，去 `config.php` 找 `ADMIN_ENTRY`。 |

日志统一在 **`data/logs/error-YYYY-MM.log.php`**，排错先看它。

---

## 11. 本地与线上的已知差异（SPEC §11.1）

这两条是**必然存在**的，不是环境装错了：

**① 本地 Apache 有 URL 重写，线上没有。**
本项目一律不使用重写 —— 前台走 Hash 路由，API 走 `api.php?action=xxx`。
所以这个差异不会影响结果。**但本地不要为了图省事额外开重写**，否则测出来的东西线上不成立。

**② 本地没有 `fastcgi_finish_request()`，线上有。**
XAMPP 的 Apache 是 mod_php，这个函数不存在 → 伪 cron 走 **5 秒预算分支**
（线上走 12 秒分支）。这是本地唯一需要"心里有数"的行为差异：
任务分批数量会比线上少，低访问量时任务推进得慢一些，属正常。

> 换句话说：`php -S` 内置服务器和 XAMPP 的 Apache 在这件事上**完全一样**，
> 都没有 `fastcgi_finish_request()`。装 Apache 换来的是图形控制面板和 phpMyAdmin。

---

## 12. 部署前检查（SPEC §11.2）

- [ ] 改动的 PHP 文件都跑过 `php -l`
- [ ] 本地打开改动过的页面，**无报错、无警告**（本地会把警告变成异常，所以这条是有牙齿的）
- [ ] 改过表结构的话，先在本地跑一遍完整建表 SQL
- [ ] `APP_DEBUG` 为 `false`
- [ ] 前端是 `npm run build` 的产物，且 `dist/` 内容已铺到站点根
- [ ] `config.php` 没有被本地配置覆盖
- [ ] **线上删掉 `install.php`**
