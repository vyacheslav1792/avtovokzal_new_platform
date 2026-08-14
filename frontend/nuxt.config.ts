// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  modules: ['@nuxt/ui'],
  css: ['~/assets/css/main.css'],
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
      ],
    },
  },
  vite: {
    server: {
      // Внутри docker-сети к dev-серверу ходит gotenberg (рендер PDF билетов)
      // с Host: frontend — Vite такие хосты блокирует (403 «This host is not
      // allowed»), из-за чего PDF не собирался. Остальные — имена https-прокси
      // (вход через VK ID): он проксирует сюда с исходным Host страницы.
      allowedHosts: ['frontend', 'localhost', 'avtovokzal57.local'],
    },
  },
  routeRules: {
    // Покупка: рейс задан адресом (ссылкой можно делиться), но цены,
    // свободные места и статус рейса живут минутами — ни браузер, ни прокси
    // не должны отдавать эту страницу и её payload из кэша.
    '/buy': { headers: { 'cache-control': 'no-store' } },
    '/buy/**': { headers: { 'cache-control': 'no-store' } },
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
    },
  },
})
