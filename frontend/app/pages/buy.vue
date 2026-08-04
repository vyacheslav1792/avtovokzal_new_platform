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
const tariffOptions = [
  { label: 'Полный', value: 'adult' },
  { label: 'Детский (до 12 лет)', value: 'child' },
]
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
const agree = ref(false)
const submitting = ref(false)

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
}

/** Подсказка формата серии/номера — по правилам базы автостанции. */
function docHint(docType: number): string {
  switch (docType) {
    case 21: return '4 цифры серии и 6 цифр номера, например 4444 555555'
    case 22: return '2 цифры серии и 7 цифр номера, например 75 1234567'
    case 3: return 'например IV-АБ 123456'
    case 15: return 'например АБ 1234567'
    case 26: return 'например XV 1234567'
    default: return 'серия и номер документа'
  }
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

  submitting.value = true
  try {
    const resp = await api<{ success: boolean, data: { payment_url: string, order_id: string } }>(
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
    // Уходим на платёжный шлюз Сбербанка.
    window.location.href = resp.data.payment_url
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
      <UBadge color="error" variant="solid">2. Пассажиры</UBadge>
      <UBadge color="neutral" variant="soft">3. Оплата</UBadge>
      <UBadge color="neutral" variant="soft">4. Готово</UBadge>
    </div>

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
          <USelect v-model="p.tariff" :items="tariffOptions" value-key="value" class="w-full" />
        </UFormField>
        <UFormField label="Багаж / ручная кладь">
          <USelect v-model="p.baggage" :items="baggageOptions" value-key="value" class="w-full" />
        </UFormField>
      </div>

      <p class="mt-3 text-sm text-muted">
        Пассажир имеет право перевезти с собой 2х детей до 12 лет по детскому тарифу.
        Для ребёнка без сопровождения взрослого необходимо приобрести билет по полному тарифу.
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
          :error="fieldError(`passengers.${i}.birth_date`)"
          :data-error-field="fieldError(`passengers.${i}.birth_date`) ? '' : undefined"
        >
          <UInput
            v-model="p.birth_date" type="date" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.birth_date`)"
            @input="clearError(`passengers.${i}.birth_date`)"
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
          <USelect
            v-model="p.doc_type" :items="DOC_TYPES" value-key="value" class="w-full"
            @update:model-value="clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>
        <UFormField
          label="Серия и номер документа" required :hint="docHint(p.doc_type)"
          class="md:col-span-2"
          :error="fieldError(`passengers.${i}.doc_number`)"
          :data-error-field="fieldError(`passengers.${i}.doc_number`) ? '' : undefined"
        >
          <UInput
            v-model="p.doc_number" placeholder="4444 555555" class="w-full"
            :highlight="!!fieldError(`passengers.${i}.doc_number`)"
            @input="clearError(`passengers.${i}.doc_number`)"
          />
        </UFormField>
      </div>

      <template #footer>
        <div class="text-right text-sm">
          Стоимость: <b class="text-lg">{{ priceFor(p).toFixed(2) }} ₽</b>
        </div>
      </template>
    </UCard>

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
          <UInput
            v-model="phone" placeholder="+7 910 000-00-00" class="w-full"
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
        <div class="flex items-center justify-between">
          <div class="text-sm text-muted">
            Оплачивая заказ, вы соглашаетесь с условиями сервиса.<br>
            Если письма от сайта не приходят — проверьте папку «Спам».
          </div>
          <div class="flex items-center gap-4">
            <div>Всего: <b class="text-2xl">{{ total.toFixed(2) }} ₽</b></div>
            <UButton size="xl" color="error" :loading="submitting" @click="submit">
              Перейти к оплате
            </UButton>
          </div>
        </div>
      </template>
    </UCard>
  </UContainer>
</template>
