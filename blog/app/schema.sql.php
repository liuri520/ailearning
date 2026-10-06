<?php
/**
 * schema.sql.php —— 建表脚本（SPEC §5）
 *
 * 【扩展名为什么是 .php 而不是 .sql，别改回去】
 * 线上没有重写能力（SPEC §2.1），.htaccess 那条路不通；而 *.sql / *.md / *.json
 * 这类静态文件不受任何守卫保护 —— 指名道姓就能下走。任何人访问
 * /app/schema.sql 都能拿到完整 DDL（见 DEBUG.md D-08）。
 * 改成 .php 后，首行这段 APP_BOOT 守卫会让直接访问变成 404。
 *
 * 【内容为什么用 nowdoc <<<'SQL' 而不是 heredoc】
 * nowdoc 不解析 $ 也不认转义。将来 SQL 里出现 $ 或被注释掉的变量，
 * 不会被 PHP 悄悄吃掉。
 */

if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

return <<<'SQL'
-- ============================================================================
-- schema.sql —— 建表脚本（SPEC §5）
--
-- 目标环境：MySQL 5.6.51（线上）与 MySQL 8.0（本地可能）**双兼容**
-- 字符集：utf8mb4 / utf8mb4_unicode_ci（支持 emoji）
-- 引擎：InnoDB
--
-- 【MySQL 5.6 硬性限制，改动本文件时逐条对照 SPEC §2.2】
--   · 索引前缀上限 767 字节 → utf8mb4 下可索引文本列 ≤ VARCHAR(191)
--   · 无 JSON 列类型        → 用 TEXT 存 JSON 字符串
--   · 无真正的降序索引      → 索引定义中不写 DESC（写了被静默忽略，反而误导）
--   · 无 CTE / 窗口函数     → 相关推荐等用普通 JOIN + GROUP BY
--   · DATETIME 默认值受限   → created_at / updated_at 一律由 PHP 显式写入
--   · 不加外键约束          → 删除逻辑由应用层负责（与软删除语义一致）
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- 1. contents —— 统一主表（SPEC §5.1）
-- ============================================================================
CREATE TABLE IF NOT EXISTS `contents` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`         ENUM('article','video','image') NOT NULL,
  `slug`         VARCHAR(191) NOT NULL COMMENT 'URL 标识，英文，唯一',
  `title`        VARCHAR(255) NOT NULL,
  `summary`      VARCHAR(500) NULL COMMENT '列表页摘要，留空则从正文截取',
  `cover_path`   VARCHAR(500) NULL COMMENT '封面，相对路径或外链 URL',
  `status`       ENUM('draft','published','trashed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME NULL COMMENT '未来时间即定时发布，查询条件自动生效',
  `is_featured`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首页精选',
  `sort_weight`  INT NOT NULL DEFAULT 0 COMMENT '拖拽排序权重，越大越靠前',
  `deleted_at`   DATETIME NULL COMMENT '进入回收站的时间',
  `created_at`   DATETIME NOT NULL,
  `updated_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_list_article` (`type`, `status`, `published_at`),
  KEY `idx_list_general` (`status`, `published_at`),
  KEY `idx_trash` (`status`, `deleted_at`),
  KEY `idx_featured` (`is_featured`, `status`, `published_at`),
  KEY `idx_title` (`title`(64)) COMMENT '供 LIKE 前缀匹配，全文 LIKE 仍走扫描'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. 扩展表（SPEC §5.2）
-- ============================================================================

-- 文章（1:1）
CREATE TABLE IF NOT EXISTS `article_meta` (
  `content_id` BIGINT UNSIGNED NOT NULL,
  `body_md`    MEDIUMTEXT NOT NULL COMMENT 'Markdown 原文，前端渲染',
  `body_text`  MEDIUMTEXT NOT NULL COMMENT '剥离标记的纯文本，供 LIKE 搜索与摘要生成',
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`content_id`),
  KEY `idx_bodytext` (`body_text`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 视频（1:1）
CREATE TABLE IF NOT EXISTS `video_meta` (
  `content_id`       BIGINT UNSIGNED NOT NULL,
  `source_type`      ENUM('embed','mp4') NOT NULL COMMENT 'embed=iframe 嵌入, mp4=直链',
  `provider`         VARCHAR(32) NULL COMMENT 'bilibili / youtube / custom',
  `source_key`       VARCHAR(255) NOT NULL COMMENT 'BV 号 / YouTube videoId / mp4 完整 URL',
  `poster_path`      VARCHAR(500) NULL COMMENT '封面图，必填（无 FFmpeg 无法自动抽帧）',
  `duration_seconds` INT UNSIGNED NULL COMMENT '手填时长，可为空',
  `aspect_ratio`     VARCHAR(10) NOT NULL DEFAULT '16:9' COMMENT '预留占位，防布局跳动',
  PRIMARY KEY (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 图片（1:1）
CREATE TABLE IF NOT EXISTS `image_meta` (
  `content_id`  BIGINT UNSIGNED NOT NULL,
  `asset_id`    BIGINT UNSIGNED NULL COMMENT '关联媒体库',
  `asset_path`  VARCHAR(500) NOT NULL COMMENT '冗余自 assets.rel_path：钛盘为 UKEY，外链为完整 URL',
  `width`       INT UNSIGNED NULL COMMENT '必填，防布局跳动',
  `height`      INT UNSIGNED NULL,
  `alt_text`    VARCHAR(255) NULL COMMENT '无障碍与图片失效时的替代文本',
  `camera_note` VARCHAR(255) NULL COMMENT '拍摄信息，可选',
  PRIMARY KEY (`content_id`),
  KEY `idx_asset` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. 标签（SPEC §5.3）
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tags` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64) NOT NULL COMMENT '显示名，可中文',
  `slug`       VARCHAR(64) NOT NULL COMMENT 'URL 用，英文或拼音',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_tags` (
  `content_id` BIGINT UNSIGNED NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`content_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`, `content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. 媒体库与资产（SPEC §5.4）
