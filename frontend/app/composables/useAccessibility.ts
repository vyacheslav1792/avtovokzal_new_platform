/**
 * Версия сайта для людей с нарушениями зрения.
 *
 * Требования — ГОСТ Р 52872-2019 «Интернет-ресурсы и другая информация,
 * представленная в электронно-цифровой форме» (п. 6 «Альтернативная версия
 * сайта») и WCAG 2.1 AA. Стандарт требует дать пользователю как минимум:
 * три размера шрифта, три цветовые схемы (чёрным на белом, белым на чёрном,
 * тёмно-синим на голубом), выключение изображений, настройку межбуквенного
 * и межстрочного интервала, выбор шрифта с засечками/без и явный возврат к
 * обычной версии. Всё это — панель `AccessibilityPanel.vue`.
 *
 * В legacy такой версии не было вовсе, переносить было нечего.
 *
 * **Главное в устройстве: обычный режим не затрагивается.** Настройки
 * превращаются в набор классов на `<html>` (`a11y`, `a11y-scheme-*`, …), и
 * ВСЕ стили специального режима (`app/assets/css/accessibility.css`) живут
 * под селектором `html.a11y`. Пока режим выключен, класса нет — ни одно
 * правило не применяется, разметка страниц не меняется.
 *
 * Состояние хранится в **cookie**, а не в localStorage: класс нужен уже в
 * SSR-разметке, иначе страница сначала показалась бы обычной и только потом
 * перекрасилась — для того, кому нужен этот режим, такая вспышка хуже всего.
 */

/** Цветовые схемы. Первые три — обязательный набор ГОСТ Р 52872-2019. */
export const A11Y_SCHEMES = [
  { value: 'white', label: 'Чёрным на белом', fg: '#000000', bg: '#ffffff' },
  { value: 'black', label: 'Белым на чёрном', fg: '#ffffff', bg: '#000000' },
  { value: 'blue', label: 'Тёмно-синим на голубом', fg: '#063462', bg: '#9dd1ff' },
  { value: 'beige', label: 'Коричневым на бежевом', fg: '#4d3b2a', bg: '#f7f3d6' },
] as const

export type A11yScheme = typeof A11Y_SCHEMES[number]['value']

/** Шаги размера шрифта, межбуквенного и межстрочного интервала. */
export const A11Y_STEPS = [1, 2, 3] as const

export type A11yStep = typeof A11Y_STEPS[number]

export interface A11ySettings {
  /** Специальный режим включён. */
  enabled: boolean
  /** Размер шрифта: 1 — обычный (18px), 2 — крупный, 3 — очень крупный. */
  font: A11yStep
  scheme: A11yScheme
  /** Межбуквенный интервал (кернинг): 1 — стандартный, 2 — средний, 3 — большой. */
  letter: A11yStep
  /** Межстрочный интервал: 1 — одинарный (1.5), 2 — полуторный, 3 — двойной. */
  line: A11yStep
  /** Показывать изображения. */
  images: boolean
  /** Шрифт с засечками (иначе — без засечек). */
  serif: boolean
}

export const A11Y_DEFAULTS: A11ySettings = {
  enabled: false,
  font: 1,
  scheme: 'white',
  letter: 1,
  line: 1,
  images: true,
  serif: false,
}

const COOKIE = 'a11y'
const STATE = 'a11y-settings'

/** Год: настройка редкая и менять её при каждом заходе никто не должен. */
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365

/**
 * Значение переменной окружения как «да/нет».
 *
 * **ЛОВУШКА:** в runtimeConfig значение приходит уже разобранным (`destr`),
 * и `NUXT_PUBLIC_ACCESSIBILITY=0` становится ЧИСЛОМ `0`, а не `false` —
 * сравнение с `false` пропустило бы выключение, и кнопка осталась бы на
 * сайте. Поэтому проверяем по смыслу, а не по типу.
 */
function isEnvOn(value: unknown): boolean {
  if (typeof value === 'string') {
    return !['', '0', 'false', 'off', 'no'].includes(value.trim().toLowerCase())
  }

  return Boolean(value)
}

function isStep(value: unknown): value is A11yStep {
  return value === 1 || value === 2 || value === 3
}

/**
 * Cookie правит кто угодно (и она переживает выкладку новой версии), поэтому
 * читаем её как чужие данные: всё неизвестное заменяем значением по умолчанию.
 */
function sanitize(raw: unknown): A11ySettings {
  const value = (raw ?? {}) as Partial<A11ySettings>

  return {
    enabled: value.enabled === true,
    font: isStep(value.font) ? value.font : A11Y_DEFAULTS.font,
    scheme: A11Y_SCHEMES.some(s => s.value === value.scheme)
      ? value.scheme as A11yScheme
      : A11Y_DEFAULTS.scheme,
    letter: isStep(value.letter) ? value.letter : A11Y_DEFAULTS.letter,
    line: isStep(value.line) ? value.line : A11Y_DEFAULTS.line,
    images: value.images !== false,
    serif: value.serif === true,
  }
}

export function useAccessibility() {
  const config = useRuntimeConfig()

  /**
   * Кнопка и вся версия выключаются одной переменной окружения
   * (`ACCESSIBILITY_VERSION=0` в корневом `.env` → `NUXT_PUBLIC_ACCESSIBILITY`).
   */
  const available = computed(() => isEnvOn(config.public.accessibility))

  const cookie = useCookie<A11ySettings | null>(COOKIE, {
    maxAge: COOKIE_MAX_AGE,
    path: '/',
    sameSite: 'lax',
    // httpOnly здесь не нужен и вреден: настройку меняет сам браузер.
    // Секрета в ней нет — только оформление страницы.
  })

  /*
   * `useCookie` на каждый вызов отдаёт НОВЫЙ ref: правка из панели не дошла
   * бы до кнопки в шапке. Поэтому единое состояние держит `useState`, а в
   * cookie пишут функции-мутаторы.
   */
  const settings = useState<A11ySettings>(STATE, () => sanitize(cookie.value))

  const enabled = computed(() => available.value && settings.value.enabled)

  function apply(patch: Partial<A11ySettings>) {
    const next = sanitize({ ...settings.value, ...patch })
    settings.value = next
    cookie.value = next
  }

  function enable() {
    apply({ enabled: true })
  }

  function disable() {
    apply({ enabled: false })
  }

  /** Сброс оформления — сам режим при этом остаётся включённым. */
  function reset() {
    apply({ ...A11Y_DEFAULTS, enabled: settings.value.enabled })
  }

  /**
   * Классы на `<html>`. Единственная точка, где специальный режим вообще
   * как-то влияет на страницу.
   */
  const htmlClass = computed(() => {
    if (!enabled.value) return ''

    const s = settings.value

    return [
      'a11y',
      `a11y-font-${s.font}`,
      `a11y-scheme-${s.scheme}`,
      `a11y-letter-${s.letter}`,
      `a11y-line-${s.line}`,
      s.serif ? 'a11y-serif' : 'a11y-sans',
      s.images ? '' : 'a11y-no-images',
    ].filter(Boolean).join(' ')
  })

  return { available, enabled, settings, htmlClass, apply, enable, disable, reset }
}

/**
 * Навешивает классы режима на `<html>`. Вызывается ОДИН раз — в макете
 * публичной части (`layouts/default.vue`); печатные формы идут с
 * `layout: false` и специального режима не знают: лист бумаги всегда
 * чёрным по белому, а цветная схема на печати только съела бы тонер.
 */
export function useAccessibilityHead() {
  const a11y = useAccessibility()

  useHead({ htmlAttrs: { class: a11y.htmlClass } })

  return a11y
}
