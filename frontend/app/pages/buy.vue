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
    query: { awtcod: awtcod.value, to: toName.value, date: date.value, all: 1 },
  }),
  { ...noCache, watch: [awtcod, toName, date] },
)

const trip = computed(() =>
  (tripsResp.value?.data ?? []).find(t => t.napcod === napcod.value && t.raicecod === raicecod.value) ?? null)

const fromName = computed(() => tripsResp.value?.awtname ?? '')

/** Причина, по которой рейс нельзя купить на сайте (иначе — пусто). */
const unavailableReason = computed(() => {
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

  const departure = new Date(`${t.date_start}T${t.time_start}`).getTime()
  if (departure <= Date.now() + 2000 * 1000) {
    return 'Продажа через сайт закрыта: до отправления осталось меньше получаса. Билет можно купить в кассе автовокзала.'
  }

  return ''
})

/** Назад к рейсам этого маршрута на ту же дату. */
const tripsLink = computed(() => ({
  path: '/trips',
  query: { awtcod: awtcod.value, from: fromName.value, to: toName.value, date: date.value, all: 1 },
}))

const { data: placesResp } = await useAsyncData(
  'buy-places',
  () => trip.value
    ? api<{ success: boolean, data: { free: number[], taken: number[] } }>('/trips/places', {
      query: {
        awtcod: awtcod.value,
        date: trip.value.date_start,
        napcod: trip.value.napcod,
        raicecod: trip.value.raicecod,
      },
    })
    : Promise.resolve(null),
  { ...noCache, watch: [trip] },
)

// Справочник гражданств меняется раз в годы — его кэш оставляем.
const { data: citizenshipsResp } = await useAsyncData('citizenships', () =>
  api<{ success: boolean, data: { code: number, name: string }[] }>('/citizenships'))

const freePlaces = computed(() => placesResp.value?.data.free ?? [])
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

/** Детский тариф скрываем, когда взять его уже нельзя. */
function tariffOptions(passenger: PassengerForm) {
  const options = [{ label: 'Полный', value: 'adult' }]

  if (passenger.tariff === 'child' || childLimitFor(passenger) > 0) {
    options.push({ label: 'Детский (до 12 лет)', value: 'child' })
  }

  return options
}

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
    baggage: 1,
    // Авторизованному предлагаем сохранить пассажира в профиль; гостю
    // галочка не показывается, а backend её игнорирует — сохранять некуда.
    save_to_profile: true,
    saved_id: null,
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

watch(user, (u) => {
  if (u) {
    if (u.email) email.value = u.email
    if (!phone.value) phone.value = formatPhone(u.phone)
  }
})

/*
 * Гостю предлагаем войти или завести кабинет — с ним не придётся вводить
 * почту заново, а билеты будут под рукой. Шаг **необязательный**: покупка
 * без регистрации остаётся как была, и отказ мы запоминаем на вкладку
 * (sessionStorage), чтобы окно не выскакивало на каждом рейсе.
 */
const { data: providers } = await useAuthProviders()
const authPromptOpen = ref(false)
const AUTH_PROMPT_SKIPPED = 'buy-auth-prompt-skipped'

onMounted(() => {
  // Рейс не найден или продажа закрыта — предлагать нечего.
  if (!trip.value || unavailableReason.value) return
  if (isLoggedIn.value || sessionStorage.getItem(AUTH_PROMPT_SKIPPED)) return

  authPromptOpen.value = true
})

// Вошёл в соседней вкладке или через VK — предложение больше не нужно.
watch(isLoggedIn, (value) => {
  if (value) authPromptOpen.value = false
})

