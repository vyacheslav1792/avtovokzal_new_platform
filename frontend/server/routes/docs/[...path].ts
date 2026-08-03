/**
 * Файлы страниц сайта по прежним адресам legacy: /docs/ustav2024.pdf,
 * /docs/pl/1.png и т. п. В legacy это были обычные файлы в avtovokzal57/docs,
 * теперь они лежат в загрузках backend и грузятся через админку — адреса
 * сохраняем, чтобы старые ссылки и поисковая выдача не сломались.
 */
export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''

  if (!path || path.includes('..')) {
    throw createError({ statusCode: 404, statusMessage: 'Файл не найден' })
  }

  const config = useRuntimeConfig()
  const base = config.apiBaseServer || config.public.apiBase
  const target = `${base}/api/v1/files/docs/${path.split('/').map(encodeURIComponent).join('/')}`

  return proxyRequest(event, target)
})
