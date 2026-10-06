/**
 * markdown.js —— Markdown 渲染（SPEC D14）
 *
 * 正文以 Markdown 原文存库，前端渲染。这样做的好处是数据与呈现解耦 ——
 * 换渲染器、换样式，都不用动数据库。
 *
 * 【安全】marked 输出的 HTML 一律过 DOMPurify。
 * 「内容都是我自己写的」不能作为跳过净化的理由：Markdown 里可以内嵌
 * 原始 HTML，而一篇文章被复制粘贴进来的片段里有什么，写的时候是看不出来的。
 * 净化是最后一道闸门，成本几乎为零（SPEC §9.5）。
 */
import { marked } from 'marked'
import DOMPurify from 'dompurify'

// 代码块语言标记由自己控制，不用 highlight.js（T5 默认关闭，SPEC §14.3）
marked.setOptions({
  gfm: true,
  breaks: true,      // 中文写作习惯：单换行即换行
  headerIds: false,  // 不生成 id，避免与页面锚点冲突
  mangle: false,
})

const PURIFY_CONFIG = {
  ALLOWED_TAGS: [
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'p', 'br', 'hr', 'blockquote', 'pre', 'code',
    'ul', 'ol', 'li', 'dl', 'dt', 'dd',
    'strong', 'em', 'del', 's', 'u', 'mark', 'sub', 'sup', 'small',
    'a', 'img', 'figure', 'figcaption',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'div', 'span', 'input',   // input 仅用于任务列表的 checkbox
  ],
  ALLOWED_ATTR: [
    'href', 'title', 'alt', 'src', 'srcset', 'width', 'height',
    'class', 'id', 'colspan', 'rowspan', 'align',
    'type', 'checked', 'disabled', 'loading',
  ],
  // 【必须显式禁止】否则 javascript: 伪协议可以直接执行脚本
  ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
  ADD_ATTR: ['target', 'rel'],
}

// 外链统一加 rel="noopener"，防止 target=_blank 页面对 window.opener 为所欲为
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A' && node.getAttribute('href')) {
    const href = node.getAttribute('href')
    if (/^https?:\/\//i.test(href)) {
      node.setAttribute('target', '_blank')
      node.setAttribute('rel', 'noopener noreferrer')
    }
  }
})

/** Markdown → 安全 HTML */
export function renderMarkdown(md) {
  if (!md) return ''
  const raw = marked.parse(String(md))
  return DOMPurify.sanitize(raw, PURIFY_CONFIG)
}

/** 去掉标记取纯文本，用于摘要 */
export function markdownToText(md) {
  if (!md) return ''
  return String(md)
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/`[^`]*`/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/[*_~>]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

/** 从正文提取目录（h2 / h3） */
export function extractOutline(md) {
  if (!md) return []
  const outline = []
  const regex = /^(#{2,3})\s+(.+)$/gm
  let match
  let index = 0
  while ((match = regex.exec(String(md))) !== null) {
    outline.push({
      level: match[1].length,
      text: match[2].trim(),
      index: index++,
    })
  }
  return outline
}

/**
 * 给渲染出的 HTML 里的标题补 id，供目录锚点跳转。
 * 与 marked 分开做，是因为净化后 DOM 已定型，改起来更可控。
 */
export function withHeadingIds(html) {
  if (!html) return ''
  let counter = 0
  return html.replace(/<(h[23])>/g, (_m, tag) => {
    const id = `heading-${counter++}`
    return `<${tag} id="${id}">`
  })
}
