export interface SiteMenuLink {
  title: string
  url: string
}

export interface SiteMenuGroup {
  title: string
  children: SiteMenuLink[]
}

export type SiteMenuEntry = SiteMenuLink | SiteMenuGroup

export interface SiteMenu {
  header: SiteMenuEntry[]
  footer: SiteMenuLink[]
}

export function isMenuGroup(entry: SiteMenuEntry): entry is SiteMenuGroup {
  return Array.isArray((entry as SiteMenuGroup).children)
}

/**
 * Меню сайта (шапка и подвал) из backend. В legacy оба списка были
 * захардкожены в вёрстке avtovokzal57/index.php, теперь их правят в админке.
 *
 * Ключ useAsyncData один на приложение: шапка есть на каждой странице,
 * при переходах данные берутся из payload, а не запрашиваются снова.
 */
export function useSiteMenu() {
  const api = useApi()

  return useAsyncData<SiteMenu>('site-menu', async () => {
    try {
      const resp = await api<{ success: boolean, data: SiteMenu }>('/menu')

      return resp.data
    }
    catch {
      // Меню — не повод ронять страницу: покажем шапку без ссылок.
      return { header: [], footer: [] }
    }
  }, {
    default: (): SiteMenu => ({ header: [], footer: [] }),
  })
}
