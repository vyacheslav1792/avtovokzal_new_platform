/**
 * Купленные билеты на устройстве — то, ради чего сайт стал PWA.
 *
 * В автобусе сети может не быть вовсе, а билет показать надо: посадка идёт
 * по документу, но контролёру нужны рейс, место и номер билета. Поэтому
 * данные печатной формы (`GET /orders/{id}/print` — тот же источник, что у
 * страницы `/print/{id}` и у PDF) складываются в IndexedDB сразу после
 * покупки и обновляются при заходе в кабинет, а страница `/tickets` читает
 * их локально и работает без единого запроса в сеть.
 *
 * Почему IndexedDB, а не кэш service worker: ответы API кэшировать нельзя
 * (они ходят с Bearer-токеном кабинета, и чужой браузерный кэш — не место
 * для персональных данных), а здесь мы храним ровно то, что нужно
 * показать, и умеем это чистить — по сроку и по кнопке покупателя.
 *
 * Хранилище общее для гостя и для кабинета: гость, купивший билет с
 * телефона, свои билеты офлайн тоже получает — у него нет даже кабинета,
 * где их можно было бы потом найти.
 */

/** Багажная квитанция (у билета их столько, сколько мест багажа). */
export interface OfflineBaggageTicket {
  serial: string
  number: string
}

/** Пассажирский билет — состав полей печатной формы. */
export interface OfflineTicket {
  serial: string
  number: string
  place: string | number
  tariff: string
  price: number
  baggage: number
  price_bag: number
  baggage_tickets: OfflineBaggageTicket[]
  passenger: string
  citizenship: string
  doc_name: string
  doc_number: string
  birth_date: string
  gender: string
}

/** Ответ `GET /orders/{id}/print`. */
export interface OfflineOrderData {
  order_id: string
  from_name: string
  to_name: string
  trip_name: string
  carrier: string
  date_start: string
  time_start: string
  date_end: string | null
  time_end: string | null
  sold_at: string
  back_code: string | null
  fiscal_sign: string | null
  total_price: number
  depersonalized: boolean
  tickets: OfflineTicket[]
}

/** Строка хранилища: данные заказа и отметка, когда их обновляли. */
export interface OfflineOrder {
  order_id: string
  /** Время последнего обновления с сервера, мс (Date.now). */
  saved_at: number
  order: OfflineOrderData
}

const DB_NAME = 'avtovokzal-offline'
const DB_VERSION = 1
const STORE = 'orders'

/**
 * Сколько держим билет после отправления рейса. Сутки — чтобы билет
 * оставался под рукой до конца поездки (ночные рейсы приходят на
 * следующий день), но не превращался в вечный архив персональных данных
 * на чужом, может быть, телефоне.
 */
const KEEP_AFTER_DEPARTURE_MS = 24 * 60 * 60 * 1000

/**
 * Предельный срок хранения от последнего обновления. Через 30 дней после
 * отправления backend обезличивает печатную форму (`depersonalized`), и
 * держать копию дольше незачем.
 */
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) {
        request.result.createObjectStore(STORE, { keyPath: 'order_id' })
      }
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

/**
 * Выполнить операцию над хранилищем.
 *
 * Любая ошибка гасится: IndexedDB может быть недоступна (приватный режим,
 * запрет хранения данных сайта) — тогда офлайн-билетов просто нет, но ни
 * покупка, ни кабинет от этого падать не должны.
 */
async function withStore<T>(
  mode: IDBTransactionMode,
  run: (store: IDBObjectStore) => IDBRequest,
  fallback: T,
): Promise<T> {
  if (!import.meta.client || typeof indexedDB === 'undefined') return fallback

  try {
    const db = await openDb()

    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(STORE, mode)
      const request = run(tx.objectStore(STORE))

      request.onsuccess = () => resolve(request.result as T)
      request.onerror = () => reject(request.error)
      tx.oncomplete = () => db.close()
    })
  }
  catch {
    return fallback
  }
}

/** Все сохранённые заказы: ближайшее отправление первым. */
export async function offlineOrders(): Promise<OfflineOrder[]> {
  const rows = await withStore<OfflineOrder[]>('readonly', store => store.getAll(), [])

  return rows.sort((a, b) => departureTime(a.order) - departureTime(b.order))
}

/** Сколько билетов лежит на устройстве (для кнопок и прогрева кэша). */
export async function offlineOrdersCount(): Promise<number> {
  return await withStore<number>('readonly', store => store.count(), 0)
}

export async function putOfflineOrder(order: OfflineOrderData): Promise<void> {
  await withStore<unknown>(
    'readwrite',
    store => store.put({ order_id: order.order_id, saved_at: Date.now(), order } satisfies OfflineOrder),
    null,
  )
}

