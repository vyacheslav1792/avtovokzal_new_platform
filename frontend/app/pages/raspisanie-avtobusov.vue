<script setup lang="ts">
/**
 * Расписание автобусов (перенос legacy /raspisanie-avtobusov).
 *
 * В legacy было две разные страницы: список станций и — по ?from= —
 * готовая HTML-таблица из файла. Здесь одна страница: пункт отправления
 * переключается прямо на ней (адрес меняется на ?from=…), данные приходят
 * из БД в JSON.
 */
const route = useRoute()
const router = useRouter()
const api = useApi()

interface ScheduleStation {
  awtcod: number
  name: string
  slug: string
  routes_count: number
  generated_at: string | null
}

interface Departure {
  time: string
  raicecod: number
  nomkat: number
  charter: boolean
}

interface ScheduleRoute {
  route_name: string
  trip_name: string
  direction_name: string
  napcod: number
  route_number: string | null
  route_full_name: string | null
  carrier: string | null
  schedule: string
  days: string[]
  interval: number | null
  fixed_dates: string[]
  next_dates: string[]
  departures: Departure[]
}

interface ScheduleData {
  awtcod: number
  name: string
  slug: string
  routes: ScheduleRoute[]
  generated_at: string | null
}

const from = computed(() => String(route.query.from ?? ''))

const { data: stationsResp } = await useAsyncData('schedule-stations', () =>
  api<{ success: boolean, data: ScheduleStation[] }>('/schedule'))

const stations = computed(() => stationsResp.value?.data ?? [])

const { data: scheduleResp, error } = await useAsyncData(
  'schedule',
  () => from.value
    ? api<{ success: boolean, data: ScheduleData }>(`/schedule/${encodeURIComponent(from.value)}`)
    : Promise.resolve(null),
  { watch: [from] },
)

const schedule = computed(() => scheduleResp.value?.data ?? null)

/**
 * Старые адреса legacy: /raspisanie-avtobusov?from=&to=&date=5+августа+2026
 * (а также date=tomorrow|aftertomorrow и blij=1 — «ближайшие»). Там рейсы
 * до конкретной остановки показывались прямо на этой странице; теперь это
 * /trips с включённым показом рейсов без интернет-продажи.
 */
const MONTHS = [
  'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
  'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
]

