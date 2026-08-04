import { safeRedirect, setVkStateCookie, vkFailure } from '~~/server/utils/vk'

/**
 * Начало входа через VK ID: спрашиваем у backend адрес авторизации
 * (там же генерируются code_challenge и state), запоминаем state в cookie
 * и уводим покупателя на id.vk.ru.
 *
 * Кнопка «Войти через VK» — обычная ссылка на этот адрес, поэтому вход
 * работает и без JS на странице.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const redirect = safeRedirect(getQuery(event).redirect)

  try {
    const resp = await $fetch<{ data: { url: string, state: string } }>(
      `${config.apiBaseServer}/api/v1/auth/vk/start`,
      { method: 'POST', body: { redirect } },
    )

    setVkStateCookie(event, resp.data.state)

    return sendRedirect(event, resp.data.url, 302)
  }
  catch {
    // Не настроен VK или backend недоступен — возвращаем на обычный вход.
    return vkFailure(event, 'unavailable')
  }
})
