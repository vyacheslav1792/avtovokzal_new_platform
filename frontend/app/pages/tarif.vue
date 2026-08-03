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
  arrival: string
  time_way: string
  time_way_minutes: number
  stop_minutes: number
}

interface TariffVariants {
  current: { nomkat: number, valid_to: string | null }
  upcoming?: { nomkat: number, valid_from: string }
}

interface Tariff {
  awtcod: number
  napcod: number
  raicecod: number
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

const { data, error } = await useAsyncData(
  'tariff',
  () => api<{ success: boolean, awtname: string | null, data: Tariff }>('/tariff', {
    query: {
      awtcod: awtcod.value,
      napcod: napcod.value,
      raicecod: raicecod.value,
      variant: variant.value,
    },
  }),
  { watch: [awtcod, napcod, raicecod, variant] },
)

const tariff = computed(() => data.value?.data ?? null)

/**
 * Клик по остановке ведёт к рейсам до неё — расписание с той же даты,
 * что была на странице, откуда пришли (в legacy /tarif дата так же
 * таскалась в адресе), со всеми рейсами: тариф смотрят и по тем, что
 * онлайн не продаются.
 */
const stopDate = computed(() => {
  const raw = String(route.query.date ?? '')
  return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : new Date().toISOString().slice(0, 10)
})

function stopLink(stop: TariffStop) {
  return {
    path: '/trips',
    query: {
      awtcod: awtcod.value,
      from: data.value?.awtname ?? '',
      to: stop.name,
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
    ? `Тариф по рейсу ${tariff.value.route_name}, отправление ${tariff.value.time_start}`
    : 'Стоимость проезда',
})

const otherVariantLink = computed(() => ({
  path: '/tarif',
  query: {
    from: awtcod.value,
    napcod: napcod.value,
    raicecod: raicecod.value,
    variant: variant.value === 'current' ? 'upcoming' : 'current',
  },
}))

/**
 * «Скачать тариф» — как в legacy: та же таблица файлом, который открывается
 * в Word (там это был HTML с расширением .rtf через атрибут download).
 */
function download() {
  const t = tariff.value
  if (!t) return

  const rows = t.stops.map(s => `<tr>
    <td>${s.name}</td><td>${s.km.toFixed(2).replace('.', ',')}</td>
    <td>${s.price_full.toFixed(2)}</td><td>${s.price_child.toFixed(2)}</td>
    <td>${s.price_baggage.toFixed(2)}</td><td>${s.insurance.toFixed(2)}</td>
    <td>${s.arrival}</td><td>${s.time_way}</td></tr>`).join('')

  const html = `<html><head><meta charset="utf-8"></head><body>
    <h2>Тариф стоимости проезда, провоза багажа/ручной клади${validityNote.value}
        по рейсу ${t.route_name}, время отправления ${t.time_start}</h2>
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
    <UAlert
      v-if="error || !tariff"
      color="error"
      variant="subtle"
      title="Не удалось показать тариф"
      description="Проверьте ссылку: рейс не найден или данные станции временно недоступны."
    />

    <template v-else>
      <h1 class="text-2xl font-light mb-2">
        Информация о стоимости проезда, провоза багажа/ручной клади{{ validityNote }}
        по рейсу {{ tariff.route_name }}, время отправления {{ tariff.time_start }}
      </h1>

      <div class="text-sm text-muted mb-4">
        <span v-if="tariff.route_number">Маршрут № {{ tariff.route_number }}</span>
        <span v-if="tariff.carrier"> · перевозчик {{ tariff.carrier }}</span>
      </div>

      <div class="flex flex-wrap gap-2 mb-4 no-print">
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-arrow-left" @click="$router.back()">
          Назад
        </UButton>
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-printer" @click="print()">
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

      <div class="overflow-x-auto">
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
              <td class="p-2 border border-default text-center">{{ s.arrival }}</td>
              <td class="p-2 border border-default text-center">{{ s.time_way }}</td>
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
}
</style>
