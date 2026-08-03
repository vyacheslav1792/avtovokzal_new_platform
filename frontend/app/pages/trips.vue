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

const awtcod = computed(() => Number(route.query.awtcod))
const fromName = computed(() => String(route.query.from ?? ''))
const toName = computed(() => String(route.query.to ?? ''))
/** Пусто — «ближайшие рейсы» по всем датам. */
const date = computed(() => String(route.query.date ?? ''))
const showAll = computed(() => queryFlag(route.query.all))

const { data, status, error } = await useAsyncData(
  () => `trips-${awtcod.value}-${toName.value}-${date.value}-${showAll.value}`,
  () => api<{ success: boolean, awtname: string, data: Trip[] }>('/trips', {
    query: {
      awtcod: awtcod.value,
      to: toName.value,
      ...(date.value ? { date: date.value } : {}),
      ...(showAll.value ? { all: 1 } : {}),
    },
  }),
  { watch: [awtcod, toName, date, showAll] },
)

const trips = computed(() => {
  const list = data.value?.data ?? []
  // В режиме покупки — как в legacy-поиске: рейсы без свободных мест не
  // показываем. Отменённые прячем и здесь: продавать на них нельзя.
  return showAll.value ? list : list.filter(t => !t.canceled && t.free_places > 0)
})

function shiftDate(days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

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
      awtcod: awtcod.value,
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

function statusColor(kind: TripStatus['kind']) {
  if (kind === 'cash') return 'success'
  return kind === 'closed' || kind === 'limited' ? 'error' : 'neutral'
}

/** Прибытие: время рейса плюс сутки, если оно меньше времени отправления. */
function arrival(trip: Trip) {
  if (!trip.time_end) return null
  const start = new Date(`${trip.date_start}T${trip.time_start}`)
  const end = new Date(`${trip.date_start}T${trip.time_end}`)
  if (end < start) end.setDate(end.getDate() + 1)
  return end
}

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
  const departure = new Date(`${trip.date_start}T${trip.time_start}`).getTime()
  // legacy: продажа через сайт закрывается за 2000 секунд до отправления
  const openForSale = trip.available && !trip.transit && departure > now + 2000 * 1000
  let status: TripStatus = { kind: 'none' }

  if (openForSale && trip.free_places > 0) {
    status = { kind: 'buy', note: `Осталось: ${trip.free_places} мест` }

    if (trip.min_kilom) {
      status = {
        kind: 'limited',
        text: 'Продажа ограничена',
        note: `билет можно купить не ближе чем до: ${trip.min_kilom}. Осталось: ${trip.free_places} мест`,
      }
    }
    if (trip.max_kilom) {
      status = {
        kind: 'limited',
        text: 'Продажа ограничена',
        note: `билет можно купить не дальше чем до: ${trip.max_kilom}. Осталось: ${trip.free_places} мест`,
      }
    }
    if (!trip.internet_sale_available) {
      status = { kind: 'limited', text: 'Продажа через интернет запрещена!' }
    }
  }
  else {
    if (trip.available && !trip.transit && departure <= now + 2000 * 1000 && trip.free_places > 0) {
      status = { kind: 'cash', text: 'Продажа в кассах', note: `Осталось: ${trip.free_places} мест` }
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
 * Рейс передаётся странице покупки адресом, а не состоянием приложения:
 * такую ссылку можно отправить или опубликовать. Дата берётся у самого
 * рейса — в режиме «ближайшие» её в адресе списка нет.
 */
function buy(trip: Trip) {
  router.push({
    path: '/buy',
    query: {
      awtcod: awtcod.value,
      to: toName.value,
      date: trip.date_start,
      napcod: trip.napcod,
      raicecod: trip.raicecod,
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
  arrival: arrival(trip),
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
      from: awtcod.value,
      napcod: trip.napcod,
      raicecod: trip.raicecod,
      date: trip.date_start,
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
    <div class="flex flex-wrap items-center gap-3">
      <UButton icon="i-lucide-arrow-left" variant="ghost" color="neutral" to="/">Назад</UButton>
      <h1 class="text-xl md:text-2xl font-light">
        Рейсы {{ fromName }} → {{ toName }}
        <span v-if="date">на {{ formatDate(date) }}</span>
        <span v-else>— ближайшие</span>
      </h1>
    </div>

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
            <span>{{ trip.time_start.slice(0, 5) }}</span>
            <UIcon name="i-lucide-arrow-right" class="text-muted size-5" />
            <span>{{ trip.time_end?.slice(0, 5) }}</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="font-medium truncate">Рейс: {{ trip.trip_name }}–{{ trip.direction_name }}</div>
            <div class="text-sm text-muted">
              Отправление {{ formatDate(trip.date_start) }}<template v-if="arrivesAt">,
                прибытие {{ formatDateTime(arrivesAt) }}</template>
            </div>
            <div class="text-sm text-muted truncate">
              Перевозчик: {{ trip.carrier }} · {{ trip.bus }}
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
            <div v-if="tripState.kind === 'buy'" class="text-right">
              <UButton color="error" size="lg" @click="buy(trip)">Купить</UButton>
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
