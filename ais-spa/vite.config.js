import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/*
 * Версия сборки. Попадает и в код (`__APP_VERSION__`), и в отдельный файл
 * `version.json` рядом со статикой — по их расхождению приложение понимает,
 * что на сервере лежит уже другая сборка, и перезагружается.
 *
 * Без этого в браузере сотрудника оставалась СТАРАЯ версия SPA: index.html
 * мог прийти из кэша, и человек работал с прошлой сборкой, не зная об этом
 * (ловилось 16.11.2026 — «не могу вернуть багаж» оказалось старой версией
 * страницы возврата).
 */
const BUILD_VERSION = new Date().toISOString()

/** Плагин кладёт version.json в сборку: его и опрашивает приложение. */
function emitVersion() {
  return {
    name: 'ais-emit-version',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'version.json',
        source: JSON.stringify({ version: BUILD_VERSION }),
      })
    },
  }
}

// SPA отчётов АИС новой платформы. Живёт в корне своего хоста (base '/').
// В dev '/api' проксируется на ais-api: в docker — http://ais_web,
// на хосте — http://localhost:8086 (переопределяется env AIS_API_PROXY).
export default defineConfig({
  base: '/',
  define: { __APP_VERSION__: JSON.stringify(BUILD_VERSION) },
  plugins: [vue(), emitVersion()],
  preview: {
    // vite preview отдаёт собранную статику в проде; за nginx приходит Host домена.
    allowedHosts: ['localhost', '.orelavtotrans.ru'],
  },
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