-- ============================================================================
CREATE TABLE IF NOT EXISTS `assets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rel_path`         VARCHAR(500) NOT NULL
                     COMMENT '主标识。provider=ttttt 时存 UKEY（不变的身份）；外链/manual 时存完整 URL',
  `thumb_rel_path`   VARCHAR(500) NULL
                     COMMENT '缩略版本的 UKEY / URL。钛盘无缩略参数，必须独立上传',
  `remote_dkey`      VARCHAR(255) NULL COMMENT 'link_add 返回的 dkey。link_del 时必需',
  `remote_name`      VARCHAR(255) NULL COMMENT 'link_add 返回的 name（注意不是 filename）',
  `direct_url`       VARCHAR(1000) NULL
                     COMMENT '由 dkey+name 按 ttttt_direct_template 拼出的直链（物化存储）',
  `thumb_dkey`       VARCHAR(255) NULL COMMENT '缩略版本的 dkey',
  `thumb_name`       VARCHAR(255) NULL COMMENT '缩略版本的 name',
  `thumb_direct_url` VARCHAR(1000) NULL COMMENT '缩略版本的直链',
  `origin_url`       VARCHAR(1000) NULL COMMENT '完整原始 URL，审计与迁移用',
  `provider`         VARCHAR(32) NOT NULL DEFAULT 'manual' COMMENT 'ttttt/manual',
  `is_external`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=完全外部，换图床时不动它',
  `storage_model`    TINYINT UNSIGNED NOT NULL DEFAULT 99
                     COMMENT '钛盘存储模式：99=永久, 0=24h, 1=3天, 2=7天。默认必须为 99',
  `expires_at`       DATETIME NULL COMMENT '非永久模式的过期时间；99=永久 时为 NULL',
  `mime`             VARCHAR(64) NULL,
  `width`            INT UNSIGNED NULL COMMENT '原图宽（必填，防布局跳动）',
  `height`           INT UNSIGNED NULL COMMENT '原图高（必填）',
  `thumb_width`      INT UNSIGNED NULL,
  `thumb_height`     INT UNSIGNED NULL,
  `file_size`        INT UNSIGNED NULL COMMENT '原图字节数',
  `sha256`           CHAR(64) NULL COMMENT '去重：相同图片不重复上传',
  `ref_count`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '被引用次数，0 才允许删除记录',
  `check_status`     ENUM('unknown','ok','missing','expired') NOT NULL DEFAULT 'unknown',
  `last_check_at`    DATETIME NULL,
  `last_http_code`   SMALLINT NULL,
  `last_error`       VARCHAR(255) NULL COMMENT '巡检失败原因；钛盘返回的 status 码也记这里',
  `created_at`       DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rel_path` (`rel_path`(191)),
  KEY `idx_check` (`check_status`, `last_check_at`),
  KEY `idx_expire` (`storage_model`, `expires_at`),
  KEY `idx_sha` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 注意：rel_path 索引前缀取 191 字符 —— MySQL 5.6 + utf8mb4 的上限是 767 字节，
--       191×4 = 764 字节正好卡在内。不要改成 255。

-- ============================================================================
-- 5. 系统表（SPEC §5.5）
-- ============================================================================

-- 键值配置
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(64) NOT NULL,
  `value`      TEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 伪 cron 任务状态
CREATE TABLE IF NOT EXISTS `tasks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key`    VARCHAR(64) NOT NULL,
  `last_run_at` DATETIME NULL,
  `duration_ms` INT UNSIGNED NULL,
  `cursor`      VARCHAR(255) NULL COMMENT '分批处理进度',
  `locked_at`   DATETIME NULL COMMENT '超过 60 秒视为死锁，可被抢占',
  `lock_owner`  VARCHAR(64) NULL,
  `last_result` VARCHAR(255) NULL COMMENT '用于后台展示，如「巡检 20 条，2 条失效」',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task` (`task_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 登录限流
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`      CHAR(64) NOT NULL COMMENT 'sha256(ip + 盐)，不存明文 IP',
  `attempted_at` DATETIME NOT NULL,
  `success`      TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 浏览去重明细（会被聚合任务清理）
CREATE TABLE IF NOT EXISTS `view_seen` (
  `content_id`   BIGINT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL COMMENT 'sha256(ip + UA + 日盐)',
  `stat_date`    DATE NOT NULL,
  PRIMARY KEY (`content_id`, `visitor_hash`, `stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 浏览日聚合
CREATE TABLE IF NOT EXISTS `view_daily` (
  `content_id` BIGINT UNSIGNED NOT NULL,
  `stat_date`  DATE NOT NULL,
  `pv`         INT UNSIGNED NOT NULL DEFAULT 0,
  `uv`         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`content_id`, `stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- slug 变更历史（旧链接重定向）
CREATE TABLE IF NOT EXISTS `slug_redirects` (
  `old_slug`   VARCHAR(191) NOT NULL,
  `content_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`old_slug`),
  KEY `idx_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 首页布局区块（拖拽排序）
CREATE TABLE IF NOT EXISTS `home_blocks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `block_type`  VARCHAR(32) NOT NULL COMMENT 'banner/featured/recent_article/recent_video/gallery/tag_cloud/about',
  `title`       VARCHAR(128) NULL COMMENT '区块自定义标题',
  `config`      TEXT NULL COMMENT 'JSON 字符串：条数、筛选条件等。MySQL 5.6 无 JSON 列，用 TEXT 存',
  `sort_weight` INT NOT NULL DEFAULT 0,
  `is_enabled`  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`is_enabled`, `sort_weight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. 默认配置（SPEC 附录 B）
--    说明：cache_ttl / cdn_whitelist / frame_whitelist 为本文档补充项（§9.8
--    要求设置页提供「图床域名白名单」，frame_whitelist 是同一件事的另一半：
--    自定义视频嵌入域名不入 frame-src 就会被浏览器拦成白框）；
--    backup_remind_days / trash_pending_count 为 Scheduler 写入的内部状态，
--    不是用户配置。
-- ============================================================================
INSERT IGNORE INTO `settings` (`key`, `value`, `updated_at`) VALUES
  ('site_name',             '',                                              NOW()),
  ('site_desc',             '',                                              NOW()),
  ('site_icp',              '',                                              NOW()),
  ('cdn_base',              '',                                              NOW()),
  ('cdn_whitelist',         '',                                              NOW()),
  ('frame_whitelist',       '',                                              NOW()),
  ('ttttt_direct_template', 'https://download.wuyuge.cn/files/{dkey}/{name}', NOW()),
  ('admin_user',            'admin',                                         NOW()),
  ('admin_pass_hash',       '',                                              NOW()),
  ('cache_enabled',         '0',                                             NOW()),
  ('cache_ttl',             '300',                                           NOW()),
  ('cache_version',         '1',                                             NOW()),
  ('cron_probability',      '2',                                             NOW()),
  ('comments_enabled',      '0',                                             NOW()),
  ('view_dedup_salt',       '',                                              NOW()),
  ('last_export_at',        '',                                              NOW()),
  ('backup_remind_days',    '-1',                                            NOW()),
  ('trash_pending_count',   '0',                                             NOW()),
  ('storage_driver',        'ttttt',                                         NOW()),
  ('ttttt_model',           '99',                                            NOW()),
  ('ttttt_key_param',       'key',                                           NOW()),
  ('ttttt_mrid',            '',                                              NOW()),
  ('ttttt_max_bytes',       '104857600',                                     NOW()),
  ('image_thumb_edge',      '800',                                           NOW()),
  ('image_full_edge',       '2560',                                          NOW()),
  ('page_size',             '10',                                            NOW());

-- ============================================================================
-- 7. 伪 cron 任务登记（SPEC §7.1 任务清单）
-- ============================================================================
INSERT IGNORE INTO `tasks` (`task_key`, `last_run_at`, `last_result`) VALUES
  ('asset_check',    NULL, NULL),
  ('expires_watch',  NULL, NULL),
  ('view_aggregate', NULL, NULL),
  ('view_gc',        NULL, NULL),
  ('cache_gc',       NULL, NULL),
  ('backup_remind',  NULL, NULL),
  ('login_gc',       NULL, NULL),
  ('trash_gc',       NULL, NULL);

-- ============================================================================
-- 8. 默认首页区块（SPEC §5.5 home_blocks）
--    仅作首次安装的初始布局，后台可自由增删排序。
-- ============================================================================
INSERT IGNORE INTO `home_blocks` (`id`, `block_type`, `title`, `config`, `sort_weight`, `is_enabled`) VALUES
  (1, 'banner',         NULL, '{"subtitle":""}',       100, 1),
  (2, 'featured',       NULL, '{"limit":6}',            90, 1),
  (3, 'recent_article', NULL, '{"limit":6}',            80, 1),
  (4, 'recent_video',   NULL, '{"limit":4}',            70, 1),
  (5, 'gallery',        NULL, '{"limit":8}',            60, 1),
  (6, 'tag_cloud',      NULL, '{"limit":20}',           50, 1);

-- ============================================================================
-- 附录 A：comments 表设计（冻结，第一版不建表，SPEC §5.6 / 附录 A）
-- 启用时执行建表 SQL + 一个 action 处理器 + 打开 settings.comments_enabled
-- ============================================================================
-- CREATE TABLE `comments` (
--   `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--   `content_id`        BIGINT UNSIGNED NOT NULL,
--   `parent_id`         BIGINT UNSIGNED NULL COMMENT '支持一级嵌套回复',
--   `author_name`       VARCHAR(64) NOT NULL,
--   `author_email_hash` CHAR(64) NULL COMMENT '仅存哈希，用于头像，不存明文邮箱',
--   `author_site`       VARCHAR(255) NULL,
--   `body_text`         TEXT NOT NULL COMMENT '纯文本，禁止 HTML',
--   `status`            ENUM('pending','approved','spam') NOT NULL DEFAULT 'pending',
--   `ip_hash`           CHAR(64) NOT NULL,
--   `created_at`        DATETIME NOT NULL,
--   PRIMARY KEY (`id`),
--   KEY `idx_content_status` (`content_id`, `status`, `created_at`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 启用时需同步实现：蜜罐字段、频率限制（同 IP 每分钟 1 条）、关键词黑名单、
-- settings.comments_enabled 开关、后台审核页。

SQL;
