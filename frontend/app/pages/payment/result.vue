<script setup lang="ts">
const route = useRoute()
const api = useApi()

interface OrderData {
  order_id: string
  status: string
  from_name: string
  to_name: string
  trip_name: string
  carrier: string
  date_start: string
  time_start: string
  total_price: number
  email: string
  back_code: string | null
  tickets: {
    place: string
    type: string
    serial: string | null
    number: string | null
    last_name: string
    first_name: string
    middle_name: string
    price: number
    baggage: number
    price_bag: number
  }[]
}

// Сбербанк возвращает ?orderId=<id заказа в шлюзе>
const sberOrderId = String(route.query.orderId ?? '')

const { data, status, error } = await useAsyncData('confirm', () =>
  api<{ success: boolean, data: OrderData }>('/orders/confirm', {
    method: 'POST',
    body: { sber_order_id: sberOrderId },
  }), { server: false })

const order = computed(() => data.value?.data)

function formatDate(d?: string) {
  return d ? new Date(d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}
</script>

<template>
  <UContainer class="py-10 max-w-3xl space-y-6">
    <div class="flex gap-2 text-sm">
      <UBadge color="neutral" variant="soft">1. Рейсы</UBadge>
      <UBadge color="neutral" variant="soft">2. Пассажиры</UBadge>
      <UBadge color="neutral" variant="soft">3. Оплата</UBadge>
      <UBadge :color="order?.status === 'completed' ? 'success' : 'neutral'" variant="solid">4. Готово</UBadge>
    </div>

    <div v-if="status === 'pending'" class="text-center py-16 space-y-4">
      <UIcon name="i-lucide-loader-circle" class="size-10 animate-spin text-error" />
      <div class="text-lg">Проверяем оплату и оформляем билеты…</div>
      <div class="text-sm text-muted">Не закрывайте страницу</div>
    </div>

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Не удалось подтвердить покупку"
      :description="String(error?.data?.error ?? error?.message ?? '')"
    />

    <template v-else-if="order?.status === 'completed'">
      <UAlert
        color="success"
        variant="subtle"
        icon="i-lucide-ticket-check"
        title="Вы успешно приобрели билет(ы)!"
        :description="`Данные переданы на автовокзал. Электронный билет и чек будут отправлены на ${order.email}.`"
      />

      <UCard>
        <template #header>
          <div class="font-medium">
            {{ order.from_name }} → {{ order.to_name }} ·
            {{ formatDate(order.date_start) }} в {{ order.time_start }}
          </div>
          <div class="text-sm text-muted">Рейс {{ order.trip_name }} · {{ order.carrier }}</div>
        </template>

        <div class="divide-y divide-default">
          <div
            v-for="t in order.tickets"
            :key="t.number ?? t.place"
            class="py-3 flex flex-wrap items-center gap-3"
          >
            <UBadge color="neutral" variant="soft">Место {{ t.place }}</UBadge>
            <div class="flex-1 min-w-40">
              <div class="font-medium">{{ t.last_name }} {{ t.first_name }} {{ t.middle_name }}</div>
              <div class="text-sm text-muted">
                Билет {{ t.serial }} № {{ t.number }}
                <template v-if="t.baggage > 0"> · багаж: {{ t.baggage }} место</template>
              </div>
            </div>
            <div class="font-semibold">{{ (t.price + t.baggage * t.price_bag).toFixed(2) }} ₽</div>
          </div>
        </div>

        <template #footer>
          <div class="flex items-center justify-between">
            <div class="text-sm text-muted">
              Код для возврата: <b class="text-highlighted">{{ order.back_code }}</b>
            </div>
            <div>Итого: <b class="text-xl">{{ order.total_price.toFixed(2) }} ₽</b></div>
          </div>
        </template>
      </UCard>

      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-id-card"
        title="Обязательно возьмите с собой паспорт"
        description="Посадка в автобус осуществляется по документу, указанному при покупке билета. Печать билета необязательна. Возврат — не позднее чем за 30 минут до отправления."
      />

      <div class="text-center">
        <UButton to="/" variant="outline" color="neutral" icon="i-lucide-house">
          Вернуться на главную
        </UButton>
      </div>
    </template>
  </UContainer>
</template>
