/* eslint-disable no-undef */
/**
 * Service worker сайта (PWA).
 *
 * Главное, ради чего он нужен: **купленные билеты должны открываться без
 * интернета**. В автобусе у покупателя может не быть сети вовсе (подвал
 * автовокзала, трасса, роуминг), а билет контролёру показать надо.
 *
 * Сами данные билетов лежат не здесь, а в IndexedDB
 * (`app/composables/useOfflineTickets.ts`): сохраняются при покупке и при
 * заходе в кабинет. Service worker отвечает за ДРУГОЕ — за то, чтобы без
 * сети открылась сама страница `/tickets` вместе со своими скриптами
 * Nuxt. Без него офлайн-браузер показал бы «нет подключения» и до
 * сохранённых билетов покупатель бы просто не добрался.
 *
 * Workbox и `@vite-pwa/nuxt` сознательно не подключены: весь нужный здесь
 * код — три стратегии кэширования, а сборочная магия с precache-манифестом
 * плохо уживается с SSR Nuxt (оболочку всё равно отдаёт Nitro, а не
 * статический index.html).
 *
 * **Версию кэша (VERSION) надо поднимать руками**, когда меняется сам
 * service worker: браузер сверяет файл побайтно, а старые кэши чистятся
 * при активации по префиксу версии.
 */

const VERSION = 'v3'

/** HTML страниц-оболочек: их отдаём, когда сети нет. */
const SHELL_CACHE = `shell-${VERSION}`

/** Скрипты, стили и картинки: имена с хэшем, содержимое неизменно. */
const ASSET_CACHE = `assets-${VERSION}`

/**
 * Страницы, обязанные работать офлайн.
 *
 * `/app` (стартовый экран приложения), `/tickets` и `/offline` объявлены
 * `ssr: false` в `nuxt.config.ts` — Nitro отдаёт для них клиентскую
 * оболочку, не зависящую ни от backend, ни от того, кто вошёл.
 *
 * `/contacts` — исключение: страница **серверная** (её читают поисковики),
 * и в кэш ложится готовая разметка с адресами и телефонами. Так контакты
 * видны без сети вообще без запросов — данные уже внутри HTML. Страницы
 * кабинета так кэшировать нельзя: там персональные данные покупателя. В
 * разметке `/contacts` из личного есть только e-mail в шапке, поэтому при
 * выходе из кабинета этот кэш стирается (`CLEAR_SHELL`).
 */
const SHELL_PAGES = ['/app', '/tickets', '/offline', '/contacts']

/** Мелочь, без которой офлайн-страница выглядит сломанной. */
const SHELL_FILES = [
  '/manifest.webmanifest',
  '/img/logo.png',
  '/icons/icon-192.png',
  '/favicon.ico',
]

/** Адрес манифеста — по нему браузер решает, КАК ставить приложение. */
const MANIFEST_URL = '/manifest.webmanifest'

function isAsset(url) {
  return url.pathname.startsWith('/_nuxt/')
    || url.pathname.startsWith('/icons/')
    || url.pathname.startsWith('/favicon')
    || url.pathname.startsWith('/img/')
}

/**
 * Положить адреса в кэш оболочки.
 *
 * Именно `fetch` + `put`, а НЕ `cache.add()`: `add` считает неудачей любой
 * ответ не-2xx и отменяет всю пачку целиком, да ещё и молча — один
 * недоступный файл оставлял бы без офлайна всю страницу билетов.
 * `cache: 'reload'` обязателен: иначе оболочка возьмётся из HTTP-кэша
 * браузера, и в офлайн-копию попадёт разметка прошлой сборки со ссылками
 * на скрипты, которых на сервере уже нет.
 */
async function cacheShell(urls) {
  const shell = await caches.open(SHELL_CACHE)

  await Promise.all(urls.map(async (url) => {
    try {
      const response = await fetch(url, { cache: 'reload' })
      if (response.ok) await shell.put(url, response)
    }
    catch {
      // Сеть отвалилась посреди установки — попробуем в следующий раз.
    }
  }))
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    await cacheShell([...SHELL_PAGES, ...SHELL_FILES])
    // Новый service worker ждать закрытия всех вкладок не должен: обновление
    // страницы билетов не та вещь, ради которой стоит держать покупателя на
    // прошлой версии.
    await self.skipWaiting()
  })())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys()
    await Promise.all(names
      .filter(name => name !== SHELL_CACHE && name !== ASSET_CACHE)
      .map(name => caches.delete(name)))
    await self.clients.claim()
  })())
})

