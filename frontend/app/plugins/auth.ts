/**
 * Старт приложения: достаём токен из httpOnly cookie (на SSR — из cookie
 * запроса) и подтягиваем профиль, чтобы шапка и форма покупки сразу знали
 * покупателя.
 */
export default defineNuxtPlugin(async () => {
  const { restoreSession, fetchUser } = useAuth()

  await restoreSession()
  await fetchUser()
})
