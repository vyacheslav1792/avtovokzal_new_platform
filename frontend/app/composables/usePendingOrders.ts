/**
 * Незавершённые покупки: покупатель ушёл на страницу платёжного шлюза и не
 * вернулся — закрыл вкладку, разрядился телефон, пропал интернет.
 *
 * Такой заказ нельзя бросать: места на рейсе зарезервированы, а деньги могли
 * уже списаться — билетов при этом нет. В legacy это решалось баннером
 * поверх страниц, а сама покупка хранилась в PHP-сессии и умирала вместе с
 * ней (закрыл браузер — и концов не найти).
 *
 * Здесь идентификаторы заказов лежат в `localStorage`, поэтому переживают
 * перезапуск браузера; у покупателя с кабинетом backend дополнительно ищет
 * незавершённые заказы по `user_id` — тогда покупка найдётся и с другого
 * устройства.
 */
const STORAGE_KEY = 'pending-orders'

/** Держим немного: это «хвост» последних покупок, а не история. */
const MAX_REMEMBERED = 5

export interface PendingOrder {
  order_id: string
  /** `awaiting_payment` — не оплачен; `awaiting_confirmation` — оплачен, но билетов нет. */
  state: 'awaiting_payment' | 'awaiting_confirmation'
  sber_order_id: string
  from_name: string
  to_name: string
  trip_name: string | null
  date_start: string
  time_start: string
  total_price: number
  places: (number | string)[]
  payment_url: string | null
  /** До какого момента имеет смысл платить (бронь мест); ISO-8601. */
  pay_until: string | null
}

function read(): string[] {
  if (!import.meta.client) return []
  try {
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')
    return Array.isArray(raw) ? raw.filter(id => typeof id === 'string') : []
  }
  catch {
    // Значение испорчено — начинаем с чистого листа, падать тут нечему.
    return []
  }
}

function write(ids: string[]) {
  if (!import.meta.client) return
  localStorage.setItem(STORAGE_KEY, JSON.stringify(ids.slice(0, MAX_REMEMBERED)))
}

/** Запомнить заказ перед уходом на шлюз. */
export function rememberPendingOrder(id: string) {
  write([id, ...read().filter(known => known !== id)])
}

/** Покупка доведена до конца или покупатель её скрыл — больше не напоминаем. */
export function forgetPendingOrder(id: string) {
  write(read().filter(known => known !== id))
}

/**
 * Спросить backend, что с незавершёнными покупками. Пустой ответ — заказов
 * нет либо они уже не спасаются (бронь слетела, шлюз закрыл заказ).
 */
export function usePendingOrders() {
  const api = useApi()
  const { isLoggedIn } = useAuth()

  const items = ref<PendingOrder[]>([])

  async function load() {
    const ids = read()
    // Гостю без запомненных заказов спрашивать нечего — лишний запрос с
    // каждой страницы сайта, да ещё и с походом в платёжный шлюз.
    if (!ids.length && !isLoggedIn.value) {
      items.value = []
      return
    }

    try {
      const resp = await api<{ success: boolean, data: PendingOrder[] }>('/orders/pending', {
        method: 'POST',
        body: { ids },
      })
      items.value = resp.data

      // Заказы, которых backend не вернул, уже неактуальны — чистим память
      // браузера, иначе запрос будет таскать их вечно.
      const alive = new Set(resp.data.map(o => o.order_id))
      const stale = ids.filter(id => !alive.has(id))
      if (stale.length) write(ids.filter(id => alive.has(id)))
    }
    catch {
      // Сеть или шлюз недоступны — молча ничего не показываем, повторим
      // при следующем заходе.
      items.value = []
    }
  }

  return { items, load }
}
