# 个人博客

PHP 8.1 零依赖后端 + Vue 3 SPA 前端的个人博客系统。

为**共享主机子目录部署**而设计：没有 Composer、没有 cron、没有 URL 重写权限、
数据库是 MySQL 5.6。这些约束不是偷懒，是部署环境决定的 —— 它们决定了这个项目
长什么样（比如为什么用 Hash 路由、为什么有个「伪 cron」）。

## 特性

- **三类内容**：文章（Markdown）、视频（嵌入或 MP4）、图片，共用主表 + 扩展表
- **自带图床对接**：钛盘四接口（上传 / 建直链 / 撤销 / 列表），带引用计数与巡检
- **双尺寸图片**：浏览器端 Canvas 重绘，同时剥离 EXIF（图床不剥，这步是隐私必需项）
- **伪 cron**：概率触发 + 时间预算分批 + 关服兜底，不需要真实 crontab
- **回收站**：软删除 + 30 天保留
- **安全**：会话 + CSRF、登录限流、后台入口随机化、CSP 域名白名单、全量预处理语句

## 文档

| 文档 | 什么时候看 |
|---|---|
| [`docs/SPEC.md`](docs/SPEC.md) | **唯一事实来源**。需求、数据库结构、API 契约、验收标准。与任何实现的冲突以它为准 |
| [`docs/TECHNICAL_PLAN.md`](docs/TECHNICAL_PLAN.md) | 实现蓝图。类职责与方法签名、M0–M12 路线图、开放决策项的默认取值 |
| [`docs/DEBUG.md`](docs/DEBUG.md) | 缺陷登记簿。每条含现象 / 根因 / 修复 / 验证，**只增不改**，作为回归依据 |
| [`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md) | 从零搭建本地环境（XAMPP、php.ini、Apache Alias、建库） |
| [`blog/README.md`](blog/README.md) | 部署操作手册：上传清单、首次安装、上线前检查、常见故障 |
| [`CHANGELOG.md`](CHANGELOG.md) | 版本迭代记录与版本号规则 |

**改代码之前先看 SPEC，改完代码记得去 DEBUG 登记。**

## 目录结构

```
.
├── CHANGELOG.md
├── docs/                     设计文档（不随站点上传）
└── blog/                     站点代码
    ├── app/                  PHP 后端
    │   ├── actions/          接口层：public.php / admin.php
    │   ├── Storage/          图床适配器（接口 + 钛盘实现 + 外链实现）
    │   └── schema.sql.php    建表脚本（.php 后缀是为了过守卫，见 DEBUG D-08）
    ├── src/                  Vue 3 前端源码
    ├── data/                 运行数据：缓存 / 日志 / 导出（内容不入库，目录结构入库）
    ├── uploads/              临时上传目录
    ├── config.sample.php     配置模板（真实的 config.php 不入库）
    └── install.php           一次性安装向导（**装完必须从线上删除**）
```

## 快速开始

```bash
git clone https://github.com/liuri520/ailearning.git
cd ailearning/blog

# 前端依赖（后端零依赖，不需要任何包管理器）
npm install

# 本地环境搭建（XAMPP / php.ini / Apache Alias / 建库）
# 见 docs/LOCAL_SETUP.md，那里是完整步骤
```

日常开发：

```bash
# 后端：XAMPP 启动 Apache + MySQL，站点 http://localhost/blog/
npm run dev      # 前端 Vite dev server：http://localhost:5173
```

部署：

```bash
npm run build    # 产物在 dist/，把 dist/* 铺到线上站点根
```

完整的部署流程、上传清单与上线前检查表见 [`blog/README.md`](blog/README.md)。

## 版本

当前 **v1.0.0**（2026-10-06）。版本号规则与历次变更见 [`CHANGELOG.md`](CHANGELOG.md)。

## 注意

`config.php` 含数据库密码与钛盘 API Key，**不在版本控制中**，需要各环境自行生成
（首次安装时由 `install.php` 写出）。切勿提交、部署时切勿用本地配置覆盖线上配置。
