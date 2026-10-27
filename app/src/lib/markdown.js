/**
 * A tiny, safe Markdown subset for lesson text: ## headings, paragraphs,
 * - and 1. lists, **bold**, *italic*, `code`, links and ![pictures](https://…). Everything is
 * HTML-escaped first, and links only allow http(s), so stored text can never
 * inject markup or scripts.
 */
const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

// Links are built first and set aside as placeholders, so no later step can
// touch the inside of an <a> tag (a link written inside another link's href
// could otherwise break out of the attribute).
function inline(text) {
  const links = []
  const keep = (html) => `\u0000${links.push(html) - 1}\u0000`
  const anchor = (href, label) => keep(`<a href="${href}" target="_blank" rel="noopener nofollow">${label}</a>`)
  return escape(text.replace(/\u0000/g, ''))
    .replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, (_, alt, src) => keep(`<img src="${src}" alt="${alt}" loading="lazy">`))
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, label, href) => anchor(href, label))
    .replace(/(^|[\s(])(https?:\/\/[^\s<\u0000]+[^\s<.,:;"')\]!?\u0000])/g, (_, pre, href) => pre + anchor(href, href))
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
    .replace(/\u0000(\d+)\u0000/g, (_, i) => links[Number(i)])
}

export function renderMarkdown(source = '') {
  const lines = source.replace(/\r\n?/g, '\n').split('\n')
  const out = []
  let list = null // { tag, items }
  let para = []

  const flushPara = () => {
    if (para.length) out.push(`<p>${para.map(inline).join('<br>')}</p>`)
    para = []
  }
  const flushList = () => {
    if (list) out.push(`<${list.tag}>${list.items.map((i) => `<li>${inline(i)}</li>`).join('')}</${list.tag}>`)
    list = null
  }

  for (const raw of lines) {
    const line = raw.trimEnd()
    const heading = line.match(/^(#{1,3})\s+(.*)$/)
    const bullet = line.match(/^\s*[-*]\s+(.*)$/)
    const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/)

    if (!line.trim()) {
      flushPara()
      flushList()
    } else if (heading) {
      flushPara()
      flushList()
      const level = Math.min(4, heading[1].length + 1) // # → h2, ## → h3
      out.push(`<h${level}>${inline(heading[2])}</h${level}>`)
    } else if (bullet || numbered) {
      flushPara()
      const tag = bullet ? 'ul' : 'ol'
      if (list && list.tag !== tag) flushList()
      list ??= { tag, items: [] }
      list.items.push((bullet || numbered)[1])
    } else {
      flushList()
      para.push(line)
    }
  }
  flushPara()
  flushList()
  return out.join('')
}
