<script setup lang="ts">
/**
 * Рейсы маршрута — объединение двух legacy-страниц: поиска билетов
 * (`index.php`, только рейсы, доступные к интернет-продаже) и расписания
 * до конкретной остановки (`raspisanie-avtobusov?from=&to=&date=`, все рейсы
 * со статусами). Разница теперь — переключатель `?all=1`.
 *
 * Без `date` — режим «ближайшие» (legacy blij=1): рейсы всех дат подряд.
 */
import type { Trip } from '~/composables/useApi'

const route = useRoute()
const router = useRouter()
const api = useApi()

/*
 * Продажу билетов на сайте выключают из АИС (раздел «Настройки»). Статусы
 * рейсов при этом остаются как есть — расписание и свободные места
 * по-прежнему полезны, — а вот кнопка покупки уступает место объявлению:
 * иначе покупатель дошёл бы до формы и получил отказ от сервера.
 */
const { data: siteSettings } = await useSiteSettings()
const salesEnabled = computed(() => siteSettings.value.sales_enabled)

const awtcod = computed(() => Number(route.query.awtcod))
/*
 * Поиск от ПУНКТА ПОСАДКИ (остановка на трассе): станцию выбирает сервер
 * по тарифам — от Дросково до Ливен везёт база Орла, до Орла — база Ливен
 * (docs/BOARDING_POINTS.md). У каждого рейса в ответе своя `awtcod`,
 * поэтому дальше покупка берёт её у рейса, а не из адреса страницы.
 */
const fromPoint = computed(() => String(route.query.from_point ?? ''))
const toName = computed(() => String(route.query.to ?? ''))
/** Пусто — «ближайшие рейсы» по всем датам. */
const date = computed(() => String(route.query.date ?? ''))
const showAll = computed(() => queryFlag(route.query.all))

const { data, status, error } = await useAsyncData(
  () => `trips-${fromPoint.value || awtcod.value}-${toName.value}-${date.value}-${showAll.value}`,
  () => api<{ success: boolean, awtname: string, data: Trip[], warnings?: string[] }>('/trips', {
    query: {
      ...(fromPoint.value ? { from_point: fromPoint.value } : { awtcod: awtcod.value }),
      to: toName.value,
      ...(date.value ? { date: date.value } : {}),
      ...(showAll.value ? { all: 1 } : {}),
    },
  }),
  { watch: [awtcod, fromPoint, toName, date, showAll] },
)

/**
 * Станция не ответила, а рейсы других есть: пункт посадки обслуживают
 * несколько баз, и молчание одной не должна скрывать пустая выдача.
 */
const warnings = computed(() => data.value?.warnings ?? [])

/*
 * Название пункта отправления для заголовка. В адресе оно есть не
 * всегда: по прямой ссылке `/trips?awtcod=…&to=…` (а такие собирают
 * руками и оставляют старые ссылки) параметра `from` нет — тогда берём
 * название станции из ответа API, иначе заголовок читался бы как
 * «Рейсы → Ливны АС».
 */
const fromName = computed(() => String(route.query.from ?? '') || data.value?.awtname || '')

const trips = computed(() => {
  const list = data.value?.data ?? []
  // В режиме покупки — как в legacy-поиске: рейсы без свободных мест не
  // показываем. Отменённые прячем и здесь: продавать на них нельзя.
  return showAll.value ? list : list.filter(t => !t.canceled && t.free_places > 0)
})

// Дата считается в поясе автовокзала (siteDateShifted), а не в UTC: после
// полуночи по Москве UTC-дата ещё вчерашняя, и ссылка «Сегодня» уводила
// на прошедший день.
const shiftDate = siteDateShifted

/** Ссылки «Сегодня / Завтра / Послезавтра / Ближайшие» как в legacy. */
const dateLinks = computed(() => [
  { label: 'Сегодня', date: shiftDate(0) },
  { label: 'Завтра', date: shiftDate(1) },
  { label: 'Послезавтра', date: shiftDate(2) },
  { label: 'Ближайшие', date: '' },
])

