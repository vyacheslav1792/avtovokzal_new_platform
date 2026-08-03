export function useApi() {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  // SSR выполняется внутри docker-сети — там свой адрес backend'а.
  const base = import.meta.server && config.apiBaseServer
    ? config.apiBaseServer
    : config.public.apiBase

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

/** Выбранный рейс переживает переход между страницами. */
export function useSelectedTrip() {
  return useState<{ trip: Trip, from_awtcod: number, from_name: string, to_name: string } | null>(
    'selected-trip',
    () => null,
  )
}
