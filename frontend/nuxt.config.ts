// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  modules: ['@nuxt/ui'],
  // Стили специального режима идут отдельным файлом и ПОСЛЕ основного:
  // все их селекторы начинаются с `html.a11y`, поэтому на обычный вид сайта
  // они не влияют (см. app/assets/css/accessibility.css).
  css: ['~/assets/css/main.css', '~/assets/css/accessibility.css'],
  app: {
    head: {
      title: 'Автовокзал57 Орёл — покупка билетов онлайн',
      htmlAttrs: { lang: 'ru' },
      meta: [
        {
          name: 'description',
          content:
            'Официальный сайт автовокзалов Орла и Орловской области: расписание, покупка билетов на автобус онлайн',
        },
        // Плитка Windows — тот же набор, что в legacy (index.php)
        { name: 'msapplication-TileImage', content: '/favicon/ms-icon-144x144.png' },
        // PWA: цвет системной строки у приложения с домашнего экрана —
        // тот же, что у шапки сайта (bg-neutral-900) и у фона иконки.
        { name: 'theme-color', content: '#28292e' },
        // iOS манифест почти не читает: имя и режим без адресной строки
        // задаются этими метками.
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-title', content: 'Автовокзал57' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' },
        { name: 'mobile-web-app-capable', content: 'yes' },
      ],
      // Иконки сайта перенесены из legacy как есть (`avtovokzal57/favicon/`,
      // те же размеры и те же файлы — оранжевый автобус). Без этого списка
      // в заголовке вкладки стояла иконка Nuxt по умолчанию.
      // `/favicon.ico` (16/32/96 из тех же картинок) лежит в `public/` — его
      // браузеры и поисковые роботы запрашивают сами, без ссылки в разметке.
      link: [
        // PWA: без манифеста браузер не предложит установить приложение.
        { rel: 'manifest', href: '/manifest.webmanifest' },
        { rel: 'icon', type: 'image/png', sizes: '16x16', href: '/favicon/favicon-16x16.png' },
        { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon/favicon-32x32.png' },
        { rel: 'icon', type: 'image/png', sizes: '96x96', href: '/favicon/favicon-96x96.png' },
        { rel: 'icon', type: 'image/png', sizes: '192x192', href: '/favicon/android-icon-192x192.png' },
        { rel: 'apple-touch-icon', sizes: '57x57', href: '/favicon/apple-icon-57x57.png' },
        { rel: 'apple-touch-icon', sizes: '60x60', href: '/favicon/apple-icon-60x60.png' },
        { rel: 'apple-touch-icon', sizes: '72x72', href: '/favicon/apple-icon-72x72.png' },
        { rel: 'apple-touch-icon', sizes: '76x76', href: '/favicon/apple-icon-76x76.png' },
        { rel: 'apple-touch-icon', sizes: '114x114', href: '/favicon/apple-icon-114x114.png' },
        { rel: 'apple-touch-icon', sizes: '120x120', href: '/favicon/apple-icon-120x120.png' },
        { rel: 'apple-touch-icon', sizes: '144x144', href: '/favicon/apple-icon-144x144.png' },
        { rel: 'apple-touch-icon', sizes: '152x152', href: '/favicon/apple-icon-152x152.png' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/favicon/apple-icon-180x180.png' },
      ],
    },
  },
  vite: {
    server: {
      // Внутри docker-сети к dev-серверу ходит gotenberg (рендер PDF билетов)
      // с Host: frontend — Vite такие хосты блокирует (403 «This host is not
      // allowed»), из-за чего PDF не собирался. Остальные — имена https-прокси
      // (вход через VK ID): он проксирует сюда с исходным Host страницы.
      allowedHosts: ['frontend', 'localhost', 'avtovokzal57.local', '.orelavtotrans.ru'],
    },
  },
  routeRules: {
    // Покупка: рейс задан адресом (ссылкой можно делиться), но цены,
    // свободные места и статус рейса живут минутами — ни браузер, ни прокси
    // не должны отдавать эту страницу и её payload из кэша.
    '/buy': { headers: { 'cache-control': 'no-store' } },
    '/buy/**': { headers: { 'cache-control': 'no-store' } },
    // Страницы, обязанные открываться БЕЗ СЕТИ (см. public/sw.js):
    // оболочку целиком кладёт в кэш service worker, поэтому она не должна
    // зависеть ни от backend, ни от того, кто вошёл в кабинет — рендерим
    // их только в браузере. Билеты страница читает из IndexedDB.
    '/tickets': { ssr: false },
    '/offline': { ssr: false },
    // Service worker браузер перепроверяет сам, но прокси и CDN не должны
    // отдавать его из кэша: иначе обновление сайта не доедет до
    // установленных приложений.
    '/sw.js': { headers: { 'cache-control': 'no-cache' } },
    '/manifest.webmanifest': { headers: { 'cache-control': 'no-cache' } },
  },
  runtimeConfig: {
    // SSR внутри docker-сети ходит в backend напрямую (сервис web).
    apiBaseServer: 'http://web',
    public: {
      // Адрес высокоуровневого API avtovokzal57 (backend), НЕ API продаж.
      apiBase: 'http://127.0.0.1:8088',
      // Публичный адрес САЙТА для ссылок внутрь печатных форм и писем.
      // Из URL запроса его брать нельзя: PDF рендерит gotenberg по
      // внутреннему http://frontend:3000, и такая ссылка утекает в билет.
      siteUrl: 'http://127.0.0.1:3000',
      // Клиентский ключ Yandex SmartCaptcha (ysc1_…) для формы обратной
      // связи — NUXT_PUBLIC_CAPTCHA_KEY. Пустой = капчи на форме нет
      // (локальный контур: тестовых ключей у сервиса не бывает).
      captchaKey: '',
      // Версия сайта для людей с нарушениями зрения (ГОСТ Р 52872-2019):
      // кнопка в шапке и панель настроек. Выключается целиком —
      // `ACCESSIBILITY_VERSION=0` в корневом `.env` (compose передаёт её как
      // NUXT_PUBLIC_ACCESSIBILITY). Выключенная версия не рендерит ни кнопку,
      // ни панель, а класс режима на `<html>` не ставится.
      accessibility: true,
      // PWA: установка приложения и работа без сети (service worker).
      // `PWA=0` в корневом `.env` (compose → NUXT_PUBLIC_PWA) выключает
      // и регистрацию service worker, и кнопку установки. В dev он не
      // регистрируется в любом случае — кэш мешает Vite.
      pwa: true,
    },
  },
})
