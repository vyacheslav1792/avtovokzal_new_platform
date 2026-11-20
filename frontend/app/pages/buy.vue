<script setup lang="ts">
/**
 * Покупка билетов на выбранный рейс.
 *
 * Рейс задаётся адресом страницы (`/buy?awtcod=&to=&date=&napcod=&raicecod=`),
 * а не состоянием приложения: ссылку на покупку можно отправить или
 * опубликовать. Рейс и места **всегда** запрашиваются заново (`getCachedData`
 * отключён, а страница отдаётся с `Cache-Control: no-store` — routeRules
 * в `nuxt.config.ts`): цены, свободные места и статус рейса меняются
 * каждую минуту.
 */
import type { PassengerForm, Trip } from '~/composables/useApi'
import { DOC_TYPES } from '~/composables/useApi'

const api = useApi()
const route = useRoute()
const toast = useToast()

const awtcod = computed(() => Number(route.query.awtcod ?? 0))
const toName = computed(() => String(route.query.to ?? ''))
const date = computed(() => String(route.query.date ?? ''))
const napcod = computed(() => Number(route.query.napcod ?? 0))
const raicecod = computed(() => Number(route.query.raicecod ?? 0))
/*
 * Пункт посадки — остановка на трассе рейса, с которой садится покупатель
 * (docs/BOARDING_POINTS.md). Станция (`awtcod`) при этом та, что ведёт
 * рейс: от Дросково до Ливен это Орёл, до Орла — Ливны.
 */
const fromPoint = computed(() => String(route.query.from_point ?? ''))

// Кэш useAsyncData отключён: данные берём из payload только при гидрации
// (там они с этого же запроса), а на любом повторном заходе — новый запрос
// к API. Иначе возврат «назад» показывал бы вчерашние места и цены.
const noCache = {
  getCachedData: (key: string, nuxtApp: ReturnType<typeof useNuxtApp>) =>
    nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined,
}

const { data: tripsResp, error: tripError } = await useAsyncData(
  'buy-trip',
  () => api<{ success: boolean, awtname: string, data: Trip[] }>('/trips', {
    // all=1 — рейс должен найтись, даже если продажа на него уже закрыта:
    // иначе вместо понятной причины была бы «рейс не найден».
    query: {
      awtcod: awtcod.value,
      to: toName.value,
      date: date.value,
      all: 1,
      ...(fromPoint.value ? { from_point: fromPoint.value } : {}),
    },
  }),
  { ...noCache, watch: [awtcod, toName, date, fromPoint] },
)

const trip = computed(() =>
  (tripsResp.value?.data ?? []).find(t => t.napcod === napcod.value && t.raicecod === raicecod.value) ?? null)

/*
 * Откуда едем, как это видит покупатель: название пункта посадки
 * («Дросково»), а у обычной продажи — название станции. Оно же уходит в
 * заказ и дальше в билет, письмо и чек.
 */
const fromName = computed(() => tripsResp.value?.awtname ?? '')

/*
 * Прибытие — отправление плюс время в пути (общий `tripArrival`). Ни
 * время, ни дату прибытия нельзя взять из рейса напрямую: `time_end` —
 * это время суток и у рейсов дольше суток пути оборачивается, а даты
 * прибытия в данных рейса нет вовсе.
 */
const arrivesAt = computed(() => (trip.value ? tripArrival(trip.value) : null))

/** Отправление, которое ждёт пассажир: от его остановки, а не рейса. */
const departureTime = computed(() => {
  const t = trip.value
  if (!t) return ''

  const departure = tripDeparture(t)

  return departure
    ? departure.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    : t.time_start.slice(0, 5)
})
const arrivalDate = computed(() => {
  const at = arrivesAt.value

  return at
    ? `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`
    : ''
})

/*
 * Продажу билетов на сайте выключают из АИС (раздел «Настройки») — например
 * на время работ у станции. Проверка на сервере всё равно есть
 * (middleware site.enabled), здесь — чтобы покупатель не заполнял форму
 * впустую.
 */
const { data: siteSettings } = await useSiteSettings()

/** Причина, по которой рейс нельзя купить на сайте (иначе — пусто). */
const unavailableReason = computed(() => {
  if (!siteSettings.value.sales_enabled) {
    return 'Продажа билетов на сайте временно приостановлена. Билеты продаются в кассах автовокзалов.'
  }

  const t = trip.value
  if (!t) return 'Рейс не найден: возможно, ссылка устарела или расписание изменилось.'
  // Транзитный проверяем ДО отмены: у транзитных рейсов CANCELED в BUSTER
  // взведён и без отмены — legacy тоже смотрел его только у нетранзитных.
  if (t.transit) return 'Рейс транзитный — билеты продаются только в кассах автовокзала.'
  if (t.canceled) return 'Рейс отменён.'
  if (t.disrupt) return 'Рейс сорван.'
  if (t.gone) return 'Рейс уже отправился.'
  if (!t.available) return 'Продажа билетов на этот рейс не открыта.'
  if (!t.internet_sale_available) return 'Продажа билетов на этот рейс через интернет запрещена.'
  if (t.min_kilom) return `Продажа ограничена: билет можно купить не ближе чем до «${t.min_kilom}».`
  if (t.max_kilom) return `Продажа ограничена: билет можно купить не дальше чем до «${t.max_kilom}».`
  if (t.free_places < 1) return 'На рейсе нет свободных мест.'

  /*
   * У посадки на остановке число мест в выдаче — нижняя граница, и ноль
   * там не значит «мест нет»: точную картину даёт выбор места ниже
   * (`freePlaces`). Поэтому рейс не закрываем, а ждём ответа станции —
   * иначе отказали бы покупателю там, где места есть.
   */
  const departure = (tripDeparture(t) ?? new Date(`${t.date_start}T${t.time_start}`)).getTime()
  if (departure <= Date.now() + 2000 * 1000) {
    return 'Продажа через сайт закрыта: до отправления осталось меньше получаса. Билет можно купить в кассе автовокзала.'
  }

  return ''
})

