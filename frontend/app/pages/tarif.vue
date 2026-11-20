<script setup lang="ts">
/**
 * Тариф рейса по остановкам (перенос legacy /tarif).
 *
 * Отличие от legacy: тарифную категорию (`nomkat`) страница НЕ передаёт —
 * её выбирает сервер. У рейса категорий бывает две: действующая и новая,
 * вступающая в силу с даты смены (raicelst.CHANDATE). Клиент просит только
 * вариант — `current` или `upcoming`. Старые ссылки с `nomkat` продолжают
 * работать: параметр просто игнорируется, показывается действующий тариф.
 */
import { setResponseHeader, setResponseStatus } from 'h3'

const route = useRoute()
const api = useApi()

interface TariffStop {
  name: string
  statcode: number
  km: number
  price_full: number
  price_child: number
  price_baggage: number
  insurance: number
  /** null — времени в пути до остановки в расписании нет, прибытие неизвестно. */
  arrival: string | null
  /**
   * На какие сутки от отправления приходится прибытие: 0 — в день
   * отправления. Одного времени мало: рейсы бывают дольше суток
   * (Орёл–Симферополь — 25 ч 35 мин), и «Прибытие 12:50» без пометки
   * читается как «в тот же день».
   */
  arrival_day_offset?: number
  time_way: string | null
  time_way_minutes: number | null
  stop_minutes: number
}

interface TariffVariants {
  current: { nomkat: number, valid_to: string | null }
  upcoming?: { nomkat: number, valid_from: string }
}

/**
 * Остановка отправления, если тариф смотрят ОТ НЕЁ (пункт посадки).
 *
 * `departure` — время, когда автобус уходит с остановки (рейс отправляется
 * раньше), `km` — её километраж от начала трассы: расстояния в таблице
 * уже пересчитаны от остановки (docs/BOARDING_POINTS.md).
 */
interface TariffFromStop {
  name: string
  km: number | null
  departure: string | null
  offset_minutes: number | null
  /** false — тариф заведён, а посадка в расписании рейса запрещена. */
  boarding_allowed: boolean | null
}

interface Tariff {
  awtcod: number
  napcod: number
  raicecod: number
  from_stop?: TariffFromStop | null
  route_name: string
  route_number: string | null
  carrier: string | null
  charter: boolean
  time_start: string
  variant: 'current' | 'upcoming'
  nomkat: number
  change_date: string | null
  variants: TariffVariants
  stops: TariffStop[]
}

// from — код станции отправления (в legacy назывался так же).
const awtcod = computed(() => Number(route.query.from ?? route.query.awtcod ?? 0))
const napcod = computed(() => Number(route.query.napcod ?? 0))
const raicecod = computed(() => Number(route.query.raicecod ?? 0))
const variant = computed(() => route.query.variant === 'upcoming' ? 'upcoming' : 'current')
/*
 * Тариф ОТ ОСТАНОВКИ на трассе: `from_point` — ключ пункта посадки сайта
 * (название остановки в базе станции сервер берёт из справочника сам),
 * `from_stop` — прямое название остановки, если пункта посадки нет.
 * Пусто — тариф от самой станции, как было всегда.
 */
const fromPoint = computed(() => String(route.query.from_point ?? ''))
const fromStop = computed(() => String(route.query.from_stop ?? ''))

/*
 * Адрес обязан называть рейс целиком: станция + направление + рейс.
 * Если чего-то нет (обрезанная ссылка вида `/tarif?from=5701&napcod=35` —
 * таких приходит полторы сотни в сутки, их оставляют поисковики и старые
 * ссылки), запрос НЕ делаем: `Number(undefined ?? 0)` давал ноль, сайт
 * шёл в API с `raicecod=0`, получал 422 и писал ошибку в журнал — по
 * сотне записей в сутки на пустом месте.
 */
const hasTrip = computed(() => awtcod.value > 0 && napcod.value > 0 && raicecod.value > 0)

