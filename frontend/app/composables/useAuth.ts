export interface AuthUser {
  id: number
  /** null — кабинет заведён через VK, а почту VK не отдал (её предлагаем добавить). */
  email: string | null
  phone: string | null
  name: string | null
  email_verified: boolean
  phone_verified: boolean
  /** Задан ли пароль: у входа через VK его может не быть вовсе. */
  has_password: boolean
  /** Привязан ли вход через VK. */
  has_vk: boolean
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
    const resp = await api<{ success: boolean, data: AuthUser }>('/auth/password/change', {
      method: 'POST',
      body: { current_password: currentPassword, password },
    })
    // У кабинета из VK пароля не было — после первого он появляется,
    // и форма профиля должна об этом узнать (has_password).
    user.value = resp.data
  }

  /**
   * Добавить e-mail кабинету, вошедшему через VK без почты. Адрес сразу не
   * ставится: backend шлёт письмо со ссылкой, по ней он и подтверждается —
   * иначе по чужому адресу открылись бы чужие покупки.
   */
  async function requestEmail(email: string) {
    const resp = await api<{ success: boolean, message: string }>('/auth/email', {
      method: 'POST',
      body: { email },
    })

    return resp.message
  }

  /** Подтверждение e-mail по ссылке из письма (страница /confirm-email). */
  async function confirmEmail(confirmToken: string) {
    const resp = await api<{ success: boolean, data: { email: string } }>('/auth/email/confirm', {
      method: 'POST',
      body: { token: confirmToken },
    })

    if (token.value) await fetchUser(true)

    return resp.data.email
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
    requestEmail,
    confirmEmail,
  }
}

/**
 * Какие способы входа настроены на сервере. Кнопку «Войти через VK»
 * показываем только при заведённом приложении VK (на локальном контуре
 * его нет — как и у капчи, тестовых ключей VK не выдаёт).
 */
export function useAuthProviders() {
  const api = useApi()

  return useAsyncData('auth-providers', async () => {
    try {
      const resp = await api<{ success: boolean, data: { vk: boolean } }>('/auth/providers')
      return resp.data
    }
    catch {
      // Недоступность backend не должна ронять страницу входа.
      return { vk: false }
    }
  }, { default: () => ({ vk: false }) })
}

/**
 * Как назвать покупателя в шапке и кабинете. Обычно это e-mail, но у входа
 * через VK его может не быть — тогда имя из профиля VK.
 */
export function userLabel(user: AuthUser | null | undefined): string {
  return user?.email || user?.name || 'Личный кабинет'
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
