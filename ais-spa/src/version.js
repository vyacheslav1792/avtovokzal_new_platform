// Обновление АИС, когда на сервере появилась новая сборка.
//
// SPA отдаётся браузеру один раз и живёт в нём неделями: сотрудник держит
// вкладку открытой, index.html приходит из кэша — и человек работает со
// СТАРОЙ версией, не зная об этом. Ловилось 16.11.2026: «не могу вернуть
// багаж» оказалось старой версией страницы возврата, где этого раздела ещё
// не было.
//
// Как устроено: при сборке vite кладёт рядом со статикой `version.json`
// (см. vite.config.js) и подставляет ту же метку в код. Приложение
// опрашивает файл и сравнивает версии.
//
// Перезагружаем ПРИНУДИТЕЛЬНО, но в безопасный момент — на переходе между
// страницами: посреди заполненной формы возврата терять введённое нельзя.
// Пока сотрудник остаётся на странице, он видит полосу с кнопкой «Обновить».
import { ref } from 'vue'

/* eslint-disable no-undef */
export const APP_VERSION = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : 'dev'

/** Вышла новая версия — показываем полосу и перезагружаемся при переходе. */
export const updateAvailable = ref(false)

/** Как часто спрашивать сервер (мс). */
const CHECK_EVERY = 2 * 60 * 1000

async function check() {
  if (updateAvailable.value) return

  try {
    // no-store обязателен: иначе проверка новой версии сама придёт из кэша.
    const r = await fetch(`/version.json?t=${Date.now()}`, { cache: 'no-store' })
    if (!r.ok) return

    const data = await r.json()
    if (data?.version && data.version !== APP_VERSION) updateAvailable.value = true
  } catch (e) {
    // Нет сети или сервер перезапускается — молча ждём следующей проверки.
  }
}

/** Перезагрузка с сервера, а не из кэша. */
export function applyUpdate(path) {
  window.location.replace(path || window.location.pathname + window.location.search)
}

/**
 * Включить слежение за версией.
 *
 * В dev не включаем вовсе: там версия меняется на каждой пересборке, и
 * страница перезагружалась бы без конца (этим занимается HMR).
 */
export function startVersionWatch(router) {
  if (import.meta.env.DEV) return

  check()
  setInterval(check, CHECK_EVERY)

  // Вернулись к вкладке — самое время проверить: её могли не трогать сутки.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') check()
  })

  router.beforeEach((to, from, next) => {
    // Переход на другую страницу — безопасная точка, чтобы подменить бандл.
    if (updateAvailable.value && from.name && to.fullPath !== from.fullPath) {
      applyUpdate(to.fullPath)

      return
    }

    next()
  })
}