/** Назад к рейсам этого маршрута на ту же дату. */
const tripsLink = computed(() => ({
  path: '/trips',
  query: {
    ...(fromPoint.value ? { from_point: fromPoint.value } : { awtcod: awtcod.value }),
    from: fromName.value,
    to: toName.value,
    date: date.value,
    all: 1,
  },
}))

const { data: placesResp, refresh: refreshPlaces } = await useAsyncData(
  'buy-places',
  () => trip.value
    ? api<{ success: boolean, data: { free: number[], taken: number[] } }>('/trips/places', {
      query: {
        awtcod: awtcod.value,
        date: trip.value.date_start,
        napcod: trip.value.napcod,
        raicecod: trip.value.raicecod,
        /*
         * Пункт назначения: с ним станция учитывает участок пути — место,
         * занятое только до середины трассы, для билета до неё свободно.
         * Так картина мест совпадает с тем, что станция даст оформить.
         */
        to: toName.value,
      },
    })
    : Promise.resolve(null),
  { ...noCache, watch: [trip] },
)

// Справочник гражданств меняется раз в годы — его кэш оставляем.
const { data: citizenshipsResp } = await useAsyncData('citizenships', () =>
  api<{ success: boolean, data: { code: number, name: string }[] }>('/citizenships'))

const freePlaces = computed(() => placesResp.value?.data.free ?? [])

/**
 * Станция ответила, и свободных мест НЕТ.
 *
 * Для посадки на остановке это штатный случай: число мест в выдаче рейсов
 * — нижняя граница, и кнопка покупки там не скрывается (место, занятое
 * «Орёл → Дросково», для посадки в Дросково всё равно занято — так
 * устроена вендорная проверка). Значит отказать можно только здесь, когда
 * известна точная картина (docs/BOARDING_POINTS.md).
 */
const placesSoldOut = computed(() => placesResp.value !== null && freePlaces.value.length === 0)
const citizenshipOptions = computed(() =>
  (citizenshipsResp.value?.data ?? []).map(c => ({ label: c.name, value: c.code })))

const baggageOptions = [
  { label: 'Отсутствует', value: 0 },
  { label: '1 место', value: 1 },
  { label: '2 места', value: 2 },
]

/*
 * Правило станции: детский билет — только вместе с полным, не больше
 * N детей на взрослого (в legacy проверялось и на форме — `js/passengers.js`,
 * и на сервере — `url/passengers.php`). Правило приходит с backend и там же
 * выключается (`config/sale_rules.php` → CHILD_TARIFF_REQUIRES_ADULT),
 * поэтому форма не решает за станцию, а лишь не даёт оформить заведомо
 * отказной заказ.
 */
interface SaleRules {
  child_requires_adult: boolean
  max_children_per_adult: number
  child_requires_adult_message: string
}

const { data: saleRulesResp } = await useAsyncData('sale-rules', () =>
  api<{ success: boolean, data: SaleRules }>('/sale-rules'))

const saleRules = computed(() => saleRulesResp.value?.data ?? null)

/** Сколько детских билетов ещё можно взять, не считая самого пассажира. */
function childLimitFor(current: PassengerForm): number {
  const rules = saleRules.value
  if (!rules?.child_requires_adult) return Number.POSITIVE_INFINITY

  const others = passengers.value.filter(p => p !== current)
  const adults = others.filter(p => p.tariff === 'adult').length
  const children = others.filter(p => p.tariff === 'child').length

  return adults * rules.max_children_per_adult - children
}

/**
 * Детский тариф из списка НЕ убираем: исчезнувший пункт покупатель не
 * заметит и не поймёт, почему детского билета нет. Пункт на месте, но
 * выбрать его без полного билета не даём — с объяснением.
 */
const TARIFF_OPTIONS = [
  { label: 'Полный', value: 'adult' },
  { label: 'Детский (до 12 лет)', value: 'child' },
]

function setTariff(passenger: PassengerForm, value: string) {
  if (value === 'child' && childLimitFor(passenger) <= 0) {
    toast.add({
      title: 'Детский тариф недоступен',
      description: saleRules.value?.child_requires_adult_message,
      color: 'warning',
      duration: 8000,
    })
    return
  }

  passenger.tariff = value as PassengerForm['tariff']
}

/** Состав пассажиров нарушает правило — кнопка оплаты не должна срабатывать. */
const childRuleBroken = computed(() => {
  const rules = saleRules.value
  if (!rules?.child_requires_adult) return false

  const adults = passengers.value.filter(p => p.tariff === 'adult').length
  const children = passengers.value.filter(p => p.tariff === 'child').length

  return children > 0 && (adults === 0 || children > adults * rules.max_children_per_adult)
})
const genderOptions = [
  { label: 'Мужской', value: 'М' },
  { label: 'Женский', value: 'Ж' },
]

function emptyPassenger(): PassengerForm {
  return {
    place: null,
    tariff: 'adult',
    last_name: '',
    first_name: '',
    middle_name: '',
    birth_date: '',
    gender: 'М',
    doc_type: 21,
    doc_number: '',
    citizenship: 185,
    // Багажа по умолчанию НЕТ: раньше подставлялось одно место, и
    // покупатель, не трогавший поле, платил за багаж, которого не везёт
    // (ручная кладь местом багажа не считается).
    baggage: 0,
    // Авторизованному предлагаем сохранить пассажира в профиль; гостю
    // галочка не показывается, а backend её игнорирует — сохранять некуда.
    save_to_profile: true,
    saved_id: null,
    // Выбор в поле «Пассажир» ещё не сделан — поля ввода прячем, пока
    // покупатель не укажет, кто едет (см. `chosen` в PassengerForm).
    chosen: false,
  }
}

const passengers = ref<PassengerForm[]>([emptyPassenger()])

