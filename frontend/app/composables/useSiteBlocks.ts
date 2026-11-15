/**
 * Блоки сайта («виджеты»): ключ слота → HTML из админки АИС.
 *
 * В legacy это были PHP-файлы avtovokzal57/vidjets/*.php плюс куски,
 * вбитые прямо в url/index.php — приветствие, обращение, памятки и баннер
 * над формой поиска правились выкладкой кода на боевой сервер.
 *
 * Ключ useAsyncData один на приложение: блоки есть на нескольких страницах,
 * при переходах они берутся из payload, а не запрашиваются снова. Сервер
 * отдаёт только то, что реально надо показать: выключенные блоки и блоки
 * вне срока показа в ответ не попадают.
 */
export type SiteBlocks = Record<string, string>

export function useSiteBlocks() {
  const api = useApi()

  return useAsyncData<SiteBlocks>('site-blocks', async () => {
    try {
      const resp = await api<{ success: boolean, data: SiteBlocks }>('/blocks')

      return resp.data || {}
    }
    catch {
      // Объявление — не повод ронять страницу: покажем её без блоков.
      return {}
    }
  }, {
    default: (): SiteBlocks => ({}),
  })
}
