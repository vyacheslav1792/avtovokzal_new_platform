import { SESSION_COOKIE, setSessionCookie } from '~~/server/utils/session'

/**
 * Отдать портал токен из httpOnly cookie. Заодно продлевает cookie:
 * срок считается от последней активности, как и на бэкенде.
 */
export default defineEventHandler((event) => {
  const token = getCookie(event, SESSION_COOKIE) ?? null

  if (token) {
    setSessionCookie(event, token)
  }

  return { token }
})
