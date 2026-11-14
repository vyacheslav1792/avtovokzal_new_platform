/**
 * Состояние приложения как PWA: установка на устройство, появление новой
 * версии и наличие сети.
 *
 * Регистрацией service worker и подпиской на события занимается плагин
 * `plugins/pwa.client.ts` — здесь только общее состояние и действия,
 * которыми пользуются кнопки.
 */

/** Событие установки приложения — в типах браузера его нет. */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/**
 * Приложение уже запущено с домашнего экрана. На Android это
 * `display-mode: standalone`, на iOS — нестандартный `navigator.standalone`
 * (Safari до сих пор отвечает на медиазапрос «browser»).
 */
export function isStandaloneApp(): boolean {
  if (!import.meta.client) return false

  return window.matchMedia('(display-mode: standalone)').matches
    || (navigator as Navigator & { standalone?: boolean }).standalone === true
}

/** iPhone/iPad: `beforeinstallprompt` там не поддерживается вовсе. */
export function isIosDevice(): boolean {
  if (!import.meta.client) return false

  const ua = navigator.userAgent
  // iPad c iPadOS 13+ представляется Mac'ом — отличаем по тач-экрану.
  return /iphone|ipad|ipod/i.test(ua)
    || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1)
}

/**
 * Приложение запущено с домашнего экрана (а не открыто браузером).
 *
 * Значение выставляется после монтирования: при рендере на сервере режим
 * отображения неизвестен, и разметка разошлась бы с клиентской.
 */
/**
 * Спросить браузер, установлено ли уже это приложение.
 *
 * **Зачем:** на Android удаление ИКОНКИ с домашнего экрана не удаляет
 * само приложение (WebAPK) — оно остаётся в системе, Chrome считает его
 * установленным и на повторную попытку предлагает лишь ярлык. Снаружи
 * это выглядит как «установка сломалась», поэтому в таком случае мы
 * прячем свои предложения установить, а в инструкции объясняем, как
 * удалить приложение по-настоящему.
 *
 * Работает за счёт `related_applications` в манифесте, где приложение
 * указано само на себя; где API нет (Safari, десктоп) — тихо отвечаем
 * «не установлено».
 */
export async function detectInstalledApp(): Promise<boolean> {
  if (!import.meta.client) return false

  const nav = navigator as Navigator & {
    getInstalledRelatedApps?: () => Promise<unknown[]>
  }

  if (!nav.getInstalledRelatedApps) return false

  try {
    return (await nav.getInstalledRelatedApps()).length > 0
  }
  catch {
    return false
  }
}

export function useStandalone() {
  const standalone = useState<boolean>('pwa-standalone', () => false)

  onMounted(() => {
    standalone.value = isStandaloneApp()
  })

  return standalone
}

export function useInstallPrompt() {
  const prompt = useState<BeforeInstallPromptEvent | null>('pwa-install-prompt', () => null)
  const installed = useState<boolean>('pwa-installed', () => false)

  /** Кнопку установки показываем, только если установка вообще возможна. */
  const canInstall = computed(() => !!prompt.value && !installed.value)

  /**
   * На iOS установка делается руками через «Поделиться» → «На экран
   * „Домой“»: системного окна там нет, поэтому показываем инструкцию.
   */
  const needsIosHint = computed(() => import.meta.client
    && isIosDevice()
    && !isStandaloneApp()
    && !installed.value)

  async function install(): Promise<boolean> {
    if (!prompt.value) return false

    await prompt.value.prompt()
    const { outcome } = await prompt.value.userChoice
    // Повторно этим событием воспользоваться нельзя — браузер пришлёт
    // новое, если установка снова станет возможна.
    prompt.value = null

    return outcome === 'accepted'
  }

  return { prompt, installed, canInstall, needsIosHint, install }
}

/** Вышла новая версия сайта — её подхватил service worker. */
export function useAppUpdate() {
  const available = useState<boolean>('pwa-update', () => false)

  function apply() {
    available.value = false
    window.location.reload()
  }

  return { available, apply }
}

/**
 * Есть ли сеть. `navigator.onLine` врёт в одну сторону (отвечает `true`
 * при подключении к Wi-Fi без интернета), но для подписи «показаны
 * сохранённые билеты» этого достаточно — данные всё равно местные.
 */
export function useOnline() {
  const online = ref(true)

  onMounted(() => {
    online.value = navigator.onLine

    const update = () => {
      online.value = navigator.onLine
    }

    window.addEventListener('online', update)
    window.addEventListener('offline', update)

    onBeforeUnmount(() => {
      window.removeEventListener('online', update)
      window.removeEventListener('offline', update)
    })
  })

  return online
}
