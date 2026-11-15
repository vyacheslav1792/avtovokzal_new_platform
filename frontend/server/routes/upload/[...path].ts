/**
 * Картинки, загруженные через админку АИС: /upload/banner.jpg и т. п.
 *
 * Адрес повторяет legacy (там картинки клали по FTP в avtovokzal57/upload и
 * ссылались на них прямо из текста страницы), а файлы лежат в загрузках
 * backend. Отдельно от /docs — иначе картинки оказались бы в одной папке с
 * PDF регламентирующих документов.
 *
 * Своя папка `public/img` сайта (логотип, hero, плитки направлений) этим
 * маршрутом не затрагивается: у неё другой префикс.
 */
export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''

  if (!path || path.includes('..')) {
    throw createError({ statusCode: 404, statusMessage: 'Файл не найден' })
  }

  const config = useRuntimeConfig()
  const base = config.apiBaseServer || config.public.apiBase
  const target = `${base}/api/v1/files/upload/${path.split('/').map(encodeURIComponent).join('/')}`

  return proxyRequest(event, target)
})
