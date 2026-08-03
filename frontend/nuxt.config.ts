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
  runtimeConfig: {
    // SSR внутри docker-сети ходит в backend напрямую (сервис web).
    apiBaseServer: 'http://web',
    public: {
      // Адрес высокоуровневого API avtovokzal57 (backend), НЕ API продаж.
      apiBase: 'http://127.0.0.1:8080',
    },
  },
})
