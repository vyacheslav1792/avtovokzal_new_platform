/**
 * Станционная система старого сайта — пока ПРОКСИРУЕМ, не переносим.
 *
 * `avtovokzal57/stations/*` (диспетчерские экраны, ведомости, печатные
 * формы, план) на новую платформу ещё не переехала и продолжает работать
 * на старом сервере. Чтобы адреса не менялись у тех, кто ими пользуется
 * каждый день, запросы к `/stations/**` уходят на прежний сайт как есть.
 *
 * **Это временно** — раздел надо перенести; см. PLAN.md («Станционная
 * система») и docs/LEGACY.md.
 *
 * Адрес источника — `LEGACY_SITE_PROXY` (`runtimeConfig.legacySiteProxy`),
 * имя виртуального хоста — `LEGACY_SITE_HOST`: старый сайт отвечает по
 * доменному имени, и обращение по одному IP без Host отдало бы чужой
 * виртуальный хост. Пустой адрес = прокси выключен (локальный контур).
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

  const path = getRouterParam(event, 'path') ?? ''

  // `..` в пути — попытка выйти за пределы раздела.
  if (path.includes('..')) {
    throw createError({ statusCode: 404, statusMessage: 'Страница не найдена' })
  }

  const query = getRequestURL(event).search
  const target = `${base}/stations/${path}${query}`

  return proxyRequest(event, target, {
    headers: {
      // Старый сайт различает виртуальные хосты по Host; без него
      // 192.168.2.5 отдаст не тот сайт.
      host: config.legacySiteHost as string,
      /*
       * По этому заголовку .htaccess старого сайта НЕ переадресует нас на
       * https://автовокзал57.рф: домен уже указывает на новый сервер, и
       * редирект гонял бы запрос по кругу (правило правлено 15.11.2026,
       * рядом лежит .htaccess.bak_20261115).
       */
      'x-proxy-from': 'avtovokzal-new-platform',
    },
  })
})
