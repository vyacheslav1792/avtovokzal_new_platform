<script setup lang="ts">
import type { Trip } from '~/composables/useApi'

const route = useRoute()
const router = useRouter()
const api = useApi()
const selectedTrip = useSelectedTrip()

const awtcod = computed(() => Number(route.query.awtcod))
const fromName = computed(() => String(route.query.from ?? ''))
const toName = computed(() => String(route.query.to ?? ''))
const date = computed(() => String(route.query.date ?? ''))

const { data, status, error } = await useAsyncData(
  () => `trips-${awtcod.value}-${toName.value}-${date.value}`,
  () => api<{ success: boolean, awtname: string, data: Trip[] }>('/trips', {
    query: { awtcod: awtcod.value, to: toName.value, date: date.value },
  }),
  { watch: [awtcod, toName, date] },
)

const trips = computed(() => (data.value?.data ?? []).filter(t => !t.canceled))

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'long' })
}

function buy(trip: Trip) {
  selectedTrip.value = {
    trip,
    from_awtcod: awtcod.value,
    from_name: data.value?.awtname ?? fromName.value,
    to_name: toName.value,
  }
  router.push('/buy')
}

function canBuy(trip: Trip) {
  if (!trip.available || !trip.internet_sale_available || trip.transit || trip.free_places < 1) {
    return false
  }
  // не позднее чем за ~30 минут до отправления
  return new Date(`${trip.date_start}T${trip.time_start}`).getTime() > Date.now() + 32 * 60 * 1000
}
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <UButton icon="i-lucide-arrow-left" variant="ghost" color="neutral" to="/">Назад</UButton>
      <h1 class="text-xl md:text-2xl font-light">
        Рейсы {{ fromName }} → {{ toName }} на {{ formatDate(date) }}
      </h1>
    </div>

    <!-- Шаги покупки -->
    <div class="flex gap-2 text-sm text-muted">
      <UBadge color="error" variant="solid">1. Рейсы</UBadge>
      <UBadge color="neutral" variant="soft">2. Пассажиры</UBadge>
      <UBadge color="neutral" variant="soft">3. Оплата</UBadge>
      <UBadge color="neutral" variant="soft">4. Готово</UBadge>
    </div>

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
      description="На выбранную дату рейсы отсутствуют, либо продажа на них через сайт закрыта."
    />

    <div v-else class="space-y-4">
      <UCard v-for="trip in trips" :key="`${trip.napcod}-${trip.raicecod}-${trip.time_start}`">
        <div class="flex flex-col md:flex-row md:items-center gap-4">
          <div class="flex items-center gap-3 text-2xl font-semibold shrink-0">
            <UIcon name="i-lucide-bus" class="text-error" />
            <span>{{ trip.time_start.slice(0, 5) }}</span>
            <UIcon name="i-lucide-arrow-right" class="text-muted size-5" />
            <span>{{ trip.time_end?.slice(0, 5) }}</span>
          </div>

          <div class="flex-1 min-w-0">
            <div class="font-medium truncate">Рейс: {{ trip.trip_name }}–{{ trip.direction_name }}</div>
            <div class="text-sm text-muted truncate">
              Перевозчик: {{ trip.carrier }} · {{ trip.bus }}
            </div>
            <div class="text-sm text-muted">
              Полный: <b class="text-highlighted">{{ trip.price_full.toFixed(2) }} ₽</b>
              · Детский: <b class="text-highlighted">{{ trip.price_child.toFixed(2) }} ₽</b>
              · Багаж: <b class="text-highlighted">{{ trip.price_baggage.toFixed(2) }} ₽</b>
            </div>
          </div>

          <div class="flex items-center gap-4 shrink-0">
            <div class="text-sm text-muted">
              свободно: <b class="text-highlighted">{{ trip.free_places }}</b>
            </div>
            <UButton v-if="canBuy(trip)" color="error" size="lg" @click="buy(trip)">
              Купить
            </UButton>
            <UBadge v-else color="neutral" variant="soft">Продажа закрыта</UBadge>
          </div>
        </div>
      </UCard>
    </div>
  </UContainer>
</template>
