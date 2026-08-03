import { assertSameOrigin, clearSessionCookie } from '~~/server/utils/session'

/** Выход: убрать httpOnly cookie (сам токен гасит backend). */
export default defineEventHandler((event) => {
  assertSameOrigin(event)
  clearSessionCookie(event)

  return { success: true }
})
