import { setSessionCookie } from '~~/server/utils/session'

/**
 * Вход в кабинет покупателя от имени сотрудника (ссылка выдаётся в АИС,
 * право `customers.impersonate`).
 *
 * Код обменивает на токен СЕРВЕР, своим запросом к backend: сам токен
 * кабинета в адресную строку попасть не должен — он осел бы в истории
 * браузера и в журналах nginx. Код одноразовый и живёт две минуты.
 *
 * Выданная сессия помечена способностью `impersonated`: сайт показывает
 * полосу «вы в кабинете покупателя», а покупку, возврат и правку данных
 * backend из неё запрещает (middleware not.impersonated).
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const code = getQuery(event).code

  if (typeof code !== 'string' || code === '') {
    return sendRedirect(event, '/?impersonate=failed', 302)
  }

  let token: string

  try {
    const resp = await $fetch<{ data: { token: string } }>(
      `${config.apiBaseServer}/api/v1/auth/impersonate`,
      { method: 'POST', body: { code } },
    )
    token = resp.data.token
  }
  catch {
    // Код устарел или уже использован — в АИС выдают новую ссылку.
    return sendRedirect(event, '/?impersonate=expired', 302)
  }

  setSessionCookie(event, token)

  return sendRedirect(event, '/lk', 302)
})
