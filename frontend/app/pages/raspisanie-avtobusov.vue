<script setup lang="ts">
/**
 * Расписание автобусов (перенос legacy /raspisanie-avtobusov).
 *
 * В legacy было две разные страницы: список станций и — по ?from= —
 * готовая HTML-таблица из файла. Здесь одна страница: пункт отправления
 * переключается прямо на ней (адрес меняется на ?from=…), данные приходят
 * из БД в JSON.
 */
import { setResponseStatus } from 'h3'

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
  /** null — расписание заведено вручную, рейса в базе продаж нет. */
  raicecod: number | null
  nomkat: number | null
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
  /**
   * Расписание заведено вручную в АИС — у станции, которая закрыта и
   * билетов не продаёт. Для посетителя разница в одном: купить билет от
   * неё на сайте нельзя, рейсы показаны для справки.
   */
  is_manual?: boolean
}

const from = computed(() => String(route.query.from ?? ''))

/*
 * Пункты посадки — остановки на трассе, с которых продаются билеты
 * (docs/BOARDING_POINTS.md). Своей автостанции у такого пункта нет, и на
 * сайте он ОДНА сущность: и в «Откуда», и в разделе расписания. Своего
 * графика отправлений база продаж по нему не ведёт (рейсы чужие),
 * поэтому расписание ему заводят вручную в АИС — как в legacy, где для
 * таких мест лежал отдельный HTML-файл.
 *
 * В адресе пункт стоит НАЗВАНИЕМ, как и станция (`?from=Дросково`);
 * значение селекта `bp:<slug>` поддерживается для совместимости и уводит
 * на канонический адрес.
 */
const { data: departuresResp } = await useDepartures()
const boardingPoints = computed(() => departuresResp.value?.points ?? [])
const pointSlug = computed(() => boardingPointSlug(from.value))
const point = computed(() =>
  boardingPoints.value.find(
    p => p.name === from.value || p.slug === from.value || p.slug === pointSlug.value,
  ) ?? null)

const { data: stationsResp } = await useAsyncData('schedule-stations', () =>
  api<{ success: boolean, data: ScheduleStation[] }>('/schedule'))

const stations = computed(() => stationsResp.value?.data ?? [])

/*
 * АДРЕС СТРАНИЦЫ — ТОЛЬКО НАЗВАНИЕ СТАНЦИИ.
 *
 * Так было и в legacy: `?from=Орел АВ`. Латинские `orel-av` там были
 * именами ФАЙЛОВ расписания (`url/raspisanie/orel-av.html`), а не
 * адресами страниц, и два адреса на одну страницу сайту не нужны —
 * поисковик считает их дублем. Поэтому `slug` в адресе поддерживается
 * ровно затем, чтобы увести на канонический адрес ответом 301 (ниже).
 */
const selectedStation = computed(() =>
  stations.value.find(s => s.name === from.value || s.slug === from.value) ?? null)

/** Значение для селекта — всегда название: и у станции, и у пункта посадки. */
const selectedDeparture = computed(() =>
  selectedStation.value?.name ?? point.value?.name ?? from.value)

/*
 * Расписание запрашиваем и для пункта посадки: у него оно тоже может
 * быть (заведённое вручную), и тогда страница показывает маршруты рядом
 * с формой поиска — вместо двух разных «Дросково», как было сперва.
 */
