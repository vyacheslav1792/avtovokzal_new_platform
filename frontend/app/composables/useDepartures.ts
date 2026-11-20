/**
 * Пункты отправления для формы поиска: автостанции и ПУНКТЫ ПОСАДКИ —
 * остановки на трассе рейсов, с которых тоже продаются билеты
 * (docs/BOARDING_POINTS.md).
 *
 * Зачем общий composable: у пункта посадки свой список «Куда» и свой
 * способ искать рейсы (станцию выбирает сервер по тарифам — от Дросково
 * до Ливен везёт база Орла, а до Орла — база Ливен), и эта разница нужна
 * сразу трём страницам: главной, рейсам и расписанию.
 *
 * Значение в селекте — число для станции (awtcod) и строка `bp:<slug>`
 * для пункта посадки: так один селект хранит и то и другое, а адреса
 * страниц остаются прежними (`?awtcod=` у станций, `?from_point=` у
 * пунктов посадки).
 */
export interface BoardingPointItem {
  slug: string
  name: string
  stations: number[]
}

export type DepartureValue = number | string

const POINT_PREFIX = 'bp:'

export function isBoardingPoint(value: DepartureValue | null | undefined): boolean {
  return typeof value === 'string' && value.startsWith(POINT_PREFIX)
}

export function boardingPointSlug(value: DepartureValue | null | undefined): string {
  return isBoardingPoint(value) ? String(value).slice(POINT_PREFIX.length) : ''
}

export function boardingPointValue(slug: string): string {
  return `${POINT_PREFIX}${slug}`
}

/** Адрес списка «Куда» для выбранного пункта отправления. */
export function destinationsUrl(value: DepartureValue): string {
  return isBoardingPoint(value)
    ? `/boarding-points/${boardingPointSlug(value)}/destinations`
    : `/stations/${value}/destinations`
}

/** Часть адреса страницы рейсов, определяющая пункт отправления. */
export function departureQuery(value: DepartureValue): Record<string, string | number> {
  return isBoardingPoint(value)
    ? { from_point: boardingPointSlug(value) }
    : { awtcod: Number(value) }
}

export function useDepartures() {
  const api = useApi()

  return useAsyncData('departures', async () => {
    /*
     * Пункты посадки грузим ОТДЕЛЬНЫМ запросом и молча пропускаем его
     * неудачу: их может не быть вовсе (справочник пуст), и форма поиска
     * не должна из-за этого остаться без станций.
     */
    const [stations, points] = await Promise.all([
      api<{ success: boolean, data: { awtcod: number, name: string }[] }>('/stations'),
      api<{ success: boolean, data: BoardingPointItem[] }>('/boarding-points')
        .catch(() => ({ success: false, data: [] as BoardingPointItem[] })),
    ])

    return {
      stations: stations.data ?? [],
      points: points.data ?? [],
    }
  })
}

/**
 * Единый список для селекта «Откуда»: сперва автостанции, затем пункты
 * посадки.
 *
 * У пункта посадки к названию добавляется пояснение «остановка в пути»:
 * иначе «Дросково» в списке автовокзалов выглядит как автостанция, а
 * билет оттуда оформляет Орёл или Ливны и кассы на месте нет. Группами
 * список не делим — селект сайта (ResponsiveSelect) плоский, и его поиск
 * работает по одному полю `label`.
 */
export function departureItems(
  stations: { awtcod: number, name: string }[],
  points: BoardingPointItem[],
) {
  return [
    ...stations.map(s => ({ label: s.name, value: s.awtcod as DepartureValue })),
    ...points.map(p => ({
      label: `${p.name} (остановка в пути)`,
      value: boardingPointValue(p.slug) as DepartureValue,
    })),
  ]
}

/** Название пункта отправления по значению селекта. */
export function departureLabel(
  value: DepartureValue | null | undefined,
  stations: { awtcod: number, name: string }[],
  points: BoardingPointItem[],
): string {
  if (value === null || value === undefined) return ''

  if (isBoardingPoint(value)) {
    return points.find(p => p.slug === boardingPointSlug(value))?.name ?? ''
  }

  return stations.find(s => s.awtcod === Number(value))?.name ?? ''
}