/**
 * Прогрев из приложения: страница сообщает, что билет сохранён, и просит
 * положить в кэш оболочку `/tickets`. Иначе офлайн-доступ появлялся бы
 * только после того, как покупатель сам открыл эту страницу при сети.
 */
self.addEventListener('message', (event) => {
  const data = event.data || {}

  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting()
    return
  }

  if (data.type === 'CACHE_SHELL') {
    event.waitUntil(cacheShell(SHELL_PAGES))
    return
  }

  // Выход из кабинета: в кэше оболочек лежит серверная разметка
  // `/contacts`, а в её шапке — e-mail вошедшего. Телефон может быть
  // общим, поэтому стираем и собираем заново.
  if (data.type === 'CLEAR_SHELL') {
    event.waitUntil((async () => {
      await caches.delete(SHELL_CACHE)
      await cacheShell([...SHELL_PAGES, ...SHELL_FILES])
    })())
  }
})

/** Навигация: сеть, а без неё — сохранённая оболочка. */
async function handleNavigation(request) {
  const url = new URL(request.url)

  try {
    const response = await fetch(request)

    // Оболочку обновляем на каждом удачном заходе: так офлайн-копия
    // остаётся от текущей сборки сайта, а не от той, что стояла при
    // установке service worker.
    if (response.ok && SHELL_PAGES.includes(url.pathname)) {
      const shell = await caches.open(SHELL_CACHE)
      await shell.put(url.pathname, response.clone())
    }

    return response
  }
  catch {
    const shell = await caches.open(SHELL_CACHE)
    const cached = await shell.match(url.pathname)
    if (cached) return cached

    // Любой другой адрес без сети: объясняем, что произошло, и даём
    // дорогу к сохранённым билетам.
    const offline = await shell.match('/offline')
    if (offline) return offline

    return new Response(
      '<!doctype html><meta charset="utf-8"><title>Нет подключения</title>'
      + '<p style="font:16px sans-serif;padding:2rem">Нет подключения к интернету.</p>',
      { status: 503, headers: { 'content-type': 'text/html; charset=utf-8' } },
    )
  }
}

/**
 * Манифест: сначала сеть, кэш — только как запасной вариант.
 *
 * **ЛОВУШКА, из-за которой это понадобилось:** манифест лежал среди
 * статики и отдавался cache-first. Браузер при установке читал СТАРУЮ
 * копию — с прежним `start_url` и прежними настройками, — и приложение
 * ставилось не тем, чем стало после деплоя. Файл маленький, обновляется
 * редко, но прочитан должен быть свежим.
 */
async function handleManifest(request) {
  const shell = await caches.open(SHELL_CACHE)

  try {
    const response = await fetch(request, { cache: 'reload' })
    if (response.ok) await shell.put(MANIFEST_URL, response.clone())

    return response
  }
  catch {
    const cached = await shell.match(MANIFEST_URL)
    if (cached) return cached

    throw new Error('manifest unavailable')
  }
}

/** Статика: из кэша сразу, обновление — в фоне. */
async function handleAsset(request) {
  const cache = await caches.open(ASSET_CACHE)
  const cached = await cache.match(request)

  const fromNetwork = fetch(request)
    .then((response) => {
      if (response.ok) cache.put(request, response.clone())
      return response
    })
    .catch(() => cached)

  // Файлы `_nuxt/*` именуются по хэшу содержимого, поэтому кэш не
  // устаревает: при новой сборке меняется имя, а не содержимое файла.
  return cached || fromNetwork
}

self.addEventListener('fetch', (event) => {
  const { request } = event

  if (request.method !== 'GET') return

  const url = new URL(request.url)

  // Чужие домены (платёжный шлюз, капча, VK ID) не наше дело.
  if (url.origin !== self.location.origin) return

  // Навигации перехватываем ВСЕ, включая кабинет и печатные формы: в кэш
  // они не попадают (`handleNavigation` кладёт только SHELL_PAGES), зато
  // без сети покупатель видит нашу страницу с дорогой к билетам, а не
  // «динозавра» браузера.
  if (request.mode === 'navigate') {
    event.respondWith(handleNavigation(request))
    return
  }

  if (url.pathname === MANIFEST_URL) {
    event.respondWith(handleManifest(request))
    return
  }

  if (isAsset(url)) {
    event.respondWith(handleAsset(request))
  }
})