// Покупателю из кабинета почта и телефон подставляются: почта не
// редактируется (билеты и чек уходят на адрес кабинета), телефон можно
// поправить — он попадает в заказ и в SMS перевозчика.
// Исключение — кабинет, заведённый через VK без почты: адреса в профиле
// нет, поэтому поле обычное и заполняется вручную.
const { user, isLoggedIn } = useAuth()
const email = ref(user.value?.email ?? '')
const phone = ref(formatPhone(user.value?.phone))
const emailFromProfile = computed(() => isLoggedIn.value && !!user.value?.email)

/*
 * Почта обязательна только гостю. У кабинета она берётся из профиля, а
 * кабинет, заведённый через VK, может остаться вообще без почты — VK отдаёт
 * её не всегда. Требовать адрес в этом случае значит упереться в пустое поле
 * на ровном месте: билеты видны в кабинете, а фискальный чек по 54-ФЗ уйдёт
 * на служебный адрес сайта.
 */
const emailRequired = computed(() => !isLoggedIn.value)

/*
 * Номер телефона в кабинете может отсутствовать (вход через VK без него) —
 * тогда предлагаем запомнить тот, что указан для заказа. Если номер уже
 * есть, галочки нет: молча перетирать чужой или более свежий номер нельзя.
 */
const canSavePhone = computed(() => isLoggedIn.value && !user.value?.phone)
const savePhone = ref(true)

const emailHelp = computed(() => {
  if (emailFromProfile.value) return 'Адрес вашего личного кабинета — билеты придут на него'
  if (!emailRequired.value) return 'Необязательно: билеты будут в личном кабинете. Укажите почту, если хотите получить их письмом'
  return undefined
})

watch(user, (u) => {
  if (u) {
    if (u.email) email.value = u.email
    if (!phone.value) phone.value = formatPhone(u.phone)
  }
})

/*
 * Гостю предлагаем войти или завести кабинет — с ним не придётся вводить
 * почту заново, а билеты будут под рукой. Шаг **необязательный**: покупка
 * без регистрации остаётся полноценной. Отказ НЕ запоминаем: предложение
 * показывается при каждой покупке, иначе увидеть его можно ровно один раз
 * и больше никогда — а решение завести кабинет у покупателя обычно
 * созревает не с первого билета.
 */
const { data: providers } = await useAuthProviders()
const authPromptOpen = ref(false)

onMounted(() => {
  // Рейс не найден или продажа закрыта — предлагать нечего.
  if (!trip.value || unavailableReason.value) return
  if (isLoggedIn.value) return

  authPromptOpen.value = true
})

// Вошёл в соседней вкладке или через VK — предложение больше не нужно.
watch(isLoggedIn, (value) => {
  if (value) authPromptOpen.value = false
})

function skipAuthPrompt() {
  authPromptOpen.value = false
}

/*
 * Пассажиры из профиля: билеты берут на себя и на одних и тех же людей,
 * поэтому ФИО с документом выбираются одним щелчком, а новых предлагаем
 * сохранить (галочка у каждого пассажира, отправляется вместе с заказом).
 */
const { items: savedPassengers, load: loadSavedPassengers } = useSavedPassengers()

await loadSavedPassengers()
// Вход мог случиться уже на этой странице (модалка выше) — подтянем список.
watch(isLoggedIn, value => value && loadSavedPassengers(true))

/**
 * Варианты поля «Пассажир»: сохранённые в профиле и «Другой пассажир».
 *
 * Пункт «Другой» обязателен: без него поле выглядит как выбор из готового
 * списка, и покупатель не понимает, как ввести того, кого в профиле нет.
 * Поля ввода до выбора скрыты — иначе покупатель заполняет их сверху вниз
 * и список профиля просто не замечает.
 */
const NEW_PASSENGER = 0

const savedOptions = computed(() => [
  ...savedPassengers.value.map(p => ({
    label: `${p.full_name} · ${p.document}`,
    value: p.id,
  })),
  { label: 'Другой пассажир', value: NEW_PASSENGER },
])

/** Поле выбора показываем, только когда в профиле кто-то есть. */
const hasSavedPassengers = computed(() => isLoggedIn.value && savedPassengers.value.length > 0)

/** Поля пассажира: либо выбора нет вовсе, либо он уже сделан. */
function passengerFieldsVisible(passenger: PassengerForm): boolean {
  return !hasSavedPassengers.value || !!passenger.chosen
}

/** Выбор в поле «Пассажир»: запись профиля либо «Другой пассажир». */
function choosePassenger(index: number, id: number) {
  const passenger = passengers.value[index]
  if (!passenger) return

  if (id === NEW_PASSENGER) {
    // Чистый бланк, но место, тариф и багаж не трогаем — их уже выбрали.
    Object.assign(passenger, {
      last_name: '',
      first_name: '',
      middle_name: '',
      birth_date: '',
      gender: 'М',
      doc_type: 21,
      doc_number: '',
      citizenship: 185,
      save_to_profile: true,
      saved_id: null,
      chosen: true,
    })

    return
  }

  fillFromSaved(index, id)
}

/** Подставить сохранённого пассажира в форму. */
function fillFromSaved(index: number, id: number) {
  const saved = savedPassengers.value.find(p => p.id === id)
  const passenger = passengers.value[index]

  if (!saved || !passenger) return

  Object.assign(passenger, {
    chosen: true,
    last_name: saved.last_name,
    first_name: saved.first_name,
    middle_name: saved.middle_name ?? '',
    birth_date: saved.birth_date,
    gender: saved.gender,
    doc_type: saved.doc_type,
    doc_number: saved.document,
    citizenship: saved.citizenship,
    // Правки уходят обратно в ЭТУ же запись профиля (saved_id), поэтому
    // галочка включена: покупатель поправил данные — профиль обновится.
    save_to_profile: true,
    saved_id: saved.id,
  })

  for (const field of ['last_name', 'first_name', 'middle_name', 'birth_date', 'doc_number', 'citizenship']) {
    clearError(`passengers.${index}.${field}`)
  }
}



const agree = ref(false)
const submitting = ref(false)

/*
 * Заказ создан, места зарезервированы — остался уход на шлюз. Пока окно
 * открыто (или закрыто, но заказ жив), платить можно по этой же ссылке:
 * второй раз оформлять заказ не нужно, иначе будут две брони на одни места.
 */
