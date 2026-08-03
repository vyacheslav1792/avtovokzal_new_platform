export function useApi() {
  const config = useRuntimeConfig()

  // SSR выполняется внутри docker-сети — там свой адрес backend'а.
  const base = import.meta.server && config.apiBaseServer
    ? config.apiBaseServer
    : config.public.apiBase

  return $fetch.create({
    baseURL: `${base}/api/v1`,
    headers: { Accept: 'application/json' },
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
  doc_number: string
  baggage: number
}

/** Выбранный рейс переживает переход между страницами. */
export function useSelectedTrip() {
  return useState<{ trip: Trip, from_awtcod: number, from_name: string, to_name: string } | null>(
    'selected-trip',
    () => null,
  )
}
