import type { H3Event } from 'h3'

/**
 * Сессия портала: Bearer-токен кабинета хранится в httpOnly cookie,
 * которую ставит сам сервер Nuxt.
 *
 * Почему не обычная cookie/localStorage: Safari (ITP) стирает данные,
 * записанные из JS, через 7 дней — покупатель разлогинивался бы каждую
 * неделю. Cookie, выставленная сервером с Set-Cookie, этому не подвержена.
 *
 * Сам токен остаётся Bearer-токеном Sanctum: портал получает его из
 * `GET /api/auth/session` и шлёт в backend в заголовке Authorization —
 * ровно так же, как будет делать мобильное приложение.
 */
export const SESSION_COOKIE = 'av_session'

/** Полгода бездействия — столько же живёт токен на бэкенде (site.token_idle_minutes). */
export const SESSION_MAX_AGE = 60 * 60 * 24 * 183

export function setSessionCookie(event: H3Event, token: string) {
  setCookie(event, SESSION_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: SESSION_MAX_AGE,
    // На https ставим Secure; локальный контур работает по http.
    secure: getRequestProtocol(event) === 'https',
  })
}

export function clearSessionCookie(event: H3Event) {
  deleteCookie(event, SESSION_COOKIE, { path: '/' })
}

/**
 * Куку с сессией меняем только по запросу с нашего же сайта: иначе
 * чужая страница могла бы подсунуть браузеру свой токен и покупатель
 * оказался бы в чужом кабинете.
 */
export function assertSameOrigin(event: H3Event) {
  const origin = getRequestHeader(event, 'origin')
  if (!origin) return // не-браузерный клиент (мобильное приложение) кук не использует

  const host = getRequestHeader(event, 'host')
  if (!host || new URL(origin).host !== host) {
    throw createError({ statusCode: 403, statusMessage: 'Cross-origin session update' })
  }
}
