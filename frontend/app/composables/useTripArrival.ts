import type { Trip } from './useApi'

type TripTime = Pick<Trip, 'date_start' | 'time_start' | 'time_way_minutes' | 'time_end'>
  & { departure_at?: string | null }

/**
 * Прибытие рейса — дата и время.
 *
 * ПОЧЕМУ НЕ ИЗ `time_end`: в базе станции это поле типа TIME, то есть
 * время суток, и Firebird при сложении оборачивает его через 24 часа.
 * У рейса Орёл–Симферополь в пути 1535 минут (25 ч 35 мин), отправление
 * 11:15 — и `time_end` равен 12:50, ровно как у рейса, приезжающего в
 * день отправления. Прежняя догадка «прибытие раньше отправления? значит
 * плюс сутки» на таком рейсе не срабатывала вовсе (12:50 > 11:15), и
 * пассажиру показывалось 18 ноября вместо 19-го. Плюс она в принципе не
 * умеет выразить путь дольше суток.
 *
 * Поэтому считаем от ОТПРАВЛЕНИЯ плюс время в пути — величина та же, по
 * которой страница тарифа пишет «время в пути».
 *
 * Возвращает null, когда прибытие неизвестно: у рейса нет расписания
 * остановок (`time_way_minutes` пустой). Показывать вместо него время
 * отправления нельзя — это и было источником путаницы.
 */
export function tripArrival(trip: TripTime): Date | null {
  const minutes = trip.time_way_minutes

  if (minutes === null || minutes === undefined || minutes <= 0) {
    return null
  }

  const start = tripDeparture(trip)

  if (!start) {
    return null
  }

  return new Date(start.getTime() + minutes * 60_000)
}

/**
 * Отправление, которое ждёт пассажир.
 *
 * У продажи от пункта посадки это НЕ отправление рейса: автобус уходит из
 * Орла в 07:00, а в Дросково подбирает пассажира в 09:15 — и время в пути
 * (`time_way_minutes`) тоже считается от остановки, поэтому прибытие
 * складывается именно с этим моментом (docs/BOARDING_POINTS.md).
 */
export function tripDeparture(trip: TripTime): Date | null {
  const raw = trip.departure_at
    ? trip.departure_at.replace(' ', 'T')
    : `${trip.date_start}T${trip.time_start}`

  const departure = new Date(raw)

  return Number.isNaN(departure.getTime()) ? null : departure
}

/** Сколько суток рейс в пути: 0 — приезжает в день отправления. */
export function tripArrivalDayOffset(trip: TripTime): number {
  const arrival = tripArrival(trip)

  if (!arrival) return 0

  const startDay = new Date(`${trip.date_start}T00:00:00`).getTime()
  const arrivalDay = new Date(
    arrival.getFullYear(), arrival.getMonth(), arrival.getDate(),
  ).getTime()

  return Math.max(0, Math.round((arrivalDay - startDay) / 86_400_000))
}
