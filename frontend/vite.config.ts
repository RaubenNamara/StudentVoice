import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// In development the API runs in XAMPP at http://localhost/studentvoice/api; Vite forwards /api
// there so the app and the API share one address (and one session cookie).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5175,
    proxy: {
      '/api': {
        target: process.env.STUDENTVOICE_API ?? 'http://localhost',
        changeOrigin: true,
        rewrite: (path) => '/studentvoice' + path,
      },
    },
  },
})
