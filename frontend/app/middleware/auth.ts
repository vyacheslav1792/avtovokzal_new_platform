/** Страницы кабинета: без входа отправляем на /login с адресом возврата. */
export default defineNuxtRouteMiddleware(async (to) => {
  const { isLoggedIn, fetchUser } = useAuth()

  if (!isLoggedIn.value) {
    await fetchUser()
  }

  if (!isLoggedIn.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }
})