function skipAuthPrompt() {
  sessionStorage.setItem(AUTH_PROMPT_SKIPPED, '1')
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

const savedOptions = computed(() => savedPassengers.value.map(p => ({
  label: `${p.full_name} · ${p.document}`,
  value: p.id,
})))

/** Подставить сохранённого пассажира в форму. */
function fillFromSaved(index: number, id: number) {
  const saved = savedPassengers.value.find(p => p.id === id)
  const passenger = passengers.value[index]

  if (!saved || !passenger) return

  Object.assign(passenger, {
    last_name: saved.last_name,
    first_name: saved.first_name,
    middle_name: saved.middle_name ?? '',
    birth_date: saved.birth_date,
    gender: saved.gender,
    doc_type: saved.doc_type,
    doc_number: saved.document,
    citizenship: saved.citizenship,
    // Он уже в профиле — сохранять повторно нечего.
    save_to_profile: false,
    saved_id: saved.id,
  })

  for (const field of ['last_name', 'first_name', 'middle_name', 'birth_date', 'doc_number', 'citizenship']) {
    clearError(`passengers.${index}.${field}`)
  }
}

/** Данные правились вручную — это уже другой пассажир, не запись профиля. */
function detachFromSaved(passenger: PassengerForm) {
  passenger.saved_id = null
}

const agree = ref(false)
const submitting = ref(false)

/*
 * Заказ создан, места зарезервированы — остался уход на шлюз. Пока окно
 * открыто (или закрыто, но заказ жив), платить можно по этой же ссылке:
 * второй раз оформлять заказ не нужно, иначе будут две брони на одни места.
 */
const payment = ref<{ url: string, payUntil: string } | null>(null)
const paymentOpen = ref(false)

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

function goToGateway() {
  if (payment.value) window.location.href = payment.value.url
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
 * Маска серии и номера документа. Пока только паспорт РФ (21): 4 цифры серии
 * и 6 цифр номера. У остальных документов формат разный (римские цифры,
 * кириллица, произвольная длина) — там маска только мешала бы, формат
 * проверяет backend по правилам KS_CONTROL_DATA_PAS.
 */
function docMask(docType: number): string | null {
  return docType === 21 ? '#### ######' : null
}

/**
 * Подсказка формата серии/номера — по правилам вендорной процедуры
 * `KS_CONTROL_DATA_PAS` (их же проверяет backend). Формат задан не у всех
 * документов: у остальных станция серию с номером не проверяет, и выдумывать
 * подсказку нельзя — покупатель введёт по ней то, чего в документе нет.
 */
const DOC_FORMATS: Record<number, { hint: string, placeholder: string }> = {
  21: { hint: '4 цифры серии и 6 цифр номера', placeholder: '4444 555555' },
  22: { hint: '2 цифры серии и 7 цифр номера', placeholder: '75 1234567' },
  3: { hint: 'Римские цифры, две русские буквы и 6 цифр номера', placeholder: 'IV-АБ 123456' },
  15: { hint: 'Две русские буквы и 7 цифр номера', placeholder: 'АБ 1234567' },
  26: { hint: 'Две римские цифры и 7 цифр номера', placeholder: 'XV 1234567' },
}

function docHint(docType: number): string {
  return DOC_FORMATS[docType]?.hint ?? ''
}

/** Пусто — формат документа станцией не задан, подсказывать нечего. */
function docPlaceholder(docType: number): string {
  return DOC_FORMATS[docType]?.placeholder ?? ''
}

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
          email: email.value,
          phone: phone.value,
          agree_pd: true,
          passengers: passengers.value,
        },
      },
    )
    // На шлюз уходим НЕ молча: в legacy для этого была отдельная страница
    // `/pay` с предупреждением, без неё покупатель не понимает, что вернуться
    // на сайт после оплаты обязательно. Страница лишняя — предупреждение
    // показываем окном прямо здесь.
    rememberPendingOrder(resp.data.order_id)
    payment.value = { url: resp.data.payment_url, payUntil: resp.data.pay_until }
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
      :description="`Места держим ещё ${holdLeft}. Билеты будут оформлены после оплаты.`"
      :actions="[{ label: 'Перейти к оплате', color: 'error', onClick: () => (paymentOpen = true) }]"
    />

    <UCard>
      <template #header>
        <div class="font-medium">
          Рейс: {{ trip.trip_name }}–{{ trip.direction_name }} · Перевозчик: {{ trip.carrier }}
        </div>
      </template>
      <div class="flex flex-wrap items-center gap-6">
        <div>
          <div class="text-2xl font-semibold">{{ trip.time_start.slice(0, 5) }}</div>
          <div class="text-sm text-muted">{{ formatDate(trip.date_start) }}</div>
          <div class="font-medium">{{ fromName }}</div>
        </div>
        <UIcon name="i-lucide-arrow-right" class="size-6 text-muted" />
        <div>
          <div class="text-2xl font-semibold">{{ trip.time_end?.slice(0, 5) }}</div>
          <div class="text-sm text-muted">{{ formatDate(trip.date_start) }}</div>
          <div class="font-medium">{{ toName }}</div>
        </div>
        <div class="ml-auto text-sm text-muted">
          свободно мест: <b class="text-highlighted">{{ freePlaces.length }}</b>
        </div>
      </div>
    </UCard>

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

      <!-- Пассажиры из профиля: одним выбором подставляем ФИО и документ. -->
      <UFormField
        v-if="isLoggedIn && savedOptions.length"
        label="Выбрать из профиля"
        help="Данные подставятся в поля ниже — их можно поправить"
        class="mb-4"
      >
        <USelectMenu
          :model-value="p.saved_id ?? undefined"
          :items="savedOptions"
          value-key="value"
          placeholder="Новый пассажир"
          class="w-full md:w-2/3"
          @update:model-value="(id: number) => fillFromSaved(i, id)"
        />
      </UFormField>

      <div class="grid gap-4 md:grid-cols-3">
        <UFormField
          label="Место" required
          :error="fieldError(`passengers.${i}.place`)"
          :data-error-field="fieldError(`passengers.${i}.place`) ? '' : undefined"
        >
          <USelect
            v-model="p.place" :items="availablePlaces(p.place)" value-key="value" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.place`)"
            @update:model-value="clearError(`passengers.${i}.place`)"
          />
        </UFormField>
        <UFormField label="Тариф">
          <USelect
            :model-value="p.tariff" :items="tariffOptions(p)" value-key="value" class="w-full"
            @update:model-value="(value: string) => setTariff(p, value)"
          />
        </UFormField>
        <UFormField label="Багаж / ручная кладь">
          <USelect v-model="p.baggage" :items="baggageOptions" value-key="value" class="w-full" />
        </UFormField>
      </div>

      <p v-if="saleRules?.child_requires_adult" class="mt-3 text-sm text-muted">
        {{ saleRules.child_requires_adult_message }}
      </p>

      <div class="grid gap-4 md:grid-cols-3 mt-4">
        <UFormField
          label="Фамилия" required
          :error="fieldError(`passengers.${i}.last_name`)"
          :data-error-field="fieldError(`passengers.${i}.last_name`) ? '' : undefined"
        >
          <UInput
            v-model="p.last_name" placeholder="ИВАНОВ" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.last_name`)"
            @input="clearError(`passengers.${i}.last_name`); detachFromSaved(p)"
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
            @input="clearError(`passengers.${i}.first_name`); detachFromSaved(p)"
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
            @input="clearError(`passengers.${i}.middle_name`); detachFromSaved(p)"
          />
        </UFormField>

        <UFormField
          label="Дата рождения" required
          :error="fieldError(`passengers.${i}.birth_date`)
            || (birthDateInvalid[i] ? 'Проверьте дату рождения — такой даты не существует.' : undefined)"
          :data-error-field="fieldError(`passengers.${i}.birth_date`) ? '' : undefined"
        >
          <DateInput
            v-model="p.birth_date"
            v-model:invalid="birthDateInvalid[i]"
            class="w-full"
            :highlight="!!fieldError(`passengers.${i}.birth_date`) || !!birthDateInvalid[i]"
            @input="clearError(`passengers.${i}.birth_date`); detachFromSaved(p)"
          />
        </UFormField>
        <UFormField label="Пол">
          <USelect v-model="p.gender" :items="genderOptions" value-key="value" class="w-full" />
        </UFormField>
        <UFormField
          label="Гражданство"
          :error="fieldError(`passengers.${i}.citizenship`)"
          :data-error-field="fieldError(`passengers.${i}.citizenship`) ? '' : undefined"
        >
          <USelectMenu
            v-model="p.citizenship" :items="citizenshipOptions" value-key="value"
            class="w-full"
            :highlight="!!fieldError(`passengers.${i}.citizenship`)"
            @update:model-value="clearError(`passengers.${i}.citizenship`); clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>

        <UFormField
          label="Документ"
          :error="fieldError(`passengers.${i}.doc_type`)"
          :data-error-field="fieldError(`passengers.${i}.doc_type`) ? '' : undefined"
        >
          <!-- Название документа не обрезаем многоточием: «Удостоверение
               личности военнослужащего» в одну строку не помещается, и
               покупатель должен видеть, что именно выбрал. -->
          <USelect
            v-model="p.doc_type" :items="DOC_TYPES" value-key="value" class="w-full"
            :ui="{ base: 'items-start', value: 'whitespace-normal text-left pointer-events-none' }"
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
            @input="clearError(`passengers.${i}.doc_number`); detachFromSaved(p)"
          />
        </UFormField>
      </div>

      <!-- Новый пассажир: предлагаем запомнить его в кабинете. Уже
           сохранённого (подставленного из профиля) не переспрашиваем. -->
      <UCheckbox
        v-if="isLoggedIn && !p.saved_id"
        v-model="p.save_to_profile"
        class="mt-4"
        label="Сохранить пассажира в профиль"
        description="В следующий раз данные подставятся из кабинета — вводить их заново не придётся"
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

    <UCard>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField
          label="E-Mail для билетов и чека" required
          :error="fieldError('email')"
          :help="emailFromProfile ? 'Адрес вашего личного кабинета — билеты придут на него' : undefined"
          :data-error-field="fieldError('email') ? '' : undefined"
        >
          <UInput
            v-model="email" type="email" placeholder="you@example.com" class="w-full"
            :disabled="emailFromProfile"
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
            @input="clearError('phone')"
          />
        </UFormField>
      </div>

      <UFormField class="mt-4" :error="fieldError('agree_pd')">
        <UCheckbox
          v-model="agree"
          label="Я даю согласие на обработку моих персональных данных"
        />
      </UFormField>

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
          <div class="order-1 sm:order-2 flex items-center justify-between gap-4">
            <div class="whitespace-nowrap">Всего: <b class="text-2xl">{{ total.toFixed(2) }} ₽</b></div>
            <UButton
              size="xl" color="error" :loading="submitting" class="whitespace-nowrap"
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
      :ui="{ footer: 'justify-end' }"
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
        <UButton color="neutral" variant="ghost" @click="paymentOpen = false">
          Не сейчас
        </UButton>
        <UButton
          color="error"
          icon="i-lucide-credit-card"
          :disabled="!holdLeft"
          @click="goToGateway"
        >
          Перейти к оплате
        </UButton>
      </template>
    </UModal>

    <!-- Предложение войти перед покупкой. Пропустить можно всегда:
         покупка без регистрации остаётся полноценной. -->
    <UModal v-model:open="authPromptOpen" title="Войти в личный кабинет?">
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
        <div class="w-full space-y-3">
          <div class="flex flex-wrap gap-2">
            <UButton color="error" :to="{ path: '/login', query: { redirect: route.fullPath } }">
              Войти
            </UButton>
            <UButton
              color="neutral" variant="outline"
              :to="{ path: '/register', query: { redirect: route.fullPath, ...(email ? { email } : {}) } }"
            >
              Зарегистрироваться
            </UButton>
            <UButton color="neutral" variant="ghost" @click="skipAuthPrompt">
              Продолжить без регистрации
            </UButton>
          </div>
          <VkLoginButton v-if="providers?.vk" :redirect="route.fullPath" />
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
