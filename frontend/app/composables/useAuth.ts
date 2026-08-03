export interface AuthUser {
  id: number
  email: string
  phone: string | null
  name: string | null
  email_verified: boolean
  phone_verified: boolean
}

export interface RegisterPayload {
  email: string
  password: string
  phone: string
  invite_token?: string
  agree_pd: boolean
}

/**
 * Bearer-токен кабинета в памяти приложения. На диске он лежит в httpOnly
 * cookie, которую ставит сервер Nuxt (`server/api/auth/session.*`): cookie
 * и localStorage, записанные из JS, Safari (ITP) стирает через неделю, а
 * серверную cookie — нет. Портал получает токен из `/api/auth/session`
 * и дальше шлёт его в backend как Bearer — тем же способом, каким будет
 * ходить мобильное приложение.
 */
export function useAuthToken() {
  return useState<string | null>('auth-token', () => null)
}

/** Старая cookie токена (до перехода на httpOnly-сессию). */
const LEGACY_TOKEN_COOKIE = 'auth_token'

/**
 * Прочитать токен сессии: на SSR — вместе с cookie запроса, в браузере —
 * обычным запросом (cookie уходит сама). Заодно продлевает срок cookie.
 */
async function readSession(): Promise<string | null> {
  const headers = import.meta.server ? useRequestHeaders(['cookie']) : undefined
  const resp = await $fetch<{ token: string | null }>('/api/auth/session', { headers })

  return resp.token
}

/** Личный кабинет: состояние пользователя и операции входа. */
export function useAuth() {
  const token = useAuthToken()
  const user = useState<AuthUser | null>('auth-user', () => null)
  const api = useApi()

  const isLoggedIn = computed(() => !!user.value)

  /**
   * Восстановить сессию из httpOnly cookie (вызывается плагином при старте).
   * Если в браузере осталась старая cookie `auth_token` — переносим её
   * в серверную сессию, чтобы вошедшие раньше не разлогинились.
   */
  async function restoreSession(): Promise<string | null> {
    token.value = await readSession()

    if (!token.value && import.meta.client) {
      const legacy = useCookie<string | null>(LEGACY_TOKEN_COOKIE)
      if (legacy.value) {
        await persistSession(legacy.value)
        token.value = legacy.value
      }
      legacy.value = null
    }

    return token.value
  }

  /** Отдать токен серверу Nuxt — он положит его в httpOnly cookie. */
  async function persistSession(value: string) {
    await $fetch('/api/auth/session', { method: 'POST', body: { token: value } })
  }

  /** Подтянуть профиль по токену; при протухшем токене — тихо разлогинить. */
  async function fetchUser(force = false): Promise<AuthUser | null> {
    if (!token.value) {
      user.value = null
      return null
    }
    if (user.value && !force) return user.value

    try {
      const resp = await api<{ success: boolean, data: AuthUser }>('/auth/me')
      user.value = resp.data
    }
    catch {
      await forgetSession()
    }

    return user.value
  }

  async function forgetSession() {
    token.value = null
    user.value = null
    try {
      await $fetch('/api/auth/session', { method: 'DELETE' })
    }
    catch {
      // Куки может и не быть — на состояние приложения это не влияет.
    }
  }

  async function accept(data: { token: string, user: AuthUser }) {
    await persistSession(data.token)
    token.value = data.token
    user.value = data.user
  }

  async function login(email: string, password: string) {
    const resp = await api<{ success: boolean, data: { token: string, user: AuthUser } }>(
      '/auth/login',
      { method: 'POST', body: { email, password } },
    )
    await accept(resp.data)
  }

  async function register(payload: RegisterPayload) {
    const resp = await api<{ success: boolean, data: { token: string, user: AuthUser } }>(
      '/auth/register',
      { method: 'POST', body: payload },
    )
    await accept(resp.data)
  }

  async function resetPassword(resetToken: string, password: string) {
    const resp = await api<{ success: boolean, data: { token: string, user: AuthUser } }>(
      '/auth/password/reset',
      { method: 'POST', body: { token: resetToken, password } },
    )
    await accept(resp.data)
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' })
    }
    catch {
      // Токен мог протухнуть — из браузера всё равно выходим.
    }
    await forgetSession()
  }

  async function updateProfile(payload: { phone: string, name?: string }) {
    const resp = await api<{ success: boolean, data: AuthUser }>('/auth/profile', {
      method: 'PUT',
      body: payload,
    })
    user.value = resp.data
  }

  async function changePassword(currentPassword: string, password: string) {
    await api('/auth/password/change', {
      method: 'POST',
      body: { current_password: currentPassword, password },
    })
  }

  return {
    user,
    isLoggedIn,
    restoreSession,
    fetchUser,
    login,
    register,
    logout,
    resetPassword,
    updateProfile,
    changePassword,
  }
}

/** +79101234567 → «+7 910 123-45-67» (бэкенд хранит номер в E.164). */
export function formatPhone(phone: string | null | undefined): string {
  const digits = (phone ?? '').replace(/\D+/g, '')
  if (digits.length !== 11) return phone ?? ''
  return `+${digits[0]} ${digits.slice(1, 4)} ${digits.slice(4, 7)}-${digits.slice(7, 9)}-${digits.slice(9)}`
}

/** Сообщение об ошибке API в человеческом виде (первое поле или общее). */
export function apiErrorMessage(e: any, fallback = 'Не удалось выполнить операцию, попробуйте позже'): string {
  const errors = e?.data?.errors
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors as Record<string, string[]>).flat()[0]
    if (first) return first
  }
  return e?.data?.error ?? e?.data?.message ?? fallback
}

/** Ошибки по полям формы: путь → сообщение. */
export function apiFieldErrors(e: any): Record<string, string> {
  const errors: Record<string, string[]> | undefined = e?.data?.errors
  const flat: Record<string, string> = {}
  for (const [path, messages] of Object.entries(errors ?? {})) {
    flat[path] = messages[0] ?? 'Поле заполнено некорректно'
  }
  return flat
}