export async function removeOfflineOrder(id: string): Promise<void> {
  await withStore<unknown>('readwrite', store => store.delete(id), null)
}

/** Стереть всё — кнопка покупателя и выход из кабинета. */
export async function clearOfflineOrders(): Promise<void> {
  await withStore<unknown>('readwrite', store => store.clear(), null)
}

/** Момент отправления рейса в миллисекундах (для сортировки и чистки). */
export function departureTime(order: OfflineOrderData): number {
  const [y, m, d] = order.date_start.split('-').map(Number)
  const [hh, mm] = (order.time_start || '00:00').split(':').map(Number)

  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1, hh ?? 0, mm ?? 0).getTime()
}

/** Рейс уже ушёл. */
export function isDeparted(order: OfflineOrderData): boolean {
  return departureTime(order) < Date.now()
}

/** Убрать уехавшее и просроченное — вызывается при открытии списка. */
export async function pruneOfflineOrders(): Promise<void> {
  const now = Date.now()

  for (const row of await offlineOrders()) {
    const expired = now - departureTime(row.order) > KEEP_AFTER_DEPARTURE_MS
      || now - row.saved_at > MAX_AGE_MS

    if (expired) await removeOfflineOrder(row.order_id)
  }
}

/**
 * Попросить service worker подготовиться к работе без сети: положить в кэш
 * оболочку `/tickets` и её скрипты. Без этого офлайн-доступ появлялся бы
 * только у тех, кто хотя бы раз открывал страницу билетов при сети.
 */
export async function warmOfflineShell(): Promise<void> {
  if (!import.meta.client) return

  navigator.serviceWorker?.controller?.postMessage({ type: 'CACHE_SHELL' })

  // Скрипты страниц грузятся лениво — прогреваем их тем же способом, каким
  // Nuxt подгружает маршруты заранее; service worker подхватит файлы в кэш
  // по дороге. `/app` — стартовый экран приложения, с него без сети и
  // начинают.
  try {
    await Promise.all([
      preloadRouteComponents('/tickets'),
      preloadRouteComponents('/app'),
    ])
  }
  catch {
    // Прогрев — приятное дополнение, а не условие работы.
  }
}

/**
 * Забыть кэш оболочек: в нём лежит серверная разметка `/contacts`, а в её
 * шапке — e-mail вошедшего покупателя. Вызывается при выходе из кабинета
 * вместе с чисткой билетов.
 */
export function clearOfflineShell(): void {
  if (!import.meta.client) return

  navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_SHELL' })
}

/**
 * Сохранение и обновление билетов.
 *
 * Данные берутся у backend, поэтому живёт композаблом (нужен `useApi`),
 * а чтение хранилища — обычными функциями выше: страница `/tickets`
 * читает их и без сети, и без авторизации.
 */
export function useOfflineTickets() {
  const api = useApi()

  /** Скачать печатную форму заказа и положить на устройство. */
  async function capture(orderId: string): Promise<OfflineOrderData | null> {
    try {
      const resp = await api<{ success: boolean, data: OfflineOrderData }>(`/orders/${orderId}/print`)

      // Обезличенный заказ (прошло 30 дней) хранить незачем: ни ФИО, ни
      // документа в нём уже нет.
      if (resp.data.depersonalized) {
        await removeOfflineOrder(orderId)
        return null
      }

      await putOfflineOrder(resp.data)
      await warmOfflineShell()

      return resp.data
    }
    catch {
      // Заказ ещё не оформлен, сети нет, backend недоступен — офлайн-копия
      // просто не обновится, покупку это не ломает.
      return null
    }
  }

  /**
   * Обновить то, что уже лежит на устройстве, и добавить предстоящие
   * поездки из кабинета (у вошедшего покупателя — с любого устройства).
   */
  async function sync(options: { withCabinet?: boolean } = {}): Promise<void> {
    if (!import.meta.client || !navigator.onLine) return

    await pruneOfflineOrders()

    const ids = new Set((await offlineOrders()).map(row => row.order_id))

    if (options.withCabinet) {
      try {
        const resp = await api<{ success: boolean, data: { id: string, status: string, is_upcoming: boolean }[] }>(
          '/cabinet/orders',
          { query: { scope: 'upcoming' } },
        )

        for (const order of resp.data) {
          if (order.status === 'completed') ids.add(order.id)
        }
      }
      catch {
        // Гость, протухший токен или нет сети — обновим то, что уже есть.
      }
    }

    for (const id of ids) await capture(id)
  }

  return { capture, sync }
}
