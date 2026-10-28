import { t } from './i18n.js'

/** "2.1 MB", "340 KB": file sizes as people read them. */
export function fileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  const mb = bytes / (1024 * 1024)
  return `${mb < 10 ? mb.toFixed(1) : Math.round(mb)} MB`
}

/** What a file is, in words, from its short type: "PDF document", "Excel spreadsheet". */
const KINDS = { PDF: 'PDF document', DOC: 'Word document', DOCX: 'Word document', TXT: 'Text file', XLS: 'Excel spreadsheet', XLSX: 'Excel spreadsheet', CSV: 'Spreadsheet', PPT: 'PowerPoint slides', PPTX: 'PowerPoint slides', ZIP: 'Zip file', MP3: 'Audio', PNG: 'Picture', JPG: 'Picture', JPEG: 'Picture' }
export function kindName(ext) {
  const k = KINDS[(ext || '').toUpperCase()]
  return k ? t.__(k, 'membuild-community') : t.__('File', 'membuild-community')
}

/** A picked file's name, readable: "platform_cost-worksheet.pdf" becomes "platform cost worksheet". */
export function niceName(fileName) {
  return String(fileName || '').replace(/\.[a-z0-9]{1,5}$/i, '').replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim()
}

/** The short type ("PDF") of a file name. */
export function extOfName(fileName) {
  return (String(fileName || '').match(/\.([a-z0-9]{1,5})$/i)?.[1] || '').toUpperCase()
}

/** A lesson file's Preview address: …/file/12/Its name.pdf?view=1 (the name is only for show). */
export function previewUrl(file) {
  const [base, query] = String(file.url || '').split('?')
  const ext = (file.ext || '').toLowerCase()
  const name = encodeURIComponent(`${String(file.name || 'file').replace(/[\\/]+/g, ' ')}${ext ? `.${ext}` : ''}`)
  return `${base}/${name}?${query ? `${query}&` : ''}view=1`
}
