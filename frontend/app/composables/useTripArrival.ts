import type { Trip } from './useApi'

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
export function tripArrival(trip: Pick<Trip, 'date_start' | 'time_start' | 'time_way_minutes' | 'time_end'>): Date | null {
  const minutes = trip.time_way_minutes

  if (minutes === null || minutes === undefined || minutes <= 0) {
    return null
  }

  const start = new Date(`${trip.date_start}T${trip.time_start}`)

  if (Number.isNaN(start.getTime())) {
    return null
  }

  return new Date(start.getTime() + minutes * 60_000)
}

/** Сколько суток рейс в пути: 0 — приезжает в день отправления. */
export function tripArrivalDayOffset(
  trip: Pick<Trip, 'date_start' | 'time_start' | 'time_way_minutes' | 'time_end'>,
): number {
  const arrival = tripArrival(trip)

  if (!arrival) return 0

  const startDay = new Date(`${trip.date_start}T00:00:00`).getTime()
  const arrivalDay = new Date(
    arrival.getFullYear(), arrival.getMonth(), arrival.getDate(),
  ).getTime()

  return Math.max(0, Math.round((arrivalDay - startDay) / 86_400_000))
}
