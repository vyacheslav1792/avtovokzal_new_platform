/**
 * Пассажиры, сохранённые покупателем в профиле.
 *
 * Билеты обычно берут на себя и на одних и тех же людей, поэтому ФИО с
 * документом хранятся в кабинете: форма покупки подставляет их одним
 * выбором, а после покупки предлагает сохранить новых.
 */
export interface SavedPassenger {
  id: number
  last_name: string
  first_name: string
  middle_name: string | null
  full_name: string
  birth_date: string
  gender: 'М' | 'Ж'
  doc_type: number
  doc_series: string | null
  doc_number: string
  /** Серия и номер одной строкой — в таком виде документ вводится на форме. */
  document: string
  citizenship: number
  last_used_at: string | null
}

export interface SavedPassengerPayload {
  last_name: string
  first_name: string
  middle_name?: string
  birth_date: string
  gender: 'М' | 'Ж'
  doc_type: number
  doc_number: string
  citizenship: number
}

export function useSavedPassengers() {
  const api = useApi()
  const { isLoggedIn } = useAuth()
  const items = useState<SavedPassenger[]>('saved-passengers', () => [])
  const loaded = useState<boolean>('saved-passengers-loaded', () => false)

  /** Подтянуть список (для гостя — пустой, запроса не делаем). */
  async function load(force = false): Promise<SavedPassenger[]> {
    if (!isLoggedIn.value) {
      items.value = []
      return items.value
    }
    if (loaded.value && !force) return items.value

    try {
      const resp = await api<{ success: boolean, data: SavedPassenger[] }>('/cabinet/passengers')
      items.value = resp.data
      loaded.value = true
    }
    catch {
      // Список — удобство, а не условие покупки: молча остаёмся без него.
      items.value = []
    }

    return items.value
  }

  async function create(payload: SavedPassengerPayload): Promise<SavedPassenger> {
    const resp = await api<{ success: boolean, data: SavedPassenger }>('/cabinet/passengers', {
      method: 'POST',
      body: payload,
    })
    await load(true)

    return resp.data
  }

  async function update(id: number, payload: SavedPassengerPayload): Promise<SavedPassenger> {
    const resp = await api<{ success: boolean, data: SavedPassenger }>(`/cabinet/passengers/${id}`, {
      method: 'PUT',
      body: payload,
    })
    await load(true)

    return resp.data
  }

  async function remove(id: number): Promise<void> {
    await api(`/cabinet/passengers/${id}`, { method: 'DELETE' })
    items.value = items.value.filter(p => p.id !== id)
  }

  return { items, load, create, update, remove }
}
