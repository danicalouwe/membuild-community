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

export default defineConfig({
  plugins: [vue(), copyright],
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
