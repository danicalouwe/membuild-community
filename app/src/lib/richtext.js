/**
 * The visual lesson editor writes the same small Markdown the lesson page reads
 * (see markdown.js), so lessons written before keep working and nothing else
 * changes on the server. This turns what's in the editor back into that text.
 */
const clean = (s) => s.replace(/ /g, ' ').replace(/[ \t]+/g, ' ')

// Spaces typed at the edge of bold, italic or a link stay outside the marks, so
// "<b>Bold </b>words" keeps its space: "**Bold** words", not "**Bold**words".
// Across a line break (Shift+Enter) each line gets its own marks, as the page reads line by line.
function wrap(inner, open, close) {
  const [, before, core, after] = inner.match(/^(\s*)([\s\S]*?)(\s*)$/)
  if (!core) return inner
  if (core.includes('\n')) return before + core.split('\n').map((line) => wrap(line, open, close)).join('\n') + after
  return `${before}${open}${core}${close}${after}`
}

// Browsers sometimes put one block inside another as you type: a list inside a
// heading (List pressed on a heading line, or Heading on a list line) or a
// paragraph inside bold text. Those are read as their own lines, never flattened.
const BLOCKS = 'p,div,blockquote,ul,ol,li,h1,h2,h3,h4,h5,h6'
const hasBlock = (n) => !!n.querySelector?.(BLOCKS)

// Bold or italic around whole lines: each line keeps the mark (after its "## " or "- ").
// Bold inside bold becomes one bold line. A line with a * of its own (italic, or "5 * 3")
// stays as it is under italic, as the page can't tell those stars apart.
const MARK = { B: '**', STRONG: '**', I: '*', EM: '*' }
function markLines(md, mark) {
  return md
    .split('\n')
    .map((line) => {
      const [, start, rest] = line.match(/^((?:## |- |\d+\. )?)([\s\S]*)$/)
      if (!rest.trim()) return line
      if (mark === '**') return start + wrap(rest.replace(/\*\*/g, ''), mark, mark)
      return rest.replace(/\*\*/g, '').includes('*') ? line : start + wrap(rest, mark, mark)
    })
    .join('\n')
}

// A list item's lines: its own words, then any lines or lists inside it, one item each.
function listItems(li, add) {
  let run = ''
  const flush = () => {
    add(run.replace(/\n/g, ' ').trim())
    run = ''
  }
  for (const c of li.childNodes) {
    if (c.nodeType === 3) run += clean(c.textContent)
    else if (c.nodeType !== 1) continue
    else if (c.tagName === 'UL' || c.tagName === 'OL') {
      flush()
      for (const sub of c.children) if (sub.tagName === 'LI') listItems(sub, add)
    } else if (/^(P|DIV|BLOCKQUOTE|LI|H[1-6])$/.test(c.tagName) || hasBlock(c)) {
      flush()
      const mark = MARK[c.tagName] // bold or italic around the lines keeps its mark on each
      listItems(c, mark ? (text) => add(text && markLines(text, mark)) : add)
    } else run += inline({ childNodes: [c] })
  }
  flush()
}

// A web address, with http or https in any capitals (HTTPS://… from the link box) written small;
// anything else is not a link.
const web = (url) => (/^https?:\/\//i.test(url) ? url.replace(/^https?/i, (m) => m.toLowerCase()) : '')

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
    else if (tag === 'STRONG' || tag === 'B') out += wrap(inner.replace(/\*\*/g, ''), '**', '**') // bold inside bold: one bold
    else if (tag === 'EM' || tag === 'I') out += wrap(inner, '*', '*')
    else if (tag === 'CODE') out += `\`${inner}\``
    else if (tag === 'A') {
      const typed = n.getAttribute('href') || ''
      const href = web(typed)
      const label = inner.trim()
      out += !href ? inner : !label ? href : label !== typed ? wrap(inner, '[', `](${href})`) : inner
    } else if (tag === 'IMG') {
      const src = web(n.getAttribute('src') || '')
      if (src) out += `![${(n.getAttribute('alt') || '').replace(/[[\]]/g, '')}](${src})`
    } else out += inner
  }
  return out
}

/** Editor HTML → lesson Markdown. (`heading`: lines here are inside a heading.) */
export function htmlToMarkdown(root, heading = false) {
  const blocks = []
  const push = (text) => {
    if (heading) {
      const line = text.replace(/\n/g, ' ').trim()
      if (line) blocks.push(`## ${line}`)
      return
    }
    const lines = text.split('\n').map((l) => l.trim())
    if (lines.some(Boolean)) blocks.push(lines.join('\n'))
  }
  const nested = (n, inHeading) => {
    const inner = htmlToMarkdown(n, inHeading)
    if (inner) blocks.push(inner)
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
      if (hasBlock(n)) nested(n, true)
      else {
        const text = inline(n).replace(/\n/g, ' ').trim()
        if (text) blocks.push(`## ${text}`)
      }
    } else if (tag === 'UL' || tag === 'OL') {
      flushLoose()
      const items = []
      const add = (text) => text && items.push(`${tag === 'OL' ? `${items.length + 1}.` : '-'} ${text}`)
      for (const li of n.children) if (li.tagName === 'LI') listItems(li, add)
      if (items.length) blocks.push(items.join('\n'))
    } else if (tag === 'P' || tag === 'DIV' || tag === 'BLOCKQUOTE' || tag === 'LI') {
      flushLoose()
      // Browsers wrap lists and headings in a <div> as you type: look inside instead of flattening.
      if (hasBlock(n)) nested(n, heading)
      else push(inline(n))
    } else if (tag === 'BR') {
      loose += '\n'
    } else if (hasBlock(n)) {
      flushLoose()
      const inner = htmlToMarkdown(n, heading)
      const mark = MARK[tag]
      if (inner) blocks.push(mark ? markLines(inner, mark) : inner)
    } else {
      loose += inline({ childNodes: [n] })
    }
  }
  flushLoose()
  return blocks.join('\n\n')
}
