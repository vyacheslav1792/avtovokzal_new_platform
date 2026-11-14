/**
 * Подключение PWA: регистрация service worker и предложение установить
 * приложение.
 *
 * В dev service worker НЕ регистрируется сознательно: он кэширует бандлы,
 * а Vite при каждой правке отдаёт новые — страница начинает показывать
 * старое и ловить ошибки «Failed to fetch dynamically imported module».
 * Выключается целиком переменной `PWA=0` в корневом `.env`
 * (compose → `NUXT_PUBLIC_PWA`): тогда нет ни service worker, ни кнопки
 * установки — ровно как у версии для слабовидящих.
 */
import type { BeforeInstallPromptEvent } from '~/composables/usePwa'

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const install = useInstallPrompt()

  if (!config.public.pwa) return

  // Событие прилетает ДО того, как покупатель доберётся до страницы
  // билетов, поэтому ловим его на старте приложения и держим в состоянии:
  // показать окно установки можно только этим сохранённым объектом.
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    install.prompt.value = event as BeforeInstallPromptEvent
  })

  window.addEventListener('appinstalled', () => {
    install.prompt.value = null
    install.installed.value = true
  })

  if (import.meta.dev || !('serviceWorker' in navigator)) return

  async function registerWorker() {
    try {
      const registration = await navigator.serviceWorker.register('/sw.js')

      // Новая версия сайта: service worker активируется сам (skipWaiting),
      // но страницу не перезагружаем — покупатель может в этот момент
      // заполнять форму покупки. Предлагаем обновиться.
      registration.addEventListener('updatefound', () => {
        const worker = registration.installing
        if (!worker || !navigator.serviceWorker.controller) return

        worker.addEventListener('statechange', () => {
          if (worker.state === 'installed') useAppUpdate().available.value = true
        })
      })

      // Билеты на устройстве есть — заранее кладём в кэш страницу, на
      // которой их показываем: без сети её уже будет неоткуда взять.
      if (await offlineOrdersCount()) await warmOfflineShell()
    }
    catch {
      // Регистрация падает на http-origin и при запрете service worker в
      // настройках браузера — сайт работает как обычный, без офлайна.
    }
  }

  // **ЛОВУШКА:** вешать это только на `load` нельзя. Страницы `/tickets`
  // и `/offline` отрисовываются в браузере (`ssr: false`), их гидрация
  // случается ПОСЛЕ загрузки документа — событие к тому моменту уже
  // прошло, обработчик не вызывается, и service worker не регистрируется
  // ровно на тех страницах, ради которых он нужен.
  if (document.readyState === 'complete') registerWorker()
  else window.addEventListener('load', registerWorker, { once: true })
})
