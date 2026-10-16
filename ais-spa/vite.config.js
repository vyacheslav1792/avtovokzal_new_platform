import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// SPA отчётов АИС новой платформы. Живёт в корне своего хоста (base '/').
// В dev '/api' проксируется на ais-api: в docker — http://ais_web,
// на хосте — http://localhost:8086 (переопределяется env AIS_API_PROXY).
export default defineConfig({
  base: '/',
  plugins: [vue()],
  server: {
    // За обратным прокси (nginx) приходит Host домена — Vite его иначе блокирует.
    allowedHosts: ['localhost', '.orelavtotrans.ru'],
    proxy: {
      '/api': {
        target: process.env.AIS_API_PROXY || 'http://localhost:8086',
        changeOrigin: true,
      },
      // Backend сайта (страница админского возврата back_bilet):
      // в docker — http://web, на хосте — http://localhost:8080.
      '/site-api': {
        target: process.env.SITE_API_PROXY || 'http://localhost:8080',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/site-api/, '/api'),
      },
    },
  },
})