function shiftDate(days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

/** Дата legacy-ссылки в Y-m-d; пусто — «ближайшие рейсы». */
function legacyDate(raw: string): string {
  if (raw === 'tomorrow') return shiftDate(1)
  if (raw === 'aftertomorrow') return shiftDate(2)
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw

  // «5 августа 2026» — формат ссылок legacy-формы (функция datenorm).
  const m = raw.match(/^(\d{1,2})\s+([а-яё]+)\s+(\d{4})$/i)
  const month = m ? MONTHS.indexOf(m[2]!.toLowerCase()) : -1
  if (m && month >= 0) {
    return `${m[3]}-${String(month + 1).padStart(2, '0')}-${m[1]!.padStart(2, '0')}`
  }

  return ''
}

const legacyTo = computed(() => String(route.query.to ?? ''))

if (legacyTo.value && from.value) {
  // Код станции берём из списка станций продаж, а не из расписания:
  // расписание для станции может быть ещё не опубликовано.
  const { data: salesStations } = await useAsyncData('sales-stations', () =>
    api<{ success: boolean, data: { awtcod: number, name: string }[] }>('/stations'))

  const station = (salesStations.value?.data ?? []).find(s => s.name === from.value)

  if (station) {
    const nearest = queryFlag(route.query.blij)
    // Без даты (и с неразобранной датой) legacy показывал рейсы на сегодня.
    const date = nearest ? '' : (legacyDate(String(route.query.date ?? '')) || shiftDate(0))

    await navigateTo({
      path: '/trips',
      query: {
        awtcod: station.awtcod,
        from: station.name,
        to: legacyTo.value,
        ...(date ? { date } : {}),
        all: 1,
      },
    }, { redirectCode: 301 })
  }
}

useSeoMeta({
  title: () => from.value
    ? `Расписание автобусов ${from.value}`
    : 'Расписание автобусов автовокзалов Орловской области',
  description: () => from.value
    ? `Регулярное расписание отправлений автобусов от ${from.value}: маршруты, дни следования и время отправления.`
    : 'Расписание автобусов автовокзалов и кассовых пунктов Орловской области.',
})

const stationOptions = computed(() =>
  stations.value.map(s => ({ label: s.name, value: s.name })))

/** Смена пункта отправления меняет адрес страницы — ссылкой можно делиться. */
function selectStation(name: string | undefined) {
  router.push(name ? { query: { from: name } } : { query: {} })
}

/*
 * Пункт прибытия и дата (legacy-форма на странице расписания). Выбор ведёт
 * на /trips — там рейсы до этой остановки со статусами, как в legacy.
 */
const destinationsResp = await useAsyncData(
  'schedule-destinations',
  () => schedule.value
    ? api<{ success: boolean, data: { name: string }[] }>(`/stations/${schedule.value.awtcod}/destinations`)
    : Promise.resolve(null),
  // Список нужен только для формы — на SSR его не тянем.
  { server: false, watch: [schedule] },
)

const destinations = computed(() =>
  (destinationsResp.data.value?.data ?? []).map(d => ({ label: d.name, value: d.name })))

// Индикатор загрузки включаем только после гидрации: на сервере запроса нет,
// и «крутилка» с первого рендера клиента разошлась бы с серверной разметкой.
const mounted = ref(false)
onMounted(() => (mounted.value = true))
const destinationsLoading = computed(() =>
  mounted.value && destinationsResp.status.value === 'pending')

const today = new Date().toISOString().slice(0, 10)
const to = ref<string | null>(null)
const tripDate = ref(today)

function showTrips(nearest = false) {
  if (!schedule.value || !to.value) return
  router.push({
    path: '/trips',
    query: {
      awtcod: schedule.value.awtcod,
      from: schedule.value.name,
      to: to.value,
      ...(nearest || !tripDate.value ? {} : { date: tripDate.value }),
      // На странице расписания legacy показывал ВСЕ рейсы, а не только те,
      // что продаются онлайн, — сохраняем это поведение.
      all: 1,
    },
  })
}

const routes = computed(() => schedule.value?.routes ?? [])

/**
 * Ссылка на тариф рейса (legacy: клик по времени отправления вёл на /tarif).
 * Тарифную категорию не передаём — её выбирает сервер: у рейса их бывает
 * две (действующая и новая с даты смены).
 */
function tariffLink(r: ScheduleRoute, d: Departure) {
  return {
    path: '/tarif',
    query: {
      from: stations.value.find(s => s.name === schedule.value?.name)?.awtcod,
      napcod: r.napcod,
      raicecod: d.raicecod,
    },
  }
}
</script>

<template>
  <UContainer class="py-8 max-w-5xl">
    <h1 class="text-3xl font-light mb-2">
      Расписание автобусов<span v-if="schedule"> · {{ schedule.name }}</span>
    </h1>

    <p v-if="!from" class="text-muted mb-6">
      Выберите пункт отправления — покажем регулярное расписание маршрутов.
    </p>

    <!-- Пункт отправления переключается прямо на странице расписания -->
    <UCard v-if="from" class="mb-6 no-print">
      <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-[1fr_1fr_180px_auto] items-end">
        <UFormField label="Пункт отправления">
          <USelectMenu
            :model-value="from"
            :items="stationOptions"
            value-key="value"
            placeholder="Выберите автовокзал или кассовый пункт"
            searchable
            class="w-full"
            @update:model-value="selectStation"
          />
        </UFormField>

        <!-- legacy: выбор пункта прибытия вёл к расписанию до этой остановки -->
        <UFormField label="Пункт прибытия">
          <USelectMenu
            v-model="to"
            :items="destinations"
            value-key="value"
            :loading="destinationsLoading"
            placeholder="Выберите пункт прибытия"
            searchable
            class="w-full"
          />
        </UFormField>

        <UFormField label="Дата поездки">
          <UInput v-model="tripDate" type="date" :min="today" class="w-full" />
        </UFormField>

        <div class="flex gap-2">
          <UButton color="error" :disabled="!to" @click="showTrips()">Найти</UButton>
          <UButton
            color="neutral"
            variant="subtle"
            :disabled="!to"
            title="Рейсы всех ближайших дат"
            @click="showTrips(true)"
          >
            Ближайшие
          </UButton>
        </div>
      </div>
    </UCard>

    <!-- Список станций (как в legacy — но плитками) -->
    <div v-if="!from" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <NuxtLink
        v-for="s in stations"
        :key="s.awtcod"
        :to="{ query: { from: s.name } }"
        class="border border-default rounded-lg p-4 font-medium hover:border-primary transition-colors"
      >
        {{ s.name }}
      </NuxtLink>
    </div>

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      title="Расписание недоступно"
      description="Для этой станции расписание пока не опубликовано. Попробуйте выбрать другой пункт отправления."
    />

    <template v-else-if="schedule">
      <div class="flex justify-end mb-3 no-print">
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-printer" @click="print()">
          Версия для печати
        </UButton>
      </div>

      <p class="text-sm text-muted mb-4">
        Актуально на {{ schedule.generated_at }}. Указано для справки: расписание отправлений
        на конкретный день может отличаться от регулярного. Наличие мест, стоимость и статус
        рейса смотрите в поиске билетов на нужную дату.
      </p>

      <div v-if="!routes.length" class="py-10 text-center text-muted">
        По заданным условиям маршрутов не нашлось.
      </div>

      <div v-else class="space-y-3">
        <UCard v-for="r in routes" :key="r.route_name + r.schedule">
          <div class="flex flex-wrap gap-4">
            <div class="min-w-56 flex-1">
              <div class="font-medium text-lg">{{ r.route_name }}</div>
              <div class="text-sm text-muted">
                <span v-if="r.route_number">Маршрут № {{ r.route_number }}</span>
                <span v-if="r.carrier"> · {{ r.carrier }}</span>
              </div>
              <div class="text-sm mt-1">
                <UBadge color="neutral" variant="subtle">{{ r.schedule }}</UBadge>
                <span v-if="r.next_dates.length" class="text-muted ml-2">
                  ближайшие: {{ r.next_dates.join(', ') }}
                </span>
              </div>
            </div>

            <div class="flex-1 min-w-56">
              <div class="text-sm text-muted mb-1">Время отправления (местное)</div>
              <div class="flex flex-wrap gap-2">
                <!-- Клик по времени открывает тариф рейса (как в legacy) -->
                <UButton
                  v-for="d in r.departures"
                  :key="d.time + d.raicecod"
                  :to="tariffLink(r, d)"
                  color="primary"
                  variant="soft"
                  size="md"
                  :title="`Стоимость проезда по рейсу, отправление ${d.time}`"
                >
                  {{ d.time }}<span v-if="d.charter" class="ml-1 text-xs">(заказной)</span>
                </UButton>
              </div>
            </div>

          </div>
        </UCard>
      </div>
    </template>
  </UContainer>
</template>

<style>
/* Версия для печати (в legacy — отдельная страница for_print.php):
   печатаем сам список маршрутов, без шапки сайта и фильтров. */
@media print {
  header,
  footer,
  .no-print {
    display: none !important;
  }
}
</style>
