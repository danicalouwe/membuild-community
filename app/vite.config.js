import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Builds into the plugin. PHP reads .vite/manifest.json to find the hashed files.
export default defineConfig({
  plugins: [vue()],
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
