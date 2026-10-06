<?php
/**
 * Markdown.php —— 极简 Markdown 解析
 *
 * 【用途严格限定】仅服务端使用：
 *   1. toText()  —— 剥离标记生成 body_text，供 LIKE 搜索与摘要
 *   2. toHtml()  —— 供 RSS / JSON Feed 输出（feed 阅读器需要 HTML）
 *
 * 前台正文渲染**不经过本类**：由浏览器端 marked + DOMPurify 完成（SPEC D11）。
 * 这里的所有 HTML 都在转义之后才拼接，不引入 XSS 面（SPEC R13）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class Markdown
{
    /**
     * 剥离所有标记，得到纯文本。用于 article_meta.body_text。
     */
    public static function toText(string $md): string
    {
        $t = $md;

        // 代码块整体丢弃（搜索价值低且噪声大）
        $t = preg_replace('/```.*?```/s', ' ', $t) ?? $t;
        $t = preg_replace('/~~~.*?~~~/s', ' ', $t) ?? $t;

        // 行内代码保留内容
        $t = preg_replace('/`([^`]*)`/', '$1', $t) ?? $t;

        // 图片：保留 alt
        $t = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t;
        // 链接：保留文字
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t;

        // 原始 HTML 标签丢弃
        $t = strip_tags($t);

        // 标题 / 引用 / 列表 / 分隔线 的行首标记
        $t = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $t) ?? $t;
        $t = preg_replace('/^\s{0,3}>\s?/m', '', $t) ?? $t;
        $t = preg_replace('/^\s{0,3}([-*+]|\d+\.)\s+/m', '', $t) ?? $t;
        $t = preg_replace('/^\s{0,3}([-*_]\s*){3,}$/m', '', $t) ?? $t;

        // 强调标记
        $t = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $t) ?? $t;
        $t = preg_replace('/(\*|_)(.*?)\1/s', '$2', $t) ?? $t;
        $t = str_replace('~~', '', $t);

        // 表格分隔行
        $t = preg_replace('/^\s*\|?[\s:|-]+\|?\s*$/m', '', $t) ?? $t;
        $t = str_replace('|', ' ', $t);

        // 折叠空白
        $t = preg_replace('/[ \t]+/', ' ', $t) ?? $t;
        $t = preg_replace('/\n{3,}/', "\n\n", $t) ?? $t;

        return trim($t);
    }

    /**
     * 极简 Markdown → HTML，仅供 feed 输出。
     * 先整体转义，再粘贴受控标签，因此不存在标签注入。
     */
    public static function toHtml(string $md): string
    {
        $esc = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        /*
         * 【切行用显式换行，不能用 \R】
         * PCRE 的 \R 在没有 u 修饰符时把字节 0x85 也当换行，而 UTF-8 汉字
         * （关 / 全 / 八 / 六 / 公 / 共 / 照 / 元…）的中间字节正是 0x85，
         * 于是会从汉字中间劈开，再往断口里插 <li> 之类的标签，字就烂了。
         * 详见 install.php::runSchema() 的注释。
         */
        $lines = preg_split('/\r\n|\n|\r/', $esc) ?: [];
        $out = [];
        $inCode = false;
        $listType = null;   // 'ul' | 'ol' | null

        $closeList = static function () use (&$out, &$listType): void {
            if ($listType !== null) {
                $out[] = "</{$listType}>";
                $listType = null;
            }
        };

        foreach ($lines as $line) {
            // 围栏代码块
            if (preg_match('/^\s*```/', $line)) {
                $closeList();
                $out[] = $inCode ? '</code></pre>' : '<pre><code>';
                $inCode = !$inCode;
                continue;
            }
            if ($inCode) {
                $out[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $closeList();
                continue;
            }

            // 标题
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*)$/', $line, $m)) {
                $closeList();
                $level = strlen($m[1]);
                $out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }

            // 引用
            if (preg_match('/^\s{0,3}&gt;\s?(.*)$/', $line, $m)) {
                $closeList();
                $out[] = '<blockquote>' . self::inline($m[1]) . '</blockquote>';
                continue;
            }

            // 无序列表
            if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $m)) {
                if ($listType !== 'ul') {
                    $closeList();
                    $out[] = '<ul>';
                    $listType = 'ul';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            // 有序列表
            if (preg_match('/^\s{0,3}\d+\.\s+(.*)$/', $line, $m)) {
                if ($listType !== 'ol') {
                    $closeList();
                    $out[] = '<ol>';
                    $listType = 'ol';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            // 分隔线
            if (preg_match('/^\s{0,3}([-*_]\s*){3,}$/', $line)) {
                $closeList();
                $out[] = '<hr>';
                continue;
            }

            $closeList();
            $out[] = '<p>' . self::inline($line) . '</p>';
        }

        $closeList();
        if ($inCode) {
            $out[] = '</code></pre>';
        }

        return implode("\n", $out);
    }

    /** 行内元素：粗体 / 斜体 / 删除线 / 行内代码 / 链接 / 图片 */
    private static function inline(string $s): string
    {
        // 行内代码：内容已在调用前转义，此处只包标签
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $s) ?? $s;

        // 图片：必须排在链接**前面**。否则 `![alt](url)` 会被下面的链接规则先吃掉，
        // 结果渲染成一个孤零零的 `!` 加一条链接（见 DEBUG.md D-13）。
        $s = preg_replace_callback(
            '/!\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)\)/',
            static function (array $m): string {
                $src = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if (!preg_match('~^(https?://|/|\./)~i', $src)) {
                    return $m[1];   // 危险协议 → 退化为 alt 文本
                }
                // $m[1] 是调用前已转义的正文，直接放进 alt 属性是安全的
                return '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                     . '" alt="' . $m[1] . '" loading="lazy">';
            },
            $s
        ) ?? $s;

        // 链接：href 已转义，但需拦住 javascript: 伪协议
        //
        // URL 部分用 `(?:[^()\s]|\([^()\s]*\))+` 而不是 `[^)\s]+`：后者遇到第一个 `)`
        // 就停，含括号的地址（维基百科的 /wiki/Foo_(bar) 最典型）会被拦腰截断、
        // 剩下的 `)` 漏进正文（见 DEBUG.md D-12）。这里允许 URL 内部有一层成对括号。
        $s = preg_replace_callback(
            '/\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)\)/',
            static function (array $m): string {
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // 分隔符必须用 ~ 而不是 #：下面第三个分支要放行纯片段链接（#section），
                // 若用 # 当分隔符，模式里的那个 # 会把模式提前截断，
                // PHP 接着就把剩下的 ) 当修饰符 → «Unknown modifier ')'»，
                // 正文里每有一个链接就抛一次，share.php / feed.php 直接 500（见 DEBUG.md D-11）
                if (!preg_match('~^(https?://|/|\./|#)~i', $href)) {
                    return $m[1];   // 危险协议 → 退化为纯文本
                }
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                     . '" rel="noopener noreferrer">' . $m[1] . '</a>';
            },
            $s
        ) ?? $s;

        return $s;
    }

    /** 摘要：留空时从正文截取前 N 字（SPEC §10.1） */
    public static function excerpt(string $text, int $len = 120): string
    {
        return Str::excerpt($text, $len);
    }
}