const { data: scheduleResp, error } = await useAsyncData(
  'schedule',
  () => {
    if (!from.value) return Promise.resolve(null)

    const key = point.value?.slug ?? from.value

    return api<{ success: boolean, data: ScheduleData }>(`/schedule/${encodeURIComponent(key)}`)
      // У пункта посадки расписания может не быть вовсе — это не ошибка
      // страницы: форма поиска рейсов работает и без него.
      .catch(() => null)
  },
  { watch: [from, point] },
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

// Пояс автовокзала, а не UTC — см. useSiteDate.
const shiftDate = siteDateShifted

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

/*
 * LEGACY-АДРЕСА РАСПИСАНИЯ С ПУНКТОМ ПРИБЫТИЯ.
 *
 * В старом сайте рейсы до остановки показывались прямо здесь:
 * `/raspisanie-avtobusov?from=<станция>&to=<остановка>&date=<…>` (а также
 * `date=tomorrow|aftertomorrow`, дата по-русски «22 ноября 2026» и
 * `blij=1` — «ближайшие»). Таких ссылок полно в закладках, письмах и
 * поисковой выдаче, поэтому они не должны превращаться в битую страницу:
 * ведём их на /trips, где этот список теперь живёт, ответом 301.
 *
 * Станцию ищем УСТОЙЧИВО (resolveStation): регистр, «ё» и лишние пробелы
 * в адресе значения не имеют, а `from` может быть и кодом станции. Иначе
 * «орел ав» вместо «Орел АВ» оставался на странице расписания, которая
 * честно сообщала «расписание не опубликовано» — то есть для человека
 * ссылка выглядела сломанной.
 *
 * Список берём из станций ПРОДАЖ, а не из расписания: регулярное
 * расписание станции может быть ещё не опубликовано, а рейсы — есть.
 */
if (legacyTo.value && from.value && !isBoardingPoint(from.value)) {
  const { data: salesStations } = await useAsyncData('sales-stations', () =>
    api<{ success: boolean, data: { awtcod: number, name: string }[] }>('/stations'))

  const station = resolveStation(salesStations.value?.data ?? [], from.value)
  // Пункт посадки (остановка в пути) legacy не знал, но ссылку с ним
  // могли собрать руками — ведём на /trips тем же способом.
  const point = boardingPoints.value.find(p => p.slug === from.value || p.name === from.value)

  if (station || point) {
    const nearest = queryFlag(route.query.blij)
    // Без даты (и с неразобранной датой) legacy показывал рейсы на сегодня.
    const date = nearest ? '' : (legacyDate(String(route.query.date ?? '')) || shiftDate(0))

    await navigateTo({
      path: '/trips',
      query: {
        ...(station
          ? { awtcod: station.awtcod, from: station.name }
          : { from_point: point!.slug, from: point!.name }),
        to: legacyTo.value,
        ...(date ? { date } : {}),
        all: 1,
      },
    }, { redirectCode: 301 })
  }
  else if (import.meta.server) {
    /*
     * Станции с таким названием нет вовсе: страницы расписания по ней не
     * существует, значит и код ответа 404 — иначе ссылка остаётся в
     * поисковой выдаче и ведёт на текст ошибки. В legacy такой адрес
     * показывал «на станции ведутся технические работы», что неправда.
     */
    const event = useRequestEvent()
    if (event) setResponseStatus(event, 404)
  }
}

/*
 * Адрес со slug («?from=orel-av») уводим на канонический с названием
 * станции ответом 301: дублировать страницу двумя адресами незачем, а
 * ссылки такого вида успели попасть в АИС и в закладки.
 */
const canonicalFrom = computed(() => {
  if (selectedStation.value && from.value !== selectedStation.value.name) {
    return selectedStation.value.name
  }

  // `bp:droskovo` и просто `droskovo` — оба уводим на «Дросково».
  if (point.value && from.value !== point.value.name) {
    return point.value.name
  }

  return null
})

if (canonicalFrom.value && !legacyTo.value) {
  await navigateTo(
    { path: '/raspisanie-avtobusov', query: { from: canonicalFrom.value } },
    { redirectCode: 301 },
  )
}

/*
 * Расписания у станции нет (не опубликовано, или её убрали из раздела
 * признаком «Показывать в расписании») — страницы не существует, значит
 * и ответ 404. Иначе ссылка остаётся в поисковой выдаче и ведёт на текст
 * ошибки (та же причина, что у страницы тарифа — docs/STATIONS.md).
 */
if (import.meta.server && from.value && !point.value && !legacyTo.value) {
  const event = useRequestEvent()

  if (event && (error.value || !schedule.value)) {
    setResponseStatus(event, 404)
  }
}

/**
 * Название пункта отправления для заголовков. В адресе может стоять
 * `slug` («droskovo-kp»), а в заголовке нужно читаемое название.
 */
const departureTitle = computed(() =>
  schedule.value?.name ?? point.value?.name ?? selectedStation.value?.name ?? from.value)

useSeoMeta({
  title: () => from.value
    ? `Расписание автобусов ${departureTitle.value}`
    : 'Расписание автобусов автовокзалов Орловской области',
  description: () => from.value
    ? `Регулярное расписание отправлений автобусов от ${departureTitle.value}: маршруты, дни следования и время отправления.`
    : 'Расписание автобусов автовокзалов и кассовых пунктов Орловской области.',
})

/*
 * Пункты посадки, которых ещё НЕТ в списке расписаний.
 *
 * Пункт с заведённым расписанием приходит уже из `/schedule` (там он
 * стоит как источник расписания), и если добавить его ещё и отсюда, в
 * списке получится два «Дросково» — ровно то, от чего избавлялись,
 * объединяя пункт и станцию (docs/BOARDING_POINTS.md).
 */
const pointsWithoutSchedule = computed(() =>
  boardingPoints.value.filter(p => !stations.value.some(s => s.name === p.name)))

const stationOptions = computed(() => [
  ...stations.value.map(s => ({ label: s.name, value: s.name })),
  // Пункт посадки — таким же пунктом списка, как станция: на сайте это
  // одно место, а не «станция» и «остановка» по отдельности.
  ...pointsWithoutSchedule.value.map(p => ({ label: p.name, value: p.name })),
])

/** Смена пункта отправления меняет адрес страницы — ссылкой можно делиться. */
function selectStation(name: string | number | null | undefined) {
  router.push(name ? { query: { from: String(name) } } : { query: {} })
}

/*
 * Пункт прибытия и дата (legacy-форма на странице расписания). Выбор ведёт
 * на /trips — там рейсы до этой остановки со статусами, как в legacy.
 */
const destinationsResp = await useAsyncData(
  'schedule-destinations',
  () => {
    if (point.value) {
      return api<{ success: boolean, data: { name: string }[] }>(
        destinationsUrl(boardingPointValue(point.value.slug)),
      )
    }

    return schedule.value
      ? api<{ success: boolean, data: { name: string }[] }>(`/stations/${schedule.value.awtcod}/destinations`)
      : Promise.resolve(null)
  },
  // Список нужен только для формы — на SSR его не тянем.
  { server: false, watch: [schedule, point] },
)

const destinations = computed(() =>
  (destinationsResp.data.value?.data ?? []).map(d => ({ label: d.name, value: d.name })))

// Индикатор загрузки включаем только после гидрации: на сервере запроса нет,
// и «крутилка» с первого рендера клиента разошлась бы с серверной разметкой.
const mounted = ref(false)
onMounted(() => (mounted.value = true))
const destinationsLoading = computed(() =>
  mounted.value && destinationsResp.status.value === 'pending')

const today = siteDate()
const to = ref<string | null>(null)
const tripDate = ref(today)

function showTrips(nearest = false) {
  if (!to.value || (!schedule.value && !point.value)) return
  router.push({
    path: '/trips',
    query: {
      ...(point.value
        ? { from_point: point.value.slug, from: point.value.name }
        : { awtcod: schedule.value!.awtcod, from: schedule.value!.name }),
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
 * Печать страницы (в legacy — отдельная страница for_print.php). Вызывать
 * `print()` прямо из шаблона нельзя: в выражениях шаблона видны только
 * свойства компонента и короткий список глобалов Vue, куда `print` не входит.
 */
function printPage() {
  window.print()
}

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
    <!-- При прямом заходе на расписание станции возвращаться некуда —
         уводим к списку станций, а не на главную. -->
    <BackButton v-if="from" fallback="/raspisanie-avtobusov" />

    <h1 class="text-3xl font-light mb-2">
      Расписание автобусов<span v-if="schedule"> · {{ schedule.name }}</span><span
        v-else-if="point"
      > · {{ point.name }}</span>
    </h1>

    <!-- Объявление над расписанием: в legacy сюда включался тот же
         vidjets/news.php, что и на главную. Правится в АИС. -->
    <SiteBlock name="schedule_top" class="mb-5" />

    <p v-if="!from" class="text-muted mb-6">
      Выберите пункт отправления — покажем регулярное расписание маршрутов.
    </p>

    <!-- Пункт отправления переключается прямо на странице расписания -->
    <UCard v-if="from" class="mb-6 no-print">
      <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-[1fr_1fr_180px_auto] items-end">
        <UFormField label="Пункт отправления">
          <ResponsiveSelect
            :model-value="selectedDeparture"
            :items="stationOptions"
            placeholder="Выберите автовокзал или кассовый пункт"
            title="Пункт отправления"
            search-placeholder="Название автовокзала или кассы"
            @update:model-value="selectStation"
          />
        </UFormField>

        <!-- legacy: выбор пункта прибытия вёл к расписанию до этой остановки -->
        <UFormField label="Пункт прибытия">
          <ResponsiveSelect
            v-model="to"
            :items="destinations"
            :loading="destinationsLoading"
            placeholder="Выберите пункт прибытия"
            title="Пункт прибытия"
            search-placeholder="Название остановки"
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
      <!--
        Пункты посадки — такими же плитками, как станции; те, у которых
        расписание уже заведено, пришли выше вместе со станциями.
      -->
      <NuxtLink
        v-for="p in pointsWithoutSchedule"
        :key="p.slug"
        :to="{ query: { from: p.name } }"
        class="border border-default rounded-lg p-4 font-medium hover:border-primary transition-colors"
      >
        {{ p.name }}
      </NuxtLink>
    </div>

    <!--
      Пункт посадки: кассы на остановке нет, билет оформляет станция,
      которая ведёт рейс. Расписание у пункта может быть (заведённое
      вручную) — тогда оно показывается ниже обычной таблицей.
    -->
    <UAlert
      v-if="point && from"
      color="info"
      variant="subtle"
      class="mb-4"
      icon="i-lucide-map-pin"
      :title="`Остановка «${point.name}»`"
      :description="schedule
        ? 'Кассы на остановке нет: билет оформляет автостанция, которая ведёт рейс. Ниже — расписание проходящих автобусов, а чтобы купить билет, выберите пункт прибытия выше.'
        : 'Кассы на остановке нет: билет оформляет автостанция, которая ведёт рейс. Выберите пункт прибытия выше — покажем рейсы с временем отправления от этой остановки.'"
    />

    <UAlert
      v-if="!point && error"
      color="error"
      variant="subtle"
      title="Расписание недоступно"
      description="Для этой станции расписание пока не опубликовано. Попробуйте выбрать другой пункт отправления."
    />

    <template v-if="schedule">
      <div class="flex justify-end mb-3 no-print">
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-printer" @click="printPage">
          Версия для печати
        </UButton>
      </div>

      <p class="text-sm text-muted mb-4">
        Актуально на {{ schedule.generated_at }}. Указано для справки: расписание отправлений
        на конкретный день может отличаться от регулярного.
        <template v-if="schedule.is_manual">
          Билеты по этим рейсам продаются в кассах и на автостанциях маршрута: сама
          {{ schedule.name }} билеты не оформляет.
        </template>
        <template v-else>
          Наличие мест, стоимость и статус рейса смотрите в поиске билетов на нужную дату.
        </template>
      </p>

      <div v-if="!routes.length" class="py-10 text-center text-muted">
        По заданным условиям маршрутов не нашлось.
      </div>

      <div v-else class="space-y-3 print-schedule">
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
                <!--
                  Клик по времени открывает тариф рейса (как в legacy).

                  У расписания, заведённого вручную (станция закрыта, базы
                  продаж нет), рейса в базе НЕТ: `raicecod` пустой, и
                  ссылка на тариф вела бы на `/tarif?napcod=&raicecod=`,
                  то есть на «тариф не найден». Такое время показываем
                  обычным текстом.
                -->
                <template v-for="d in r.departures" :key="d.time + (d.raicecod ?? '')">
                  <UButton
                    v-if="d.raicecod"
                    :to="tariffLink(r, d)"
                    color="primary"
                    variant="soft"
                    size="md"
                    :title="`Стоимость проезда по рейсу, отправление ${d.time}`"
                  >
                    {{ d.time }}<span v-if="d.charter" class="ml-1 text-xs">(заказной)</span>
                  </UButton>
                  <span
                    v-else
                    class="inline-flex items-center rounded-md bg-elevated px-2.5 py-1.5 text-sm font-medium"
                  >
                    {{ d.time }}<span v-if="d.charter" class="ml-1 text-xs">(заказной)</span>
                  </span>
                </template>
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

  @page {
    margin: 10mm;
  }

  /* На бумаге времена отправления — обычный чёрный текст, а не зелёные
     кнопки-ссылки; маршруты печатаем плотнее и не рвём между страницами. */
  .print-schedule {
    font-size: 9pt;
  }

  .print-schedule a,
  .print-schedule .rounded-md {
    color: #000 !important;
    background: transparent !important;
    padding: 0 6px 0 0 !important;
    box-shadow: none !important;
  }

  /* Карточки на бумаге лишние: печатаем маршруты сплошным списком,
     иначе на лист влезает вдвое меньше строк, чем могло бы. */
  .print-schedule > * {
    break-inside: avoid;
    box-shadow: none !important;
    border: 0 !important;
    border-bottom: 1px solid #ddd !important;
    border-radius: 0 !important;
    margin: 0 !important;
  }

  .print-schedule > * > * {
    padding: 3px 0 !important;
  }
}
</style>