function linkTo(d: string) {
  return {
    path: '/trips',
    query: {
      ...(fromPoint.value ? { from_point: fromPoint.value } : { awtcod: awtcod.value }),
      from: fromName.value,
      to: toName.value,
      ...(d ? { date: d } : {}),
      ...(showAll.value ? { all: 1 } : {}),
    },
  }
}

function toggleAll(value: boolean) {
  router.replace({
    query: { ...route.query, ...(value ? { all: 1 } : { all: undefined }) },
  })
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'long' })
}

function formatDateTime(d: Date) {
  return d.toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', weekday: 'short', hour: '2-digit', minute: '2-digit',
  })
}

function formatTime(d: Date) {
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

/** Отправление, которое ждёт пассажир: от его остановки, а не рейса. */
function departureTime(trip: Trip) {
  const departure = tripDeparture(trip)

  return departure ? formatTime(departure) : trip.time_start.slice(0, 5)
}

function statusColor(kind: TripStatus['kind']) {
  if (kind === 'cash') return 'success'
  return kind === 'closed' || kind === 'limited' ? 'error' : 'neutral'
}

/*
 * Прибытие считает общий `tripArrival` — от отправления плюс время в
 * пути. Прежний расчёт шёл от `time_end` и добавлял сутки, если прибытие
 * «раньше» отправления: на рейсе Орёл–Симферополь (25 ч 35 мин в пути,
 * 11:15 → time_end 12:50) сутки не добавлялись вовсе, и пассажир видел
 * 18 ноября вместо 19-го.
 */

interface TripStatus {
  /** buy — показываем кнопку покупки */
  kind: 'buy' | 'cash' | 'limited' | 'closed' | 'none'
  text?: string
  note?: string
}

/**
 * Статус рейса — правила legacy raspisanie-avtobusov.php 1:1, в том же
 * порядке: более поздние условия перекрывают более ранние. Единственный
 * источник правды и для кнопки «Купить» (kind === 'buy').
 *
 * `now` передаётся снаружи: он общий для всего списка и обновляется по
 * таймеру, иначе рейс «доступен для покупки» так и висел бы на странице,
 * открытой полчаса назад.
 */
function tripStatus(trip: Trip, now: number): TripStatus {
  // Отправление — от остановки покупателя, если он садится в пути.
  const departure = (tripDeparture(trip) ?? new Date(`${trip.date_start}T${trip.time_start}`)).getTime()
  // legacy: продажа через сайт закрывается за 2000 секунд до отправления
  const openForSale = trip.available && !trip.transit && departure > now + 2000 * 1000
  const places = placesNote(trip)
  let status: TripStatus = { kind: 'none' }

  if (openForSale && trip.free_places > 0) {
    status = { kind: 'buy', note: places }

    if (trip.min_kilom) {
      status = {
        kind: 'limited',
        text: 'Продажа ограничена',
        note: `билет можно купить не ближе чем до: ${trip.min_kilom}. ${places}`,
      }
    }
    if (trip.max_kilom) {
      status = {
        kind: 'limited',
        text: 'Продажа ограничена',
        note: `билет можно купить не дальше чем до: ${trip.max_kilom}. ${places}`,
      }
    }
    if (!trip.internet_sale_available) {
      status = { kind: 'limited', text: 'Продажа через интернет запрещена!' }
    }
  }
  else {
    if (trip.available && !trip.transit && departure <= now + 2000 * 1000 && trip.free_places > 0) {
      status = { kind: 'cash', text: 'Продажа в кассах', note: places }
    }
    if (trip.available && !trip.transit && trip.free_places === 0) {
      status = { kind: 'closed', text: 'Нет свободных мест' }
    }
  }

  if (trip.transit) {
    status = { kind: 'none', text: 'Транзитный', note: 'продажа не открыта' }
  }
  if (trip.transit && trip.available) {
    status = { kind: 'cash', text: 'Транзитный', note: 'продажа в кассах' }
  }
  if (!trip.transit && trip.canceled) {
    status = { kind: 'closed', text: 'Отменён' }
  }
  if (trip.disrupt) {
    status = { kind: 'closed', text: 'Сорван' }
  }
  if (trip.gone) {
    status = { kind: 'none', text: 'Ушёл' }
  }

  return status
}

/**
 * Подпись о местах.
 *
 * У посадки на остановке точного числа НЕТ: счётчик рейса не знает о
 * билетах, проданных от других остановок, поэтому сервер отдаёт
 * гарантированную нижнюю границу (`free_places_min`) — её и показываем
 * как «не менее». Ноль — не повод скрывать кнопку: точную картину даёт
 * выбор места, и отказ (если мест правда нет) покупатель увидит там
 * (docs/BOARDING_POINTS.md).
 */
function placesNote(trip: Trip): string {
  if (!trip.from_station) {
    return `Осталось: ${trip.free_places} мест`
  }

  const min = trip.free_places_min ?? 0

  return min > 0
    ? `Свободно не менее ${min} мест`
    : 'Мест может не быть — уточните при выборе места'
}

/**
 * Рейс передаётся странице покупки адресом, а не состоянием приложения:
 * такую ссылку можно отправить или опубликовать. Дата берётся у самого
 * рейса — в режиме «ближайшие» её в адресе списка нет.
 *
 * `awtcod` берём У РЕЙСА: при поиске от пункта посадки рейсы приходят из
 * разных станционных баз, и в адресе страницы станции нет вовсе.
 */
function buy(trip: Trip) {
  router.push({
    path: '/buy',
    query: {
      awtcod: trip.awtcod || awtcod.value,
      to: toName.value,
      date: trip.date_start,
      napcod: trip.napcod,
      raicecod: trip.raicecod,
      ...(fromPoint.value ? { from_point: fromPoint.value, from: fromName.value } : {}),
    },
  })
}

/**
 * Текущее время для расчёта статусов. На сервере и на клиенте оно разное,
 * поэтому первый рендер клиента повторяет серверный (иначе гидрация ругается),
 * а дальше время идёт по минутному таймеру.
 */
const now = useState('trips-now', () => Date.now())
onMounted(() => {
  now.value = Date.now()
  const timer = setInterval(() => (now.value = Date.now()), 60_000)
  onUnmounted(() => clearInterval(timer))
})

const rows = computed(() => trips.value.map(trip => ({
  trip,
  status: tripStatus(trip, now.value),
  arrival: tripArrival(trip),
})))

/**
 * Тариф рейса (в legacy — «Детальная информация» в таблице расписания).
 * Дату передаём, чтобы со страницы тарифа можно было вернуться к рейсам
 * до выбранной остановки на тот же день.
 */
function tariffLink(trip: Trip) {
  return {
    path: '/tarif',
    query: {
      from: trip.awtcod || awtcod.value,
      napcod: trip.napcod,
      raicecod: trip.raicecod,
      date: trip.date_start,
      // Тариф от пункта посадки: цены от остановки свои, и расстояния в
      // таблице считаются от неё (docs/BOARDING_POINTS.md).
      ...(fromPoint.value ? { from_point: fromPoint.value } : {}),
    },
  }
}

useSeoMeta({
  title: () => `Рейсы ${fromName.value} — ${toName.value}`,
  description: () => `Расписание и статусы рейсов от ${fromName.value} до ${toName.value}, покупка билетов онлайн.`,
})
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <BackButton />

    <h1 class="text-xl md:text-2xl font-light">
      Рейсы {{ fromName }} → {{ toName }}
      <span v-if="date">на {{ formatDate(date) }}</span>
      <span v-else>— ближайшие</span>
    </h1>

    <!-- Предупреждения об отменах и изменениях — блок из АИС -->
    <SiteBlock name="trips_top" />

    <!--
      Пункт посадки: билет оформляет автостанция, которая ведёт рейс, а
      сесть нужно на остановке. Кассы там нет — это стоит сказать прямо.
    -->
    <UAlert
      v-if="fromPoint && trips.length"
      color="info"
      variant="subtle"
      icon="i-lucide-map-pin"
      :title="`Посадка на остановке «${fromName}»`"
      description="Время отправления указано для этой остановки. Билет оформляется автостанцией, которая ведёт рейс; на остановке кассы нет — покажите водителю электронный билет."
    />

    <!-- Одна из станций пункта посадки не ответила — рейсы остальных показаны. -->
    <UAlert
      v-for="w in warnings"
      :key="w"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="w"
    />

    <UAlert
      v-if="!salesEnabled"
      color="warning"
      variant="subtle"
      icon="i-lucide-ticket-x"
      title="Продажа билетов на сайте временно приостановлена"
      description="Билеты продаются в кассах автовокзалов. Расписание и свободные места на этой странице актуальны."
    />

    <!-- Шаги покупки -->
    <div class="flex gap-2 text-sm text-muted">
      <UBadge color="error" variant="solid">1. Рейсы</UBadge>
      <UBadge color="neutral" variant="soft">2. Пассажиры</UBadge>
      <UBadge color="neutral" variant="soft">3. Оплата</UBadge>
      <UBadge color="neutral" variant="soft">4. Готово</UBadge>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted">Перейти к рейсам на:</span>
        <UButton
          v-for="l in dateLinks"
          :key="l.label"
          :to="linkTo(l.date)"
          size="xs"
          variant="soft"
          :color="(l.date || '') === date ? 'error' : 'neutral'"
        >
          {{ l.label }}
        </UButton>
      </div>

      <!-- Объединяет поиск билетов и legacy-расписание до остановки -->
      <USwitch
        :model-value="showAll"
        label="Показывать ушедшие и недоступные для онлайн покупки билетов рейсы"
        @update:model-value="toggleAll"
      />
    </div>

    <UAlert
      v-if="showAll"
      color="info"
      variant="subtle"
      icon="i-lucide-info"
      title="Показаны все рейсы маршрута — вместе со статусами"
      description="Транзитные рейсы, рейсы без свободных мест, отменённые и сорванные билеты через сайт не продаются. Купить их можно в кассах автовокзала."
    />

    <div v-if="status === 'pending'" class="space-y-3">
      <USkeleton v-for="i in 3" :key="i" class="h-28 w-full" />
    </div>

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      title="Не удалось получить информацию о рейсах"
      :description="String(error?.data?.error ?? error?.message ?? '')"
    />

    <UAlert
      v-else-if="!trips.length"
      color="warning"
      variant="subtle"
      title="Рейсов не найдено"
      :description="showAll
        ? 'На выбранную дату рейсов по этому маршруту нет.'
        : 'На выбранную дату рейсы отсутствуют, либо продажа на них через сайт закрыта.'"
    >
      <!-- Пустой список чаще всего лечится одним из двух: показать рейсы,
           которые уже ушли или не продаются онлайн, либо уйти с даты
           на ближайшие рейсы маршрута. -->
      <template #actions>
        <UButton v-if="!showAll" color="warning" size="sm" @click="toggleAll(true)">
          Показать ушедшие и недоступные для онлайн покупки билетов рейсы
        </UButton>
        <UButton v-if="date" color="neutral" variant="outline" size="sm" :to="linkTo('')">
          Ближайшие рейсы
        </UButton>
      </template>
    </UAlert>

    <div v-else class="space-y-4">
      <UCard
        v-for="{ trip, status: tripState, arrival: arrivesAt } in rows"
        :key="`${trip.napcod}-${trip.raicecod}-${trip.date_start}-${trip.time_start}`"
      >
        <div class="flex flex-col md:flex-row md:items-center gap-4">
          <div class="flex items-center gap-3 text-2xl font-semibold shrink-0">
            <UIcon name="i-lucide-bus" class="text-error" />
            <!--
              Время отправления — ОТ ОСТАНОВКИ покупателя: у рейса из
              Орла в 07:00 посадка в Дросково в 09:15, и показывать здесь
              время рейса значило бы отправить пассажира на два часа
              раньше (docs/BOARDING_POINTS.md).
            -->
            <span>{{ departureTime(trip) }}</span>
            <UIcon name="i-lucide-arrow-right" class="text-muted size-5" />
            <!--
              Время прибытия само по себе врёт на сутки у рейсов дольше
              суток пути, поэтому рядом стоит пометка «+N сут.», а полная
              дата — строкой ниже. Прибытие неизвестно (у рейса нет
              времени в пути до этой остановки) — ставим прочерк:
              `time_end` у таких рейсов равен времени ОТПРАВЛЕНИЯ, и
              показать его значило бы «автобус приезжает, не выехав».
            -->
            <span v-if="arrivesAt">
              {{ formatTime(arrivesAt) }}
              <sup v-if="tripArrivalDayOffset(trip) > 0" class="text-sm text-muted font-normal"
                >+{{ tripArrivalDayOffset(trip) }} сут.</sup>
            </span>
            <span v-else class="text-muted">—</span>
          </div>

          <div class="flex-1 min-w-0">
            <!-- Название маршрута не обрезаем: по нему пассажир и узнаёт рейс. -->
            <div class="font-medium">Рейс: {{ trip.trip_name }}–{{ trip.direction_name }}</div>
            <div class="text-sm text-muted">
              Отправление {{ formatDate(trip.date_start) }}<template v-if="arrivesAt">,
                прибытие {{ formatDateTime(arrivesAt) }}</template><template v-else>,
                время прибытия уточняйте на станции</template>
            </div>
            <div class="text-sm text-muted">
              <!-- Марку автобуса не показываем: она в базе станции
                   зачастую не совпадает с тем, что реально выйдет на рейс,
                   и только вводит пассажира в заблуждение. -->
              Перевозчик: {{ trip.carrier }}
            </div>
            <div class="text-sm text-muted">
              Полный: <b class="text-highlighted">{{ trip.price_full.toFixed(2) }} ₽</b>
              · Детский: <b class="text-highlighted">{{ trip.price_child.toFixed(2) }} ₽</b>
              · Багаж: <b class="text-highlighted">{{ trip.price_baggage.toFixed(2) }} ₽</b>
            </div>
            <NuxtLink :to="tariffLink(trip)" class="text-sm text-primary hover:underline">
              Детальная информация
            </NuxtLink>
          </div>

          <div class="flex items-center gap-4 shrink-0">
            <div v-if="tripState.kind === 'buy' && salesEnabled" class="text-right">
              <UButton color="error" @click="buy(trip)">Купить</UButton>
              <div class="text-xs text-muted mt-1">{{ tripState.note }}</div>
            </div>

            <!-- Продажа выключена из АИС: рейс доступен, но не у нас -->
            <div v-else-if="tripState.kind === 'buy'" class="text-right max-w-56">
              <UBadge color="warning" variant="soft">Продажа в кассах</UBadge>
              <div class="text-xs text-muted mt-1">{{ tripState.note }}</div>
            </div>

            <div v-else class="text-right max-w-56">
              <UBadge :color="statusColor(tripState.kind)" variant="soft">
                {{ tripState.text ?? 'Продажа закрыта' }}
              </UBadge>
              <div v-if="tripState.note" class="text-xs text-muted mt-1">
                {{ tripState.note }}
              </div>
            </div>
          </div>
        </div>
      </UCard>
    </div>
  </UContainer>
</template>
