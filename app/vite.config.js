import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Builds into the plugin. PHP reads .vite/manifest.json to find the hashed files.
// The copyright notice, added to the top of the app's main file after minifying.
const copyright = {
  name: 'copyright',
  generateBundle(_, bundle) {
    for (const chunk of Object.values(bundle)) {
      if (chunk.type === 'chunk' && chunk.isEntry) chunk.code = `/*! MemBuild - Community · Copyright (C) 2026 Danica Louwe (MemBuild, https://membuild.com) · GPL-2.0-or-later */\n${chunk.code}`
    }
  },
}

/**
 * Light, Dark or Auto (Menu > Display). Every "@media (prefers-color-scheme: dark)" in the
 * app's styles also answers to <html data-theme>: dark is used when the device is dark
 * and the member hasn't picked Light, or whenever they picked Dark.
 */
const themeSwitch = () => {
  const DARK = /^\(\s*prefers-color-scheme:\s*dark\s*\)$/
  const tag = (selector, attr) =>
    selector
      .split(',')
      .map((sel) => {
        const s = sel.trim()
        if (/^(:root|html)\b/.test(s)) return s.replace(/^(:root|html)/, `$1${attr}`)
        return `:root${attr} ${s}`
      })
      .join(', ')
  return {
    postcssPlugin: 'mbcom-theme-switch',
    AtRule: {
      media(at) {
        if (!DARK.test(at.params.trim()) || at.__mbcom) return
        at.__mbcom = true
        const forced = []
        at.each((node) => {
          if (node.type !== 'rule') return
          forced.push(node.clone({ selector: tag(node.selector, '[data-theme="dark"]') }))
          node.selector = tag(node.selector, ':not([data-theme="light"])')
        })
        at.after(forced)
      },
    },
  }
}
themeSwitch.postcss = true

/**
 * Phone sizes (styles.css, "Phone sizes"): every font-size of 15px or more becomes
 * var(--mb-fs-17, 17px), and every 52px button height var(--mb-tap, 52px). Off phones the
 * variables aren't set, so the sizes are exactly as written; on phones the table in
 * styles.css makes text and buttons about 10% smaller. Sizes under 15px stay as they are.
 */
const phoneSizes = () => ({
  postcssPlugin: 'mbcom-phone-sizes',
  Declaration(decl) {
    if (decl.__mbcom) return
    decl.__mbcom = true
    if (decl.prop === 'font-size') {
      const m = decl.value.match(/^(\d+(?:\.5)?)px(\s*!important)?$/)
      if (!m || Number(m[1]) < 15) return
      decl.value = `var(--mb-fs-${m[1].replace('.5', 'h')}, ${m[1]}px)${m[2] || ''}`
    } else if (/^52px(\s*!important)?$/.test(decl.value) && (decl.prop === 'height' || decl.prop === 'min-height' || (decl.prop === 'width' && decl.parent?.some((d) => d.prop === 'height' && /52px/.test(d.value))))) {
      // A 52px button's height, and its width too when it's a round or square one.
      decl.value = decl.value.replace('52px', 'var(--mb-tap, 52px)')
    }
  },
})
phoneSizes.postcss = true

export default defineConfig({
  plugins: [vue(), copyright],
  css: { postcss: { plugins: [themeSwitch(), phoneSizes()] } },
  base: './',
  build: {
    outDir: '../membuild-community/assets/build',
    emptyOutDir: true,
    manifest: true,
    target: 'es2020',
    // Terser, like WordPress's own build tools, so "translators:" comments survive
    // minification and reach translators through `wp i18n make-pot`.
    minify: 'terser',
    terserOptions: { format: { comments: /translators:/i } },
    rollupOptions: { input: 'index.html' },
  },
})
