/**
 * A tiny, safe Markdown subset for lesson text: ## headings, paragraphs,
 * - and 1. lists, **bold**, *italic*, `code`, links and ![pictures](https://…). Everything is
 * HTML-escaped first, and links only allow http(s) (any capitals), so stored text can never
 * inject markup or scripts.
 */
const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

// Bold and italic, also one inside the other: ***both***, **bold *italic* bold**, *italic **bold***.
// It runs on text that is already escaped, so it only adds <strong> and <em>.
const italic = (s) => s.replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
const emphasis = (s) =>
  italic(s.replace(/\*\*\*([^*\s][^*]*?)\*\*\*/g, '<strong><em>$1</em></strong>').replace(/\*\*((?:[^*]|\*[^*\s][^*]*\*)+?)\*\*/g, (_, t) => `<strong>${italic(t)}</strong>`))

// Links are built first and set aside as placeholders, so no later step can
// touch the inside of an <a> tag (a link written inside another link's href
// could otherwise break out of the attribute).
function inline(text) {
  const links = []
  const keep = (html) => `\u0000${links.push(html) - 1}\u0000`
  // http or https in any capitals, written small in the address.
  const small = (url) => url.replace(/^https?/i, (m) => m.toLowerCase())
  const anchor = (href, label) => keep(`<a href="${small(href)}" target="_blank" rel="noopener nofollow">${label}</a>`)
  return escape(text.replace(/\u0000/g, ''))
    .replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/gi, (_, alt, src) => keep(`<img src="${small(src)}" alt="${alt}" loading="lazy">`))
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/gi, (_, label, href) => anchor(href, emphasis(label)))
    // A bare address, also right after bold or italic marks. It stops at a *, the mark that closes them
    // (addresses with a * in them are rare; bold right after an address is not).
    // The text is escaped already, so a typed " is &quot; and ends the address too.
    .replace(/(^|[\s(*])(https?:\/\/(?:(?!&quot;)[^\s<*\u0000])*(?!&quot;)[^\s<.,:;"')\]!?*\u0000])/gi, (_, pre, href) => pre + anchor(href, href))
    // Code is set aside too, so a * inside it is never read as bold or italic.
    .replace(/`([^`]+)`/g, (_, code) => keep(`<code>${code}</code>`))
    .replace(/[\s\S]+/, emphasis)
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
