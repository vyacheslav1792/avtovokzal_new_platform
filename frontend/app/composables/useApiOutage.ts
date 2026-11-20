/**
 * Состояние backend с точки зрения сайта: работает, на обслуживании или
 * недоступен.
 *
 * Зачем: раньше любая осечка API выглядела на сайте как пустая форма или
 * вовсе белый экран — покупатель не понимал, он что-то сделал не так или
 * сайт сломался. Теперь ответ 503 на обслуживании и сетевой сбой узнаются
 * по ответу и показываются человеческим текстом (баннер в макете,
 * страница ошибки — error.vue).
 *
 * Выключенная продажа или возврат (поле `feature` в ответе 503) сюда НЕ
 * относятся: это не сбой, а штатное состояние, у него свои объявления на
 * страницах покупки и возврата.
 */
export type ApiOutageKind = 'maintenance' | 'down'

export interface ApiOutage {
  kind: ApiOutageKind
  message: string
}

const MAINTENANCE_FALLBACK = 'Сайт на техническом обслуживании. Покупка билетов будет доступна через несколько минут.'
const DOWN_FALLBACK = 'Сайт временно недоступен. Мы уже разбираемся — попробуйте повторить через несколько минут.'

export function useApiOutage() {
  return useState<ApiOutage | null>('api-outage', () => null)
}

/**
 * Запомнить состояние backend. Код ответа при обслуживании ставит
 * серверный плагин (plugins/api-outage.server.ts): из обработчика $fetch
 * контекста запроса не видно.
 */
export function reportApiOutage(state: Ref<ApiOutage | null>, outage: ApiOutage | null): void {
  if (outage) state.value = outage
}

/**
 * Отказы, которые приходят с кодом 503, но сайт при этом РАБОТАЕТ:
 * недоступна одна автостанция (у Кром, например, нет интернета), не
 * отвечает сервис продаж, продажа или возврат по станции выключены в
 * АИС. Такие случаи объясняет сама страница — поиск рейсов, карточка
 * тарифа, форма покупки, — и поднимать баннер «сайт на обслуживании»
 * из-за одной станции нельзя (docs/BOARDING_POINTS.md, docs/STATIONS.md).
 */
const PARTIAL_OUTAGE_CODES = [
  'station_unavailable',
  'sales_api_unavailable',
  'station_sales_disabled',
  'station_refunds_disabled',
]

/** Что это за ошибка: обслуживание, недоступность или штатный отказ. */
export function classifyApiError(error: any): ApiOutage | null {
  const status: number | undefined = error?.response?.status ?? error?.statusCode ?? error?.status
  const payload = error?.response?._data ?? error?.data

  // Выключенные продажа/возврат — не сбой.
  if (payload?.feature) return null

  // Обслуживание сайта backend помечает явно (`maintenance`), поэтому
  // один код 503 признаком обслуживания больше не считается: с 21.11.2026
  // им же отвечают «станция недоступна» и «сервис продаж не отвечает».
  if (payload?.maintenance) {
    return { kind: 'maintenance', message: payload?.error || MAINTENANCE_FALLBACK }
  }

  if (PARTIAL_OUTAGE_CODES.includes(payload?.error_code)) return null

  if (status === 503) {
    return { kind: 'down', message: payload?.error || DOWN_FALLBACK }
  }

  // Сервер не ответил вовсе (нет сети, backend не поднялся): статуса нет.
  if (status === undefined) {
    // Браузер без сети — это не поломка сайта, об этом говорят свои
    // сообщения (офлайн-билеты, страница /offline).
    if (import.meta.client && navigator.onLine === false) return null

    return { kind: 'down', message: DOWN_FALLBACK }
  }

  if (status >= 500) return { kind: 'down', message: payload?.error || DOWN_FALLBACK }

  // 4xx — обычная прикладная ошибка (не нашли рейс, не прошла проверка).
  return null
}
