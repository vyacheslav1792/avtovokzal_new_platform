import { safeRedirect, setVkStateCookie } from '~~/server/utils/vk'

/**
 * Адрес авторизации VK ID для кнопки «Войти через VK» — тот же, что отдаёт
 * серверный маршрут `/auth/vk`, но БЕЗ редиректа.
 *
 * Зачем отдельно: на телефоне ссылка прямо на `id.vk.ru` открывается
 * приложением VK (Android App Links / iOS Universal Links), а наш
 * `/auth/vk` уводил туда 302-редиректом — передачу приложению браузер на
 * редиректах не делает, и покупатель каждый раз логинился в вебе заново.
 * Кнопка подставляет этот адрес сразу в `href`, поэтому нажатие приходится
 * на ссылку id.vk.ru, и система отдаёт её приложению.
 *
 * `state` кладём в ту же httpOnly cookie, что и обычный старт, — проверка
 * при возврате не меняется.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const redirect = safeRedirect(getQuery(event).redirect)

  const resp = await $fetch<{ data: { url: string, state: string } }>(
    `${config.apiBaseServer}/api/v1/auth/vk/start`,
    { method: 'POST', body: { redirect } },
  )

  setVkStateCookie(event, resp.data.state)

  return { url: resp.data.url }
})
