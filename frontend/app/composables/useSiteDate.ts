/**
 * Сегодняшняя дата сайта.
 *
 * **ЛОВУШКА, из-за которой это понадобилось:** `new Date().toISOString()`
 * отдаёт время в UTC, а Орёл живёт в +3 — c полуночи до трёх часов ночи
 * такой «сегодня» показывал ВЧЕРАШНЕЕ число (в 02:14 мск 14 ноября форма
 * поиска предлагала 13 ноября, и то же уезжало в ссылки
 * «Сегодня / Завтра / Послезавтра»).
 *
 * Берём не часовой пояс устройства, а пояс автовокзала: расписание и
 * времена отправления в BUSTER — московские wall-clock, и «сегодня» у
 * покупателя из другого региона должно значить то же, что на станции.
 * Заодно это снимает расхождение SSR и клиента: сервер и браузер считают
 * дату по одному и тому же поясу.
 */
export const SITE_TIMEZONE = 'Europe/Moscow'

// `en-CA` даёт ровно ISO-формат — 2026-11-14.
const isoFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: SITE_TIMEZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

/** Дата в поясе автовокзала, `ГГГГ-ММ-ДД`. */
export function siteDate(date: Date = new Date()): string {
  return isoFormatter.format(date)
}

/** `siteDate` со сдвигом на N суток: 0 — сегодня, 1 — завтра. */
export function siteDateShifted(days: number): string {
  return siteDate(new Date(Date.now() + days * 86400_000))
}
