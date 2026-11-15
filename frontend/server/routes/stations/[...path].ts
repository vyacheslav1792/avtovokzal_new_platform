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
 * Адрес источника — `LEGACY_SITE_PROXY` (`runtimeConfig.legacySiteProxy`):
 * это ДОМЕННОЕ имя старого сайта, а не его IP. Так совпадают Host, SNI и
 * сертификат; сам домен указывает на старый сервер через `extra_hosts`
 * контейнера (в DNS он уже ведёт на НОВЫЙ сервер, и обращение по имени
 * вернулось бы к нам же). Пустой адрес = прокси выключен.
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
