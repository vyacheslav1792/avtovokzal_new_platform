/**
 * Выключатели сайта из АИС (раздел «Настройки»): работает ли продажа и
 * возврат билетов. В legacy их не было — остановить продажу можно было
 * только правкой кода.
 *
 * Фронт по ним показывает объявление вместо формы, но решает всё равно
 * сервер: проверка стоит и в оформлении заказа, и в возврате
 * (middleware `site.enabled`). Поэтому недоступность настроек трактуем в
 * пользу работы сайта — иначе сетевая осечка закрыла бы продажу.
 */
export interface SiteSettings {
  sales_enabled: boolean
  refunds_enabled: boolean
}

const FALLBACK: SiteSettings = { sales_enabled: true, refunds_enabled: true }

export function useSiteSettings() {
  const api = useApi()

  return useAsyncData<SiteSettings>('site-settings', async () => {
    try {
      const resp = await api<{ success: boolean, data: SiteSettings }>('/settings')

      return { ...FALLBACK, ...resp.data }
    }
    catch {
      return { ...FALLBACK }
    }
  }, {
    default: (): SiteSettings => ({ ...FALLBACK }),
  })
}
