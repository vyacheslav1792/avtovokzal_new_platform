/**
 * Адрес backend для запросов ИЗ БРАУЗЕРА.
 *
 * Обычно это `apiBase` (http://127.0.0.1:8080 локально). Но страницу можно
 * открыть и через https-прокси — он нужен для входа через VK ID, который
 * принимает только https в адресе возврата. Со страницы по https браузер
 * не даст обратиться к http-API (mixed content), поэтому там ходим на тот
 * же origin: прокси отдаёт под ним и сайт, и `/api/v1/*`
 * (см. docker/caddy/Caddyfile).
 */
function browserApiBase(configured: string): string {
  if (import.meta.server) return configured

  return location.protocol === 'https:' && configured.startsWith('http:')
    ? location.origin
    : configured
}

export function useApi() {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  // SSR выполняется внутри docker-сети — там свой адрес backend'а.
  const base = import.meta.server && config.apiBaseServer
    ? config.apiBaseServer
    : browserApiBase(config.public.apiBase)

  return $fetch.create({
    baseURL: `${base}/api/v1`,
    headers: { Accept: 'application/json' },
    // Токен читаем в момент запроса: после входа/выхода он меняется,
    // а инстанс $fetch переиспользуется.
    onRequest({ options }) {
      if (token.value) {
        options.headers.set('Authorization', `Bearer ${token.value}`)
      }
    },
  })
}

/**
 * Булев флаг в адресе страницы: `?flag`, `?flag=1`, `?flag=true` — включён,
 * отсутствие параметра или `?flag=0` — выключен.
 */
export function queryFlag(value: unknown): boolean {
  if (value === undefined || value === null) return false
  return ['', '1', 'true'].includes(String(value))
}

export interface Trip {
  awtcod: number
  napcod: number
  raicecod: number
  statcode: number
  trip_name: string
  direction_name: string
  carrier: string
  bus: string
  date_start: string
  time_start: string
  time_end: string
  price_full: number
  price_child: number
  price_baggage: number
  free_places: number
  total_places: number
  available: boolean
  internet_sale_available: boolean
  transit: boolean
  canceled: boolean
  disrupt: boolean
  gone: boolean
  /** Ограничение продажи: билет нельзя купить ближе этой остановки. */
  min_kilom: string | null
  /** Ограничение продажи: билет нельзя купить дальше этой остановки. */
  max_kilom: string | null
}

export interface PassengerForm {
  place: number | null
  tariff: 'adult' | 'child'
  last_name: string
  first_name: string
  middle_name: string
  birth_date: string
  gender: 'М' | 'Ж'
  doc_type: number
  doc_number: string
  citizenship: number
  baggage: number
  /** Галочка «Сохранить в профиль» (только для авторизованного покупателя). */
  save_to_profile?: boolean
  /** Подставлен из профиля — тогда сохранять его повторно нечего. */
  saved_id?: number | null
}

/** Типы документов (коды BUSTER, перенос legacy docs/docs_id). */
export const DOC_TYPES = [
  { label: 'Паспорт гражданина РФ', value: 21 },
  { label: 'Свидетельство о рождении', value: 3 },
  { label: 'Паспорт иностранного гражданина', value: 10 },
  { label: 'Вид на жительство', value: 12 },
  { label: 'Временное удостоверение личности', value: 14 },
  { label: 'Справка об утере паспорта', value: 91 },
  { label: 'Паспорт моряка', value: 26 },
  { label: 'Заграничный паспорт гражданина РФ', value: 22 },
  { label: 'Удостоверение личности военнослужащего', value: 4 },
  { label: 'Удостоверение лица без гражданства', value: 13 },
  { label: 'Военный билет военнослужащего', value: 15 },
  { label: 'Справка об освобождении', value: 5 },
  { label: 'Паспорт гражданина СССР', value: 1 },
  { label: 'Паспорт дипломатический', value: 9 },
  { label: 'Паспорт служебный', value: 11 },
]
