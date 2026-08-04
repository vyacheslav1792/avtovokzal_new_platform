import type { H3Event } from 'h3'

/**
 * Вход через VK ID со стороны сервера Nuxt.
 *
 * Браузер в VK уводим сами, но ни client_id, ни обмен кода на токен фронт
 * не знает: за них отвечает backend (`auth/vk/start` и `auth/vk/callback`).
 * Здесь остаётся только то, что обязано жить в браузере покупателя:
 * одноразовый `state` в httpOnly cookie (по нему проверяем, что вернулись
 * из той же вкладки, откуда уходили) и адрес возврата внутри сайта.
 */
export const VK_STATE_COOKIE = 'vk_state'

/** Код VK живёт 10 минут — дольше держать начатый вход незачем. */
const VK_STATE_MAX_AGE = 15 * 60

export function setVkStateCookie(event: H3Event, state: string) {
  setCookie(event, VK_STATE_COOKIE, state, {
    httpOnly: true,
    // lax обязателен: cookie должна пережить возврат с id.vk.ru.
    sameSite: 'lax',
    path: '/',
    maxAge: VK_STATE_MAX_AGE,
    secure: getRequestProtocol(event) === 'https',
  })
}

export function takeVkStateCookie(event: H3Event): string | null {
  const state = getCookie(event, VK_STATE_COOKIE) ?? null
  deleteCookie(event, VK_STATE_COOKIE, { path: '/' })

  return state
}

/**
 * Адрес возврата после входа. Пускаем только внутрь сайта: «//evil.tld»
 * и «https://…» браузер увёл бы покупателя на чужую страницу сразу после
 * авторизации (open redirect).
 */
export function safeRedirect(value: unknown, fallback = '/lk'): string {
  const path = typeof value === 'string' ? value.trim() : ''

  if (!path.startsWith('/') || path.startsWith('//')) return fallback

  return path
}

/** Отправить покупателя обратно на страницу входа с понятной причиной. */
export function vkFailure(event: H3Event, reason: string) {
  return sendRedirect(event, `/login?vk_error=${encodeURIComponent(reason)}`, 302)
}