const payment = ref<{ orderId: string, url: string, payUntil: string } | null>(null)
const paymentOpen = ref(false)
const cancelling = ref(false)

/** Сколько ещё держатся места — обратный отсчёт в окне и в подсказке. */
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined
onMounted(() => (ticker = setInterval(() => (now.value = Date.now()), 1000)))
onBeforeUnmount(() => clearInterval(ticker))

const holdLeft = computed(() => {
  if (!payment.value) return ''
  const left = new Date(payment.value.payUntil).getTime() - now.value
  if (left <= 0) return ''
  const total = Math.floor(left / 1000)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
})

/**
 * Заказ оформлен — форму закрываем на редактирование: места уже
 * зарезервированы под этот состав пассажиров, и правки в полях на заказ
 * никак не повлияют. Чтобы изменить данные, заказ надо отменить.
 */
const locked = computed(() => !!payment.value && !!holdLeft.value)

function goToGateway() {
  if (payment.value) window.location.href = payment.value.url
}

/** Отказ от заказа: снимаем бронь мест и отменяем платёж. */
async function cancelOrder() {
  if (!payment.value) return

  cancelling.value = true
  try {
    await api(`/orders/${payment.value.orderId}/cancel`, { method: 'POST' })
    forgetPendingOrder(payment.value.orderId)
    payment.value = null
    paymentOpen.value = false
    toast.add({
      title: 'Заказ отменён',
      description: 'Бронь мест снята — их снова можно купить.',
      color: 'success',
    })
    // Свободные места изменились: список мест пересоберём с сервера.
    await refreshPlaces()
  }
  catch (e: any) {
    toast.add({
      title: 'Не удалось отменить заказ',
      description: apiErrorMessage(e, 'Попробуйте ещё раз или просто дождитесь окончания брони'),
      color: 'error',
    })
  }
  finally {
    cancelling.value = false
  }
}

// Ошибки серверной валидации: путь поля → сообщение (показывается под полем).
const serverErrors = ref<Record<string, string>>({})

function fieldError(path: string): string | undefined {
  return serverErrors.value[path]
}

function clearError(path: string) {
  if (serverErrors.value[path]) {
    const { [path]: _, ...rest } = serverErrors.value
    serverErrors.value = rest
  }
}

// Первому пассажиру сразу подставляем первое свободное место.
watch(freePlaces, (free) => {
  if (free.length && passengers.value[0] && !passengers.value[0].place) {
    passengers.value[0].place = free[0]!
  }
}, { immediate: true })

const usedPlaces = computed(() => passengers.value.map(p => p.place))

function availablePlaces(current: number | null) {
  return freePlaces.value
    .filter(p => p === current || !usedPlaces.value.includes(p))
    .map(p => ({ label: `Место ${p}`, value: p }))
}

function addPassenger() {
  if (passengers.value.length >= Math.min(freePlaces.value.length, 5)) return
  const p = emptyPassenger()
  p.place = freePlaces.value.find(fp => !usedPlaces.value.includes(fp)) ?? null
  passengers.value.push(p)
}

function removePassenger(i: number) {
  passengers.value.splice(i, 1)
  serverErrors.value = {}
  // Признаки даты привязаны к индексу — после сдвига они указывали бы на
  // соседа; DateInput взведёт их заново при следующем вводе.
  birthDateInvalid.value = {}
}

/**
 * Дата рождения набрана целиком, но такой даты не существует (31.02 и т. п.).
 * Индекс пассажира → признак; хранится отдельно от `passengers`, потому что
 * весь объект пассажира уходит в заказ.
 */
const birthDateInvalid = ref<Record<number, boolean>>({})

/**
 * Одно сообщение на оба случая: такой даты нет (31.02) либо она вне границ
 * станции. Границы задаёт вендорная процедура `KS_CONTROL_BIRTH_PAS`:
 * пассажир не старше 100 лет и дата в прошлом — билет с другой датой она
 * не оформит, и узнать об этом после оплаты уже поздно.
 */
const BIRTH_DATE_ERROR = 'Проверьте дату рождения: такой даты нет либо пассажир старше 100 лет '
  + '— станция такие билеты не оформляет.'

function priceFor(p: PassengerForm) {
  const t = trip.value
  if (!t) return 0
  const base = p.tariff === 'child' ? t.price_child : t.price_full
  return base + p.baggage * t.price_baggage
}

const total = computed(() => passengers.value.reduce((sum, p) => sum + priceFor(p), 0))

/** Скролл и фокус к первому ошибочному полю. */
async function focusFirstError() {
  await nextTick()
  const first = document.querySelector('[data-error-field]')
  if (!first) return
  first.scrollIntoView({ behavior: 'smooth', block: 'center' })
  const input = first.querySelector<HTMLElement>('input, select, button, textarea')
  input?.focus({ preventScroll: true })
}