const { data, error, refresh } = await useAsyncData(
  'tariff',
  () => hasTrip.value
    ? api<{
      success: boolean
      awtname: string | null
      from_point: string | null
      from_name: string | null
      data: Tariff
    }>('/tariff', {
      query: {
        awtcod: awtcod.value,
        napcod: napcod.value,
        raicecod: raicecod.value,
        variant: variant.value,
        ...(fromPoint.value ? { from_point: fromPoint.value } : {}),
        ...(!fromPoint.value && fromStop.value ? { from_stop: fromStop.value } : {}),
      },
    })
    : Promise.resolve(null),
  { watch: [awtcod, napcod, raicecod, variant, fromPoint, fromStop] },
)

const tariff = computed(() => data.value?.data ?? null)

/*
 * ТРИ РАЗНЫХ СОСТОЯНИЯ, и путать их нельзя — ни для человека, ни для
 * поисковика (страница тарифа индексируется, ссылок на неё много):
 *
 *  - корректное — 200;
 *  - временно недоступное (станция не отвечает, сервис продаж
 *    перезапускается) — 503 и Retry-After: «зайди позже», страница из
 *    выдачи не выпадает;
 *  - несуществующее или некорректное (нет такого рейса, остановки,
 *    пункта посадки; обрезанная ссылка без рейса) — 404.
 *
 * До 21.11.2026 всё это отдавалось с кодом 200, и «тариф по рейсу,
 * которого нет» выглядел для робота обычной страницей сайта.
 */
const UNAVAILABLE_CODES = [
  'station_unavailable',
  'sales_api_unavailable',
  'station_sales_disabled',
  'station_refunds_disabled',
]

interface ApiError {
  statusCode?: number
  data?: { error?: string, error_code?: string }
}

const apiError = computed<ApiError | null>(() => (error.value as ApiError | null) ?? null)
const errorCode = computed(() => apiError.value?.data?.error_code ?? null)

/** Временная недоступность: запрос верный, повторять имеет смысл. */
const unavailable = computed(() =>
  apiError.value?.statusCode === 503 || UNAVAILABLE_CODES.includes(errorCode.value ?? ''))

/** Текст отказа от сервера — он точнее общего «не удалось показать». */
const errorText = computed(() => apiError.value?.data?.error ?? null)

/*
 * Код ответа ставим на сервере: к этому моменту запрос тарифа уже
 * выполнен, а ответ ещё не отправлен. На клиенте (переход по ссылке
 * внутри сайта) ставить нечего — там код ответа уже не меняется.
 */
const event = useRequestEvent()

if (import.meta.server && event) {
  if (unavailable.value) {
    setResponseStatus(event, 503)
    // У станции это минуты, а не часы: без Retry-After клиенты считают
    // недоступность бессрочной.
    setResponseHeader(event, 'Retry-After', '60')
  }
  else if (!hasTrip.value || error.value || !tariff.value) {
    setResponseStatus(event, 404)
  }
}

/** Остановка отправления (пусто — тариф от самой станции). */
const stop = computed(() => tariff.value?.from_stop ?? null)

/**
 * Что считать пунктом отправления в заголовке и ссылках: название пункта
 * посадки («Дросково»), название остановки в базе станции или сама
 * станция.
 */
const departureName = computed(() =>
  data.value?.from_name || stop.value?.name || data.value?.awtname || '')

/** Время, с которого начинается поездка: посадка на остановке или рейс. */
const departureTime = computed(() => stop.value?.departure || tariff.value?.time_start || '')

/**
 * Клик по остановке ведёт к рейсам до неё — расписание с той же даты,
 * что была на странице, откуда пришли (в legacy /tarif дата так же
 * таскалась в адресе), со всеми рейсами: тариф смотрят и по тем, что
 * онлайн не продаются.
 */
const stopDate = computed(() => {
  const raw = String(route.query.date ?? '')
  return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : siteDate()
})

function stopLink(row: TariffStop) {
  return {
    path: '/trips',
    query: {
      // От пункта посадки рейсы ищутся по нему: станцию выбирает сервер
      // по тарифам (docs/BOARDING_POINTS.md).
      ...(fromPoint.value ? { from_point: fromPoint.value } : { awtcod: awtcod.value }),
      from: departureName.value,
      to: row.name,
      date: stopDate.value,
      all: 1,
    },
  }
}

