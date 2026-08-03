import { assertSameOrigin, setSessionCookie } from '~~/server/utils/session'

/** Положить токен, выданный backend'ом при входе/регистрации, в httpOnly cookie. */
export default defineEventHandler(async (event) => {
  assertSameOrigin(event)

  const body = await readBody<{ token?: string }>(event)
  const token = typeof body?.token === 'string' ? body.token.trim() : ''

  if (!token) {
    throw createError({ statusCode: 422, statusMessage: 'Token is required' })
  }

  setSessionCookie(event, token)

  return { success: true }
})