async function submit() {
  serverErrors.value = {}

  if (!agree.value) {
    toast.add({ title: 'Для покупки необходимо согласие на обработку персональных данных', color: 'warning' })
    return
  }

  if (passengers.value.some(p => !passengerFieldsVisible(p))) {
    toast.add({
      title: 'Укажите, кто едет',
      description: 'Выберите пассажира из профиля или «Другой пассажир» и заполните данные.',
      color: 'warning',
    })
    return
  }

  if (childRuleBroken.value) {
    toast.add({
      title: 'Детский тариф недоступен',
      description: saleRules.value?.child_requires_adult_message,
      color: 'error',
      duration: 8000,
    })
    return
  }

  if (Object.values(birthDateInvalid.value).some(Boolean)) {
    toast.add({ title: 'Проверьте дату рождения пассажира', color: 'error' })
    await focusFirstError()
    return
  }

  submitting.value = true
  try {
    const resp = await api<{
      success: boolean
      data: { payment_url: string, order_id: string, pay_until: string }
    }>(
      '/orders',
      {
        method: 'POST',
        body: {
          awtcod: awtcod.value,
          napcod: trip.value!.napcod,
          raicecod: trip.value!.raicecod,
          statcod: trip.value!.statcode,
          date: trip.value!.date_start,
          to_name: toName.value,
          // Пункт посадки: название остановки в базе станции сервер
          // возьмёт из справочника сам (параметром его подменить нельзя).
          ...(fromPoint.value ? { from_point: fromPoint.value } : {}),
          email: email.value,
          phone: phone.value,
          // `chosen` — состояние формы, backend про него не знает.
          passengers: passengers.value.map(({ chosen, ...rest }) => rest),
          ...(canSavePhone.value ? { save_phone: savePhone.value } : {}),
          agree_pd: true,
        },
      },
    )
    // На шлюз уходим НЕ молча: в legacy для этого была отдельная страница
    // `/pay` с предупреждением, без неё покупатель не понимает, что вернуться
    // на сайт после оплаты обязательно. Страница лишняя — предупреждение
    // показываем окном прямо здесь.
    rememberPendingOrder(resp.data.order_id)
    payment.value = {
      orderId: resp.data.order_id,
      url: resp.data.payment_url,
      payUntil: resp.data.pay_until,
    }
    paymentOpen.value = true
    submitting.value = false
    return
  }
  catch (e: any) {
    const errors: Record<string, string[]> | undefined = e?.data?.errors

    // Ошибки уровня заказа (рейс, состав пассажиров) — не привязаны к полям.
    const isFieldKey = (k: string) => k.includes('.') || ['email', 'phone', 'agree_pd'].includes(k)
    if (errors && !Object.keys(errors).some(isFieldKey)) {
      toast.add({
        title: 'Не удалось оформить заказ',
        description: Object.values(errors).flat()[0] ?? '',
        color: 'error',
      })
      submitting.value = false
      return
    }

    if (errors && Object.keys(errors).length) {
      // Ошибки по полям: подсветка + сообщение под полем + дежурный тост.
      const flat: Record<string, string> = {}
      for (const [path, messages] of Object.entries(errors)) {
        flat[path] = messages[0] ?? 'Поле заполнено некорректно'
      }
      serverErrors.value = flat
      toast.add({
        title: 'Некоторые поля формы заполнены некорректно',
        description: 'Проверьте выделенные поля.',
        color: 'error',
      })
      await focusFirstError()
    }
    else {
      toast.add({
        title: 'Не удалось оформить заказ',
        description: String(e?.data?.error ?? e?.data?.message ?? e?.message ?? ''),
        color: 'error',
      })
    }
    submitting.value = false
  }
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' })
}