function formatDate(iso?: string | null) {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

/** Хвост заголовка legacy: «, тариф действует c 01.09.2026» / «по 31.08.2026». */
const validityNote = computed(() => {
  const t = tariff.value
  if (!t || !t.change_date) return ''

  return t.variant === 'upcoming'
    ? `, тариф действует c ${formatDate(t.variants.upcoming?.valid_from)}`
    : `, тариф действует по ${formatDate(t.variants.current.valid_to)}`
})

useSeoMeta({
  title: () => tariff.value
    ? (stop.value
        ? `Тариф от остановки ${departureName.value} по рейсу ${tariff.value.route_name}`
        : `Тариф по рейсу ${tariff.value.route_name}, отправление ${tariff.value.time_start}`)
    : 'Стоимость проезда',
})

const otherVariantLink = computed(() => ({
  path: '/tarif',
  query: {
    from: awtcod.value,
    napcod: napcod.value,
    raicecod: raicecod.value,
    variant: variant.value === 'current' ? 'upcoming' : 'current',
    ...(fromPoint.value ? { from_point: fromPoint.value } : {}),
    ...(!fromPoint.value && fromStop.value ? { from_stop: fromStop.value } : {}),
  },
}))

/**
 * Печать страницы. Вызывать `print()` прямо из шаблона нельзя: в выражениях
 * шаблона видны только свойства компонента и короткий список глобалов Vue,
 * куда `print` не входит — кнопка молча ничего не делала.
 */
function printPage() {
  window.print()
}

/**
 * «Скачать тариф» — как в legacy: та же таблица файлом, который открывается
 * в Word (там это был HTML с расширением .rtf через атрибут download).
 */
/** Пометка «+N сут.» у времени прибытия; пусто — приезжает в день отправления. */
function dayNote(stop: TariffStop) {
  const offset = stop.arrival_day_offset ?? 0

  return offset > 0 ? ` (+${offset} сут.)` : ''
}

function download() {
  const t = tariff.value
  if (!t) return

  const rows = t.stops.map(s => `<tr>
    <td>${s.name}</td><td>${s.km.toFixed(2).replace('.', ',')}</td>
    <td>${s.price_full.toFixed(2)}</td><td>${s.price_child.toFixed(2)}</td>
    <td>${s.price_baggage.toFixed(2)}</td><td>${s.insurance.toFixed(2)}</td>
    <td>${s.arrival ? s.arrival + dayNote(s) : '—'}</td>
    <td>${s.time_way ?? '—'}</td></tr>`).join('')

  const html = `<html><head><meta charset="utf-8"></head><body>
    <h2>Тариф стоимости проезда, провоза багажа/ручной клади${validityNote.value}
        ${stop.value ? `от остановки ${departureName.value} ` : ''}по рейсу ${t.route_name},
        время отправления ${stop.value ? (departureTime.value || t.time_start) : t.time_start}</h2>
    <table border="1" cellspacing="0" cellpadding="4">
      <tr><th>Остановка</th><th>Расстояние, км</th><th>Полный</th><th>Детский</th>
          <th>Багаж/ручная кладь</th><th>Добровольная страховка</th>
          <th>Прибытие</th><th>Время в пути</th></tr>${rows}
    </table></body></html>`

  const url = URL.createObjectURL(new Blob([html], { type: 'application/rtf' }))
  const link = document.createElement('a')
  link.href = url
  link.download = `${t.route_name} тариф.rtf`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <UContainer class="py-8 max-w-5xl">
    <BackButton />

    <!-- Рейс в адресе не указан: ссылка обрезана. Это не ошибка загрузки,
         поэтому и текст другой — человеку надо вернуться в расписание. -->
    <UAlert
      v-if="!hasTrip"
      color="warning"
      variant="subtle"
      title="Рейс не указан"
      description="Ссылка неполная: в ней нет рейса. Откройте тариф из расписания — нажмите время отправления нужного рейса."
      :actions="[{ label: 'Перейти к расписанию', to: '/raspisanie-avtobusov', color: 'neutral' }]"
    />

    <!--
      Временно недоступно: станция не отвечает или сервис продаж
      перезапускается. Это не «рейса нет» — человеку надо повторить
      попытку, и ссылку сохранять имеет смысл.
    -->
    <UAlert
      v-else-if="unavailable"
      color="warning"
      variant="subtle"
      icon="i-lucide-cloud-off"
      title="Тариф сейчас недоступен"
      :description="errorText
        ?? 'Автостанция временно не отвечает. Попробуйте обновить страницу через несколько минут.'"
      :actions="[{ label: 'Обновить', color: 'warning', variant: 'outline', onClick: () => refresh() }]"
    />

    <!-- Такого рейса, остановки или пункта посадки нет (ответ 404). -->
    <UAlert
      v-else-if="error || !tariff"
      color="error"
      variant="subtle"
      title="Тариф не найден"
      :description="errorText
        ?? 'Проверьте ссылку: такого рейса нет в расписании — возможно, он снят или ссылка устарела.'"
      :actions="[{ label: 'Перейти к расписанию', to: '/raspisanie-avtobusov', color: 'neutral' }]"
    />

    <template v-else>
      <h1 class="text-2xl font-light mb-2">
        Информация о стоимости проезда, провоза багажа/ручной клади{{ validityNote }}
        <template v-if="stop">
          от остановки {{ departureName }} по рейсу {{ tariff.route_name }},
          время отправления с остановки {{ departureTime || '—' }}
        </template>
        <template v-else>
          по рейсу {{ tariff.route_name }}, время отправления {{ tariff.time_start }}
        </template>
      </h1>

      <div class="text-sm text-muted mb-4">
        <span v-if="tariff.route_number">Маршрут № {{ tariff.route_number }}</span>
        <span v-if="tariff.carrier"> · перевозчик {{ tariff.carrier }}</span>
        <!--
          У тарифа от остановки важно и время отправления САМОГО рейса: по
          нему пассажир сверяется с расписанием и со станцией.
        -->
        <span v-if="stop"> · рейс отправляется в {{ tariff.time_start }}</span>
        <span v-if="stop && stop.km !== null"> · остановка на {{ stop.km }} км трассы</span>
      </div>

      <!--
        Тариф от остановки заведён, а посадка в расписании этого рейса
        запрещена: купить билет станция не даст, и сказать это надо здесь,
        а не на форме покупки.
      -->
      <UAlert
        v-if="stop && stop.boarding_allowed === false"
        color="warning"
        variant="subtle"
        class="mb-4"
        icon="i-lucide-triangle-alert"
        title="На этом рейсе посадка на остановке закрыта"
        description="Цены ниже заведены, но в расписании остановок этого рейса посадка запрещена — билет от остановки оформить нельзя. Выберите другой рейс."
      />

      <!-- Тарифов от остановки может не быть вовсе — это не ошибка. -->
      <UAlert
        v-if="stop && !tariff.stops.length"
        color="warning"
        variant="subtle"
        class="mb-4"
        icon="i-lucide-info"
        :title="`От остановки ${departureName} на этом рейсе тарифы не заведены`"
        description="Станция заводит их отдельно от обычных: пока их нет, билет от остановки на этот рейс не продаётся."
      />

      <div class="flex flex-wrap gap-2 mb-4 no-print">
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-printer" @click="printPage">
          Версия для печати
        </UButton>
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-download" @click="download">
          Скачать тариф
        </UButton>
      </div>

      <!-- Смена тарифа: у рейса две категории — действующая и будущая -->
      <UAlert
        v-if="tariff.change_date"
        color="warning"
        variant="subtle"
        class="mb-4 no-print"
        :title="`Внимание! Тариф изменяется с ${formatDate(tariff.change_date)}`"
      >
        <template #description>
          <div class="flex flex-wrap items-center gap-3">
            <span v-if="tariff.variant === 'upcoming'">
              Показан новый тариф, действующий с {{ formatDate(tariff.variants.upcoming?.valid_from) }}
            </span>
            <span v-else>
              Показан старый тариф, действующий до {{ formatDate(tariff.variants.current.valid_to) }}
            </span>
            <UButton :to="otherVariantLink" color="warning" variant="outline" size="xs">
              {{ tariff.variant === 'upcoming' ? 'Показать старый тариф' : 'Показать новый тариф' }}
            </UButton>
          </div>
        </template>
      </UAlert>

      <!--
        Мобильная вёрстка — как в legacy (`url/tarif.php`): на телефоне
        таблица пряталась (`visible-md visible-lg`), а вместо неё шли
        карточки по остановкам (`visible-xs visible-sm`) с теми же полями
        и подписями. Восемь колонок в 390px не помещаются, и горизонтальная
        прокрутка таблицы читается плохо.
        На печать карточки не идут (`no-print`): печатается таблица — её
        `display` в @media print возвращается принудительно, иначе с
        телефона распечатался бы пустой лист.
      -->
      <div class="md:hidden space-y-3 no-print">
        <div
          v-for="s in tariff.stops"
          :key="s.statcode"
          class="border border-default rounded-lg overflow-hidden"
        >
          <div class="bg-elevated px-3 py-2 text-center font-medium">
            Остановка
            <NuxtLink
              :to="stopLink(s)"
              class="text-primary hover:underline"
              :title="`Рейсы до остановки «${s.name}»`"
            >
              {{ s.name }}
            </NuxtLink>
          </div>
          <dl class="divide-y divide-default text-sm">
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Расстояние</dt>
              <dd class="font-medium">{{ s.km.toFixed(2) }} км</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Тариф полный</dt>
              <dd class="font-medium">{{ s.price_full.toFixed(2) }} ₽</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Тариф детский</dt>
              <dd class="font-medium">{{ s.price_child.toFixed(2) }} ₽</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Багаж/ручная кладь</dt>
              <dd class="font-medium">{{ s.price_baggage.toFixed(2) }} ₽</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Добровол. страховка</dt>
              <dd class="font-medium">{{ s.insurance.toFixed(2) }} ₽</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Прибытие</dt>
              <dd class="font-medium">
                <template v-if="s.arrival">{{ s.arrival }}{{ dayNote(s) }}</template>
                <span v-else class="text-muted">уточняйте на станции</span>
              </dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <dt class="text-muted">Время в пути</dt>
              <dd class="font-medium">{{ s.time_way ?? '—' }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <div class="hidden md:block overflow-x-auto print-table">
        <table class="w-full text-sm border border-default">
          <thead class="bg-elevated">
            <tr>
              <th class="text-left p-2 border border-default">Остановка</th>
              <th class="p-2 border border-default">Расстояние, км</th>
              <th class="p-2 border border-default">Полный</th>
              <th class="p-2 border border-default">Детский</th>
              <th class="p-2 border border-default">Багаж/ручная кладь</th>
              <th class="p-2 border border-default">Добровольная страховка</th>
              <th class="p-2 border border-default">Прибытие</th>
              <th class="p-2 border border-default">Время в пути</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in tariff.stops" :key="s.statcode" class="odd:bg-muted/40">
              <td class="p-2 border border-default">
                <NuxtLink
                  :to="stopLink(s)"
                  class="text-primary hover:underline"
                  :title="`Рейсы до остановки «${s.name}»`"
                >
                  {{ s.name }}
                </NuxtLink>
              </td>
              <td class="p-2 border border-default text-right">{{ s.km.toFixed(2) }}</td>
              <td class="p-2 border border-default text-right">{{ s.price_full.toFixed(2) }}</td>
              <td class="p-2 border border-default text-right">{{ s.price_child.toFixed(2) }}</td>
              <td class="p-2 border border-default text-right">{{ s.price_baggage.toFixed(2) }}</td>
              <td class="p-2 border border-default text-right">{{ s.insurance.toFixed(2) }}</td>
              <td class="p-2 border border-default text-center">
                <template v-if="s.arrival">{{ s.arrival }}{{ dayNote(s) }}</template>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="p-2 border border-default text-center">{{ s.time_way ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </UContainer>
</template>

<style>
@media print {
  header,
  footer,
  .no-print {
    display: none !important;
  }

  @page {
    margin: 10mm;
  }

  /* На бумаге прокрутки нет — таблица должна поместиться в ширину листа.
     `display` возвращаем принудительно: на узком экране таблица скрыта
     (`hidden md:block`), и печать с телефона дала бы пустой лист. */
  .print-table {
    display: block !important;
    overflow: visible !important;
  }

  .print-table table {
    table-layout: fixed;
    width: 100%;
    font-size: 8pt;
  }

  .print-table th,
  .print-table td {
    padding: 2px 3px !important;
    overflow-wrap: anywhere;
  }

  /* Названия остановок — ссылки; на бумаге печатаем обычным чёрным текстом. */
  .print-table a {
    color: #000 !important;
    text-decoration: none !important;
  }

  h1 {
    font-size: 14pt;
  }
}
</style>
