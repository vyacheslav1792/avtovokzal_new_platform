<script setup lang="ts">
const route = useRoute()
const api = useApi()
const { isLoggedIn } = useAuth()

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

const { data, status, error, refresh } = await useAsyncData('confirm', () =>
  api<{ success: boolean, data: OrderData }>('/orders/confirm', {
    method: 'POST',
    body: { sber_order_id: sberOrderId },
  }), { server: false })

const order = computed(() => data.value?.data)

// Покупка доведена до конца — напоминание о ней больше не нужно
// (его показывает PendingOrderNotice по заказам, запомненным в браузере).
watch(order, (value) => {
  if (value?.status === 'completed') forgetPendingOrder(value.order_id)
}, { immediate: true })

function formatDate(d?: string) {
  return d ? new Date(d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

// PDF собирает backend (перенос legacy pdf.php) — ссылка ведёт прямо на него,
// браузер сам скачает файл по Content-Disposition.
const pdfUrl = computed(() => order.value
  ? `${useRuntimeConfig().public.apiBase}/api/v1/orders/${order.value.order_id}/pdf`
  : '')
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

    <template v-else-if="error">
      <UAlert
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="Не удалось подтвердить покупку"
        :description="String(error?.data?.error ?? error?.message ?? '')"
      />
      <!-- Деньги могли уже списаться: покупатель не должен уйти со страницы,
           не зная, что делать дальше. -->
      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-life-buoy"
        title="Что делать"
        description="Попробуйте обновить страницу — возможно, сбой был временным. Если билеты так и не оформятся, напишите нам: деньги за неоформленную покупку возвращаются."
        :actions="[
          { label: 'Повторить', color: 'error', onClick: () => refresh() },
          { label: 'Написать нам', color: 'neutral', variant: 'outline', to: '/feedback' },
        ]"
      />
    </template>

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

      <!-- Гостю сразу предлагаем кабинет: то же письмо уйдёт на почту. -->
      <UAlert
        v-if="!isLoggedIn"
        color="primary"
        variant="subtle"
        icon="i-lucide-user-plus"
        title="Заведите личный кабинет"
        :description="`Билеты этого и будущих заказов на ${order.email} будут в одном месте: печать, PDF и возврат без кода. Приглашение мы отправили на почту.`"
        :actions="[{
          label: 'Создать кабинет',
          color: 'primary',
          // Почта заказа подставляется в форму регистрации: вводить её
          // заново не нужно, а кабинет сразу увидит эту покупку
          // (заказы ищутся и по orders.email).
          to: { path: '/register', query: { email: order.email } },
        }]"
      />

      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-id-card"
        title="Обязательно возьмите с собой паспорт"
        description="Посадка в автобус осуществляется по документу, указанному при покупке билета. Печать билета необязательна. Возврат — не позднее чем за 30 минут до отправления."
      />

      <!-- На телефоне три кнопки в ряд не помещались: подписи ломались на
           две-три строки, а последняя уезжала за край экрана. Ниже sm —
           столбиком во всю ширину, подписи в одну строку. -->
      <div class="grid gap-3 sm:flex sm:flex-wrap sm:justify-center">
        <UButton
          :to="`/print/${order.order_id}`"
          target="_blank"
          color="error"
          icon="i-lucide-printer"
          class="justify-center whitespace-nowrap"
        >
          Распечатать билеты
        </UButton>
        <UButton
          :href="pdfUrl"
          external
          download
          variant="outline"
          color="error"
          icon="i-lucide-file-down"
          class="justify-center whitespace-nowrap"
        >
          Скачать билеты в PDF
        </UButton>
        <UButton
          to="/" variant="outline" color="neutral" icon="i-lucide-house"
          class="justify-center whitespace-nowrap"
        >
          Вернуться на главную
        </UButton>
      </div>
    </template>
  </UContainer>
</template>
