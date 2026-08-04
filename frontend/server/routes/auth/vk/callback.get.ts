import { setSessionCookie } from '~~/server/utils/session'
import { safeRedirect, takeVkStateCookie, vkFailure } from '~~/server/utils/vk'

/**
 * Возврат от VK ID: код меняет на токен кабинета backend, а мы кладём
 * выданный Bearer-токен в httpOnly cookie сессии — ровно как при обычном
 * входе (`/api/auth/session`), и уводим покупателя дальше по сайту.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const query = getQuery(event)
  const state = takeVkStateCookie(event)

  // Покупатель нажал «Отмена» на форме VK либо VK отказал.
  if (query.error) {
    return vkFailure(event, query.error === 'access_denied' ? 'denied' : 'failed')
  }

  const code = typeof query.code === 'string' ? query.code : ''
  const deviceId = typeof query.device_id === 'string' ? query.device_id : ''

  // state сверяем с cookie: без этого чужая страница могла бы подсунуть
  // свой код авторизации и покупатель оказался бы в чужом кабинете (CSRF).
  if (!code || !deviceId || !state || query.state !== state) {
    return vkFailure(event, 'state')
  }

  let result: { token: string, redirect: string | null, ask_email: boolean }

  try {
    const resp = await $fetch<{ data: typeof result }>(
      `${config.apiBaseServer}/api/v1/auth/vk/callback`,
      { method: 'POST', body: { code, state, device_id: deviceId } },
    )
    result = resp.data
  }
  catch {
    return vkFailure(event, 'failed')
  }

  setSessionCookie(event, result.token)

  // Кабинет только что заведён и почты у него нет — предлагаем добавить
  // (именно предлагаем: пропустить можно, покупать это не мешает).
  const target = result.ask_email
    ? '/lk/profile?add-email=1'
    : safeRedirect(result.redirect)

  return sendRedirect(event, target, 302)
})
