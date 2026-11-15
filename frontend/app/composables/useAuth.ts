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
 * Состояние кода подтверждения из письма: отсчёты и остатки, которые
 * считает backend (config/site.php → auth_code). Фронт их только
 * показывает — своих констант не держит, иначе кнопка «отправить снова»
 * включалась бы раньше, чем сервер готов принять запрос.
 */
export interface AuthCodeState {
  email: string
  /** Через сколько секунд можно запросить код заново. */
  resend_after: number
  /** Сколько секунд живёт текущий код. */
  expires_in: number
  /** Сколько попыток ввода осталось у этого кода. */
  attempts_left: number
  /** Сколько кодов на адрес ещё можно получить за сутки. */
  codes_left: number
}

/** Ответ шага «данные приняты»: либо код на почту, либо сразу кабинет. */
export type RegisterResult =
  | { status: 'code_sent', state: AuthCodeState }
  | { status: 'registered' }

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

  try {
    const resp = await $fetch<{ token: string | null }>('/api/auth/session', { headers })

    return resp.token
  }
  catch {
    // Без сети (PWA открыли офлайн ради сохранённых билетов) запрос
    // падает. Приложение при этом обязано подняться: страница `/tickets`
    // читает билеты с устройства и в кабинете не нуждается.
    return null
  }
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

  /**
   * Шаг 1 регистрации: данные уходят на сервер, а кабинет создаётся только
   * после подтверждения адреса кодом из письма (`confirmRegistration`).
   * Исключение — регистрация по приглашению: адрес уже подтверждён
   * ссылкой из письма, и кабинет открывается сразу.
   */
  async function register(payload: RegisterPayload): Promise<RegisterResult> {
    const resp = await api<{
      success: boolean
      data: AuthCodeState & { status: 'code_sent' | 'registered', token?: string, user?: AuthUser }
    }>('/auth/register', { method: 'POST', body: payload })

    if (resp.data.status === 'registered' && resp.data.token && resp.data.user) {
      await accept({ token: resp.data.token, user: resp.data.user })

      return { status: 'registered' }
    }

    return { status: 'code_sent', state: resp.data }
  }

  /** Шаг 2 регистрации: код из письма — и сразу вход в кабинет. */
  async function confirmRegistration(email: string, code: string) {
    const resp = await api<{ success: boolean, data: { token: string, user: AuthUser } }>(
      '/auth/register/confirm',
      { method: 'POST', body: { email, code } },
    )
    await accept(resp.data)
  }

  /** Запросить код для смены пароля (он же — повторная отправка). */
  async function requestPasswordReset(email: string): Promise<AuthCodeState> {
    const resp = await api<{ success: boolean, data: AuthCodeState }>(
      '/auth/password/forgot',
      { method: 'POST', body: { email } },
    )

    return resp.data
  }

  /** Прислать код заново: регистрация или смена пароля. */
  async function resendCode(
    type: 'register' | 'password_reset',
    email: string,
  ): Promise<AuthCodeState> {
    const resp = await api<{ success: boolean, data: AuthCodeState }>(
      '/auth/code/resend',
      { method: 'POST', body: { type, email } },
    )

    return resp.data
  }

  /** Новый пароль по коду из письма — и сразу вход. */
  async function resetPassword(email: string, code: string, password: string) {
    const resp = await api<{ success: boolean, data: { token: string, user: AuthUser } }>(
      '/auth/password/reset',
      { method: 'POST', body: { email, code, password } },
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
    // Билеты с устройства (PWA) стираем именно здесь, при ЯВНОМ выходе:
    // в офлайн-копии лежат ФИО и номера документов пассажиров, а телефон
    // может быть общим. В `forgetSession` этого делать нельзя — она
    // срабатывает и когда `/auth/me` не ответил из-за сети, и билеты
    // пропадали бы ровно тогда, когда они нужнее всего.
    await clearOfflineOrders()
    clearOfflineShell()
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
   * ставится: backend шлёт на него код, и адрес привязывается только
   * после ввода кода — иначе по чужому адресу открылись бы чужие покупки.
   */
  async function requestEmail(email: string): Promise<AuthCodeState> {
    const resp = await api<{ success: boolean, data: AuthCodeState }>('/auth/email', {
      method: 'POST',
      body: { email },
    })

    return resp.data
  }

  /** Подтверждение добавленного e-mail кодом из письма (страница профиля). */
  async function confirmEmail(email: string, code: string) {
    const resp = await api<{ success: boolean, data: { email: string } }>('/auth/email/confirm', {
      method: 'POST',
      body: { email, code },
    })

    await fetchUser(true)

    return resp.data.email
  }

  return {
    user,
    isLoggedIn,
    restoreSession,
    fetchUser,
    login,
    register,
    confirmRegistration,
    requestPasswordReset,
    resendCode,
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