function formatTime(d: Date) {
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <UContainer v-if="!trip || unavailableReason" class="py-8 space-y-4 max-w-4xl">
    <UAlert
      color="warning"
      variant="subtle"
      title="Купить билеты на этот рейс сейчас нельзя"
      :description="tripError
        ? 'Не удалось получить информацию о рейсе. Попробуйте повторить запрос позднее.'
        : unavailableReason"
    />
    <UButton :to="tripsLink" color="neutral" variant="outline" icon="i-lucide-arrow-left">
      Ко всем рейсам маршрута
    </UButton>
  </UContainer>

  <UContainer v-else class="py-8 space-y-6 max-w-4xl">
    <div class="flex gap-2 text-sm">
      <UBadge color="neutral" variant="soft">1. Рейсы</UBadge>
      <UBadge :color="payment ? 'neutral' : 'error'" :variant="payment ? 'soft' : 'solid'">2. Пассажиры</UBadge>
      <UBadge :color="payment ? 'error' : 'neutral'" :variant="payment ? 'solid' : 'soft'">3. Оплата</UBadge>
      <UBadge color="neutral" variant="soft">4. Готово</UBadge>
    </div>

    <!-- Окно с предупреждением закрыли, а заказ жив: платить по той же
         ссылке, второй заказ на те же места оформлять нельзя. -->
    <UAlert
      v-if="payment && !paymentOpen && holdLeft"
      color="warning"
      variant="subtle"
      icon="i-lucide-clock"
      title="Заказ создан и ждёт оплаты"
      :description="`Места держим ещё ${holdLeft}. Форма закрыта на правку — чтобы изменить данные, отмените заказ.`"
      :actions="[
        { label: 'Перейти к оплате', color: 'error', onClick: () => (paymentOpen = true) },
        { label: 'Отменить заказ', color: 'neutral', variant: 'outline', loading: cancelling, onClick: cancelOrder },
      ]"
    />

    <UCard>
      <template #header>
        <div class="font-medium">
          Рейс: {{ trip.trip_name }}–{{ trip.direction_name }} · Перевозчик: {{ trip.carrier }}
        </div>
      </template>
      <div class="flex flex-wrap items-center gap-6">
        <div>
          <!--
            Время отправления — ОТ ОСТАНОВКИ покупателя: рейс уходит из
            Орла в 07:00, а в Дросково подбирает пассажира в 09:15, и
            именно это время попадёт в билет (docs/BOARDING_POINTS.md).
          -->
          <div class="text-2xl font-semibold">{{ departureTime }}</div>
          <div class="text-sm text-muted">{{ formatDate(trip.date_start) }}</div>
          <div class="font-medium">{{ fromName }}</div>
          <div v-if="fromPoint" class="text-xs text-muted">
            остановка в пути · рейс из {{ trip.trip_name }} в {{ trip.time_start.slice(0, 5) }}
          </div>
        </div>
        <UIcon name="i-lucide-arrow-right" class="size-6 text-muted" />
        <div>
          <!--
            Прибытие считается от отправления плюс время в пути: `time_end`
            в базе станции — время СУТОК, и у рейса дольше суток пути оно
            оборачивается (Орёл–Симферополь: 11:15 + 25 ч 35 мин = 12:50).
            Под ним стояла дата ОТПРАВЛЕНИЯ, то есть покупатель видел
            «12:50, 18 ноября» у рейса, приезжающего 19-го.
          -->
          <div class="text-2xl font-semibold">
            <template v-if="arrivesAt">{{ formatTime(arrivesAt) }}</template>
            <!-- У рейса нет времени в пути до этой остановки: `time_end`
                 равен времени ОТПРАВЛЕНИЯ, показывать его нельзя. -->
            <span v-else class="text-muted">—</span>
          </div>
          <div class="text-sm text-muted">
            <template v-if="arrivesAt">{{ formatDate(arrivalDate) }}</template>
            <!-- Прибытие неизвестно (у рейса нет расписания остановок) —
                 дату отправления здесь показывать нельзя, она обманывает. -->
            <template v-else>время прибытия уточняйте на станции</template>
          </div>
          <div class="font-medium">{{ toName }}</div>
        </div>
        <div class="ml-auto text-sm text-muted">
          свободно мест: <b class="text-highlighted">{{ freePlaces.length }}</b>
        </div>
      </div>
    </UCard>

    <!--
      Мест нет именно для этой посадки: от остановки место, занятое на
      участке до неё, станция продать не даст, хотя в списке рейсов оно
      считалось свободным. Поэтому отказ выдаём здесь, а не вместо списка.
    -->
    <UAlert
      v-if="placesSoldOut"
      color="error"
      variant="subtle"
      icon="i-lucide-ticket-x"
      :title="fromPoint ? `От остановки «${fromName}» мест на этот рейс нет` : 'На рейсе нет свободных мест'"
      description="Выберите другой рейс или дату — места могли занять, пока открывалась страница."
      :actions="[{ label: 'Ко всем рейсам маршрута', to: tripsLink, color: 'neutral', variant: 'outline' }]"
    />

    <!--
      Пока заказ ждёт оплаты, форма только на чтение: места уже
      зарезервированы под этот состав, и правки в полях на заказ не повлияют.
      `fieldset[disabled]` выключает нативные поля и кнопки разом.

      **ЛОВУШКА:** одного fieldset мало. Селекты и галочки Nuxt UI (Reka)
      открываются по `pointerdown`, а браузер глушит у выключенных элементов
      только активацию (click) — события указателя до них доходят. Поэтому
      «Место», «Тариф», «Багаж», «Пол», «Гражданство», «Документ» и галочки
      получают `:disabled` ЯВНО, иначе список выбора открывается, хотя поле
      выглядит выключенным.
    -->
    <fieldset :disabled="locked" class="contents">

    <UCard v-for="(p, i) in passengers" :key="i">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">Пассажир {{ i + 1 }}</span>
          <UButton
            v-if="passengers.length > 1"
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            size="sm"
            @click="removePassenger(i)"
          >
            Удалить
          </UButton>
        </div>
      </template>

      <!--
        Кто едет: запись из профиля или «Другой пассажир». Поле появляется,
        только если в профиле кто-то есть, а поля ввода до выбора скрыты —
        иначе покупатель заполняет форму сверху вниз и сохранённых
        пассажиров не замечает. Список — тот же компонент, что у документа:
        «ИВАНОВ ИВАН ИВАНОВИЧ · 4444 555555» в строку не помещается, и
        обрезать его многоточием нельзя.
      -->
      <UFormField
        v-if="hasSavedPassengers"
        label="Пассажир"
        :help="p.chosen ? 'Данные можно поправить — на заказ пойдёт то, что в полях ниже' : 'Выберите из сохранённых в профиле или заполните данные заново'"
        class="mb-4"
      >
        <ResponsiveSelect
          :model-value="p.chosen ? (p.saved_id ?? NEW_PASSENGER) : undefined"
          :items="savedOptions"
          placeholder="Кто едет?"
          title="Кто едет"
          search-placeholder="Фамилия или номер документа"
          class="md:w-2/3"
          :disabled="locked"
          @update:model-value="(id: number) => choosePassenger(i, id)"
        />
      </UFormField>

      <div class="grid gap-4 md:grid-cols-3">
        <UFormField
          label="Место" required
          :error="fieldError(`passengers.${i}.place`)"
          :data-error-field="fieldError(`passengers.${i}.place`) ? '' : undefined"
        >
          <!-- На телефоне список мест раскрывается на весь экран, как и
               список документов: в автобусе их до полусотни, и выбирать их
               в маленьком окошке неудобно. Поиск не нужен — это номера. -->
          <ResponsiveSelect
            v-model="p.place"
            :items="availablePlaces(p.place)"
            title="Место в автобусе"
            :searchable="false"
            :highlight="!!fieldError(`passengers.${i}.place`)"
            :disabled="locked"
            @update:model-value="clearError(`passengers.${i}.place`)"
          />
        </UFormField>
        <UFormField label="Тариф">
          <USelect
            :model-value="p.tariff" :items="TARIFF_OPTIONS" value-key="value" class="w-full"
            :disabled="locked"
            @update:model-value="(value: string) => setTariff(p, value)"
          />
        </UFormField>
        <UFormField label="Багаж / ручная кладь">
          <USelect v-model="p.baggage" :items="baggageOptions" value-key="value" class="w-full" :disabled="locked" />
        </UFormField>
      </div>

      <p v-if="saleRules?.child_requires_adult" class="mt-3 text-sm text-muted">
        {{ saleRules.child_requires_adult_message }}
      </p>

      <!-- Данные пассажира показываем после выбора в поле «Пассажир»:
           место, тариф и багаж к человеку не относятся и видны всегда. -->
      <div v-if="passengerFieldsVisible(p)" class="grid gap-4 md:grid-cols-3 mt-4">
        <UFormField
          label="Фамилия" required
          :error="fieldError(`passengers.${i}.last_name`)"
          :data-error-field="fieldError(`passengers.${i}.last_name`) ? '' : undefined"
        >
          <UInput
            v-model="p.last_name" placeholder="ИВАНОВ" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.last_name`)"
            @input="clearError(`passengers.${i}.last_name`)"
          />
        </UFormField>
        <UFormField
          label="Имя" required
          :error="fieldError(`passengers.${i}.first_name`)"
          :data-error-field="fieldError(`passengers.${i}.first_name`) ? '' : undefined"
        >
          <UInput
            v-model="p.first_name" placeholder="ИВАН" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.first_name`)"
            @input="clearError(`passengers.${i}.first_name`)"
          />
        </UFormField>
        <UFormField
          label="Отчество"
          :error="fieldError(`passengers.${i}.middle_name`)"
          :data-error-field="fieldError(`passengers.${i}.middle_name`) ? '' : undefined"
        >
          <UInput
            v-model="p.middle_name" placeholder="ИВАНОВИЧ" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.middle_name`)"
            @input="clearError(`passengers.${i}.middle_name`)"
          />
        </UFormField>

        <UFormField
          label="Дата рождения" required
          :error="fieldError(`passengers.${i}.birth_date`)
            || (birthDateInvalid[i] ? BIRTH_DATE_ERROR : undefined)"
          :data-error-field="fieldError(`passengers.${i}.birth_date`) ? '' : undefined"
        >
          <DateInput
            v-model="p.birth_date"
            v-model:invalid="birthDateInvalid[i]"
            class="w-full"
            :highlight="!!fieldError(`passengers.${i}.birth_date`) || !!birthDateInvalid[i]"
            @input="clearError(`passengers.${i}.birth_date`)"
          />
        </UFormField>
        <UFormField label="Пол">
          <USelect v-model="p.gender" :items="genderOptions" value-key="value" class="w-full" :disabled="locked" />
        </UFormField>
        <UFormField
          label="Гражданство"
          :error="fieldError(`passengers.${i}.citizenship`)"
          :data-error-field="fieldError(`passengers.${i}.citizenship`) ? '' : undefined"
        >
          <!-- Гражданств под две сотни — на телефоне список раскрывается
               на весь экран с поиском, как и список документов. -->
          <ResponsiveSelect
            v-model="p.citizenship"
            :items="citizenshipOptions"
            title="Гражданство пассажира"
            search-placeholder="Название страны"
            :highlight="!!fieldError(`passengers.${i}.citizenship`)"
            :disabled="locked"
            @update:model-value="clearError(`passengers.${i}.citizenship`); clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>

        <UFormField
          label="Документ"
          :error="fieldError(`passengers.${i}.doc_type`)"
          :data-error-field="fieldError(`passengers.${i}.doc_type`) ? '' : undefined"
        >
          <!-- Тот же компонент, что «Откуда»/«Куда»: на телефоне список
               документов раскрывается на весь экран, а длинные названия
               («Удостоверение личности военнослужащего») переносятся, а не
               прячутся за многоточием — и в поле, и в самом списке. -->
          <ResponsiveSelect
            v-model="p.doc_type"
            :items="DOC_TYPES"
            title="Документ пассажира"
            search-placeholder="Название документа"
            :disabled="locked"
            @update:model-value="clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>
        <UFormField
          label="Серия и номер документа" required :help="docHint(p.doc_type)"
          class="md:col-span-2"
          :error="fieldError(`passengers.${i}.doc_number`)"
          :data-error-field="fieldError(`passengers.${i}.doc_number`) ? '' : undefined"
        >
          <!-- Подсказка формата — под полем (`help`), а не рядом с подписью
               (`hint`): длинная подсказка переносила подпись на вторую
               строку, и поля ряда переставали стоять на одной линии. -->
          <MaskedInput
            v-model="p.doc_number" :mask="docMask(p.doc_type)"
            :placeholder="docPlaceholder(p.doc_type)"
            :inputmode="docMask(p.doc_type) ? 'numeric' : 'text'" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.doc_number`)"
            @input="clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>
      </div>

      <!--
        Новый пассажир — предлагаем запомнить. Подставленный из профиля —
        предлагаем обновить ИМЕННО ЕГО запись: покупатель правил конкретного
        человека, и правка не должна заводить вторую строку в профиле.
      -->
      <UCheckbox
        v-if="isLoggedIn && passengerFieldsVisible(p)"
        v-model="p.save_to_profile"
        class="mt-4"
        :disabled="locked"
        :label="p.saved_id ? 'Обновить данные этого пассажира в профиле' : 'Сохранить пассажира в профиль'"
        :description="p.saved_id
          ? 'Изменения сохранятся в той же записи профиля'
          : 'В следующий раз данные подставятся из кабинета — вводить их заново не придётся'"
      />

      <template #footer>
        <div class="text-right text-sm">
          Стоимость: <b class="text-lg">{{ priceFor(p).toFixed(2) }} ₽</b>
        </div>
      </template>
    </UCard>

    <!-- Состав сломался после удаления пассажира: сами тарифы выбрать
         неправильно не дают, но полного билета могло не остаться. -->
    <UAlert
      v-if="childRuleBroken"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Детский тариф недоступен для такого состава"
      :description="saleRules?.child_requires_adult_message"
    />

    <UButton
      icon="i-lucide-plus"
      variant="outline"
      color="neutral"
      :disabled="passengers.length >= Math.min(freePlaces.length, 5)"
      @click="addPassenger"
    >
      Добавить пассажира
    </UButton>

    </fieldset>

    <UCard>
      <fieldset :disabled="locked" class="contents">
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField
          label="E-Mail для билетов и чека" :required="emailRequired"
          :error="fieldError('email')"
          :help="emailHelp"
          :data-error-field="fieldError('email') ? '' : undefined"
        >
          <UInput
            v-model="email" type="email" placeholder="you@example.com" class="w-full"
            :disabled="emailFromProfile || locked"
            :highlight="!!fieldError('email')"
            @input="clearError('email')"
          />
        </UFormField>
        <UFormField
          label="Номер телефона" required
          :error="fieldError('phone')"
          :data-error-field="fieldError('phone') ? '' : undefined"
        >
          <PhoneInput
            v-model="phone" class="w-full"
            :highlight="!!fieldError('phone')"
            :disabled="locked"
            @input="clearError('phone')"
          />
          <UCheckbox
            v-if="canSavePhone"
            v-model="savePhone"
            class="mt-2"
            :disabled="locked"
            label="Сохранить номер телефона в личном кабинете"
            :ui="{ label: 'text-sm text-muted' }"
          />
        </UFormField>
      </div>

      <UFormField class="mt-4" :error="fieldError('agree_pd')">
        <UCheckbox
          v-model="agree"
          :disabled="locked"
          label="Я даю согласие на обработку моих персональных данных"
        />
      </UFormField>
      </fieldset>

      <template #footer>
        <!-- На телефоне ряд не помещался: подпись сжималась в колонку из
             двух слов, а кнопка ломалась на две строки. Поэтому ниже sm
             раскладываем в столбец, а сумма с кнопкой идут отдельной
             строкой во всю ширину. -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="order-2 sm:order-1 text-sm text-muted sm:max-w-sm">
            Оплачивая заказ, вы соглашаетесь с условиями сервиса.<br>
            Если письма от сайта не приходят — проверьте папку «Спам».
          </div>
          <!-- `flex-wrap`: на экране 320px «Всего: … ₽» и «Перейти к оплате»
               в одну строку не помещались, и кнопка оплаты уезжала за край
               экрана (ловилось прогоном вёрстки на узких экранах). -->
          <div class="order-1 sm:order-2 flex flex-wrap items-center justify-between gap-4">
            <div class="whitespace-nowrap">Всего: <b class="text-2xl">{{ total.toFixed(2) }} ₽</b></div>
            <UButton
              size="xl" color="error" :loading="submitting" :disabled="placesSoldOut"
              class="whitespace-nowrap"
              @click="payment && holdLeft ? (paymentOpen = true) : submit()"
            >
              Перейти к оплате
            </UButton>
          </div>
        </div>
      </template>
    </UCard>

    <!--
      Предупреждение перед уходом на шлюз. В legacy под это была отдельная
      страница `/pay` со сплошным текстом; страница лишняя, но сам текст
      нужен — без него покупатель не понимает, что после оплаты обязательно
      нужно вернуться на сайт, иначе билеты не оформятся.
    -->
    <UModal
      v-model:open="paymentOpen"
      title="Оплата на сайте банка"
      :ui="MOBILE_FULLSCREEN_MODAL"
    >
      <template #body>
        <div class="space-y-3 text-sm">
          <p>
            Сейчас откроется защищённая страница платёжного шлюза. Реквизиты
            карты вводятся на стороне банка — сайт их не получает и не хранит.
          </p>
          <p>
            После оплаты дождитесь возврата на наш сайт: билеты оформляются
            только здесь, и здесь же появится страница с билетами и кодом
            возврата. Обычно возврат происходит автоматически.
          </p>

          <UAlert
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Дождитесь возврата на сайт"
            description="Пока вы не увидели страницу с билетами, поездка не оформлена — с таким заказом в автобус не посадят."
          />

          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-life-buoy"
            title="Если что-то пойдёт не так"
            description="Покупка не потеряется: вернитесь на сайт, и мы сами предложим продолжить оплату или завершить оформление."
          />

          <p v-if="holdLeft" class="text-muted">
            Места на рейсе забронированы — оплатите в течение
            <b class="text-highlighted tabular-nums">{{ holdLeft }}</b>.
          </p>
          <p v-else class="text-error">
            Бронь мест истекла. Выберите рейс заново — оплата этого заказа уже не пройдёт.
          </p>
        </div>
      </template>

      <template #footer>
        <UButton
          color="neutral" variant="outline" icon="i-lucide-x"
          :class="MODAL_BUTTON_CLASS"
          :loading="cancelling"
          @click="cancelOrder"
        >
          Отменить заказ
        </UButton>
        <UButton
          color="neutral" variant="ghost" :class="MODAL_BUTTON_CLASS"
          @click="paymentOpen = false"
        >
          Не сейчас
        </UButton>
        <UButton
          color="error"
          icon="i-lucide-credit-card"
          :class="MODAL_BUTTON_CLASS"
          :disabled="!holdLeft"
          @click="goToGateway"
        >
          Перейти к оплате
        </UButton>
      </template>
    </UModal>

    <!-- Предложение войти перед покупкой. Пропустить можно всегда:
         покупка без регистрации остаётся полноценной. -->
    <UModal
      v-model:open="authPromptOpen"
      title="Войти в личный кабинет?"
      :ui="MOBILE_FULLSCREEN_MODAL"
    >
      <template #body>
        <p class="mb-4">
          С кабинетом почта и телефон подставятся сами, а все билеты — покупки,
          печать и возврат — будут в одном месте.
        </p>
        <ul class="space-y-2 text-sm">
          <li class="flex gap-2">
            <UIcon name="i-lucide-history" class="size-5 text-primary shrink-0" />
            История заказов и билеты всегда под рукой
          </li>
          <li class="flex gap-2">
            <UIcon name="i-lucide-undo-2" class="size-5 text-primary shrink-0" />
            Возврат билета без ввода кода с билета
          </li>
          <li class="flex gap-2">
            <UIcon name="i-lucide-zap" class="size-5 text-primary shrink-0" />
            Быстрая покупка: контакты подставляются автоматически
          </li>
        </ul>
      </template>

      <template #footer>
        <!-- Вход через VK — первым: он не требует ни пароля, ни кода
             из письма, то есть покупку задерживает меньше всего. -->
        <div class="w-full space-y-3">
          <VkLoginButton v-if="providers?.vk" :redirect="route.fullPath" />
          <div class="grid gap-2 sm:flex sm:flex-wrap">
            <UButton
              color="error" class="justify-center"
              :to="{ path: '/login', query: { redirect: route.fullPath } }"
            >
              Войти
            </UButton>
            <UButton
              color="neutral" variant="outline" class="justify-center"
              :to="{ path: '/register', query: { redirect: route.fullPath, ...(email ? { email } : {}) } }"
            >
              Зарегистрироваться
            </UButton>
            <UButton color="neutral" variant="ghost" class="justify-center" @click="skipAuthPrompt">
              Продолжить без регистрации
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
