import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// All /api/* and /sanctum/* requests are proxied to the Laravel server
// server-side (Node → PHP via 127.0.0.1), so the browser never has to
// deal with the IPv4/IPv6 localhost split or cross-origin cookie rules.
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
    },
  },
})
