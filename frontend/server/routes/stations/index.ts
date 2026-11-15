/**
 * Корень станционной системы старого сайта (`/stations`) — тот же прокси,
 * что и для вложенных адресов: `[...path]` на пустой путь не срабатывает.
 * См. соседний `[...path].ts` — там же про то, что раздел надо перенести.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const base = (config.legacySiteProxy as string | undefined)?.replace(/\/+$/, '')

  if (!base) {
    throw createError({
      statusCode: 404,
      statusMessage: 'Станционная система недоступна: прокси на старый сайт не настроен',
    })
  }

  const query = getRequestURL(event).search

  return proxyRequest(event, `${base}/stations/${query}`, {
    headers: {
      // См. соседний [...path].ts: без этого заголовка старый сайт
      // переадресует запрос на домен, который ведёт сюда же.
      'x-proxy-from': 'avtovokzal-new-platform',
    },
  })
})
