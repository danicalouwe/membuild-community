/**
 * The visual lesson editor writes the same small Markdown the lesson page reads
 * (see markdown.js), so lessons written before keep working and nothing else
 * changes on the server. This turns what's in the editor back into that text.
 */
const clean = (s) => s.replace(/ /g, ' ').replace(/[ \t]+/g, ' ')

function inline(node) {
  let out = ''
  for (const n of node.childNodes) {
    if (n.nodeType === 3) {
      out += clean(n.textContent)
      continue
    }
    if (n.nodeType !== 1) continue
    const tag = n.tagName
    const inner = inline(n)
    if (tag === 'BR') out += '\n'
    else if (tag === 'STRONG' || tag === 'B') out += inner.trim() ? `**${inner.trim()}**` : inner
    else if (tag === 'EM' || tag === 'I') out += inner.trim() ? `*${inner.trim()}*` : inner
    else if (tag === 'CODE') out += `\`${inner}\``
    else if (tag === 'A') {
      const href = n.getAttribute('href') || ''
      out += /^https?:\/\//.test(href) ? (inner.trim() && inner.trim() !== href ? `[${inner.trim()}](${href})` : href) : inner
    } else if (tag === 'IMG') {
      const src = n.getAttribute('src') || ''
      if (/^https?:\/\//.test(src)) out += `![${(n.getAttribute('alt') || '').replace(/[[\]]/g, '')}](${src})`
    } else out += inner
  }
  return out
}

/** Editor HTML → lesson Markdown. */
export function htmlToMarkdown(root) {
  const blocks = []
  const push = (text) => {
    const lines = text.split('\n').map((l) => l.trim())
    if (lines.some(Boolean)) blocks.push(lines.join('\n'))
  }
  let loose = ''
  const flushLoose = () => {
    push(loose)
    loose = ''
  }
  for (const n of root.childNodes) {
    if (n.nodeType === 3) {
      loose += clean(n.textContent)
      continue
    }
    if (n.nodeType !== 1) continue
    const tag = n.tagName
    if (/^H[1-6]$/.test(tag)) {
      flushLoose()
      const text = inline(n).replace(/\n/g, ' ').trim()
      if (text) blocks.push(`## ${text}`)
    } else if (tag === 'UL' || tag === 'OL') {
      flushLoose()
      const items = [...n.children].filter((li) => li.tagName === 'LI').map((li, i) => `${tag === 'OL' ? `${i + 1}.` : '-'} ${inline(li).replace(/\n/g, ' ').trim()}`).filter((l) => !/^(-|\d+\.)\s*$/.test(l))
      if (items.length) blocks.push(items.join('\n'))
    } else if (tag === 'P' || tag === 'DIV' || tag === 'BLOCKQUOTE') {
      flushLoose()
      // Browsers wrap lists and headings in a <div> as you type: look inside instead of flattening.
      if ([...n.children].some((c) => /^(UL|OL|H[1-6]|P|DIV|BLOCKQUOTE)$/.test(c.tagName))) {
        const inner = htmlToMarkdown(n)
        if (inner) blocks.push(inner)
      } else push(inline(n))
    } else if (tag === 'BR') {
      loose += '\n'
    } else {
      loose += inline({ childNodes: [n] })
    }
  }
  flushLoose()
  return blocks.join('\n\n')
}
