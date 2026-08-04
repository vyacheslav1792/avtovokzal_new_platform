<script setup lang="ts">
import type { CabinetOrder, CabinetTicket } from '~/composables/useCabinet'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ title: 'Личный кабинет' })

const api = useApi()
const config = useRuntimeConfig()
const { user } = useAuth()

const scope = ref<'all' | 'upcoming' | 'past'>('all')
const page = ref(1)

const { data, pending, refresh } = await useAsyncData(
  'cabinet-orders',
  () => api<{
    success: boolean
    data: CabinetOrder[]
    meta: { current_page: number, last_page: number, total: number, orders_total: number, upcoming_total: number }
  }>('/cabinet/orders', { query: { scope: scope.value, page: page.value } }),
  { watch: [scope, page] },
)

const orders = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)

const scopeItems = [
  { label: 'Все', value: 'all' },
  { label: 'Предстоящие', value: 'upcoming' },
  { label: 'Прошедшие', value: 'past' },
]

function pdfUrl(orderId: string) {
  return `${config.public.apiBase}/api/v1/orders/${orderId}/pdf`
}

const {
  dialogOpen, step, preview, result, loading, errorMessage,
  startRefund, confirmRefund, closeDialog,
} = useTicketRefund(() => refresh())

function formatDate(iso: string) {
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

function statusColor(order: CabinetOrder) {
  if (order.status === 'completed') return order.refunded_total > 0 ? 'warning' : 'success'
  if (order.status === 'failed') return 'error'
  return 'neutral'
}

function ticketTotal(t: CabinetTicket) {
  return t.total.toFixed(2)
}
</script>

<template>
  <UContainer class="py-8 max-w-5xl">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
      <div>
        <h1 class="text-3xl font-light">Личный кабинет</h1>
        <p class="text-sm text-muted mt-1">{{ userLabel(user) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <UButton to="/" color="error" icon="i-lucide-ticket">Купить билет</UButton>
        <UButton to="/lk/profile" color="neutral" variant="outline" icon="i-lucide-settings">
          Профиль
        </UButton>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 mb-6">
      <USelect v-model="scope" :items="scopeItems" value-key="value" class="w-48" />
      <div v-if="meta" class="text-sm text-muted">
        Оплаченных заказов: <b class="text-highlighted">{{ meta.orders_total }}</b>,
        из них предстоящих поездок: <b class="text-highlighted">{{ meta.upcoming_total }}</b>
      </div>
    </div>

    <div v-if="pending && !orders.length" class="py-16 text-center text-muted">
      Загружаем ваши заказы…
    </div>

    <UCard v-else-if="!orders.length">
      <div class="py-8 text-center space-y-4">
        <UIcon name="i-lucide-ticket" class="size-10 text-muted mx-auto" />
        <p>
          {{ scope === 'all' ? 'Вы ещё не приобрели билетов.' : 'В этом разделе поездок нет.' }}
        </p>
        <UButton to="/" color="error">Заказать билет</UButton>
      </div>
    </UCard>

    <div v-else class="space-y-5">
      <UCard v-for="order in orders" :key="order.id">
        <template #header>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div class="text-lg font-medium">
                {{ order.from_name }} → {{ order.to_name }}
              </div>
              <div class="text-sm text-muted">
                Отправление {{ formatDate(order.date_start) }} в {{ order.time_start }}
                <span v-if="order.trip_name"> · рейс {{ order.trip_name }}</span>
              </div>
              <div class="text-sm text-muted">
                Куплен {{ order.created_at }}<span v-if="order.carrier"> · перевозчик {{ order.carrier }}</span>
              </div>
            </div>
            <div class="text-right space-y-2">
              <UBadge :color="statusColor(order)" variant="subtle">{{ order.status_label }}</UBadge>
              <div class="text-xl font-semibold">{{ order.total_price.toFixed(2) }} ₽</div>
              <div v-if="order.refunded_total > 0" class="text-sm text-warning">
                возвращено {{ order.refunded_total.toFixed(2) }} ₽
              </div>
            </div>
          </div>
        </template>

        <div v-if="order.tickets.length" class="space-y-3">
          <div
            v-for="ticket in order.tickets"
            :key="ticket.id"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-default last:border-0 pb-3 last:pb-0"
          >
            <div class="min-w-56">
              <div class="font-medium">{{ ticket.passenger }}</div>
              <div class="text-sm text-muted">
                Место {{ ticket.place }} · {{ ticket.tariff }}
                <span v-if="ticket.baggage"> · багаж {{ ticket.baggage }} мест.</span>
              </div>
              <div class="text-sm text-muted">
                Билет № {{ ticket.serial }} {{ ticket.number }}
              </div>
            </div>

            <div class="text-right">
              <div class="font-medium">{{ ticketTotal(ticket) }} ₽</div>
              <div v-if="ticket.refund" class="text-sm text-warning">
                Возвращено {{ ticket.refund.amount.toFixed(2) }} ₽
                <span class="text-muted">({{ ticket.refund.created_at }})</span>
                <div v-if="!ticket.refund.money_refunded" class="text-xs text-error">
                  Деньги вернёт бухгалтерия
                </div>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <UButton
                v-if="ticket.can_refund"
                color="error"
                variant="outline"
                size="sm"
                icon="i-lucide-undo-2"
                @click="startRefund(order, ticket)"
              >
                Вернуть
              </UButton>
            </div>
          </div>
        </div>

        <div v-else class="text-sm text-muted">
          <template v-if="order.payment_url">
            Заказ не оплачен. Места забронированы на несколько минут — оплату можно продолжить.
          </template>
          <template v-else-if="order.status === 'failed'">
            Заказ не оформлен. Если деньги были списаны, обратитесь на автовокзал —
            платёж вернут.
          </template>
          <template v-else>
            Билеты по этому заказу не оформлены.
          </template>
        </div>

        <template #footer>
          <div class="flex flex-wrap items-center gap-2">
            <template v-if="order.status === 'completed'">
              <UButton
                :to="`/print/${order.id}`" target="_blank"
                color="neutral" variant="outline" size="sm" icon="i-lucide-printer"
              >
                Распечатать
              </UButton>
              <UButton
                :to="pdfUrl(order.id)" target="_blank" external
                color="neutral" variant="outline" size="sm" icon="i-lucide-download"
              >
                Скачать PDF
              </UButton>
              <span v-if="order.back_code" class="text-xs text-muted ml-auto">
                Код для возврата: {{ order.back_code }}
              </span>
            </template>
            <UButton
              v-else-if="order.payment_url"
              :to="order.payment_url" external
              color="error" size="sm" icon="i-lucide-credit-card"
            >
              Продолжить оплату
            </UButton>
          </div>
        </template>
      </UCard>

      <div v-if="meta && meta.last_page > 1" class="flex justify-center pt-2">
        <UPagination
          v-model:page="page"
          :total="meta.total"
          :items-per-page="20"
        />
      </div>
    </div>

    <div class="mt-10 text-sm text-muted space-y-2">
      <p>
        Возврат билетов через личный кабинет возможен не позднее чем за 30 минут до отправления
        автобуса. Позднее, а также после отправления — по заявлению на автовокзале отправителя.
      </p>
      <p>Деньги на карту зачисляются банком с задержкой 1–7 дней.</p>
    </div>

    <!-- Возврат билета: те же правила, что и при возврате по коду -->
    <UModal v-model:open="dialogOpen" title="Возврат билета">
      <template #body>
        <div v-if="errorMessage" class="mb-4">
          <UAlert color="error" variant="subtle" :title="errorMessage" />
        </div>

        <div v-if="step === 'loading'" class="py-6 text-center text-muted">
          Рассчитываем условия возврата…
        </div>

        <div v-else-if="step === 'preview' && preview" class="space-y-3">
          <p>
            Билет от <b>{{ preview.from_name }} до {{ preview.to_name }}</b>
            на рейс: {{ preview.trip_name }}, место {{ preview.place }}
          </p>
          <p>Отправление: <b>{{ formatDate(preview.date_start) }} {{ preview.time_start }}</b></p>
          <p>будет возвращён на следующих условиях:</p>
          <p>Процент удержаний: <b>{{ preview.penalty_percent }}%</b></p>
          <p>Возвращаемая сумма: <b>{{ preview.amount.toFixed(2) }}</b> руб.</p>
          <UAlert
            color="warning"
            variant="subtle"
            title="Денежные средства возвращаются банком в срок от 3 до 7 дней, в некоторых случаях банк-эмитент может увеличить этот срок до 30 дней."
          />
        </div>

        <div v-else-if="step === 'done' && result" class="space-y-3">
          <UAlert
            color="success"
            variant="subtle"
            :title="`Билет ${result.from_name} — ${result.to_name} возвращён`"
            :description="`Отправление ${formatDate(result.date_start)} ${result.time_start}`"
          />
          <p>Сумма: <b>{{ result.amount.toFixed(2) }}</b> руб.</p>
          <p><b>Внимание!</b> Деньги на карту зачисляются банком с задержкой 1–7 дней.</p>
          <UAlert v-if="result.bank_error" color="warning" variant="subtle" :title="result.bank_error" />
        </div>
      </template>

      <template #footer>
        <div class="flex gap-3">
          <template v-if="step === 'preview'">
            <UButton color="error" :loading="loading" @click="confirmRefund">
              Выполнить возврат
            </UButton>
            <UButton color="neutral" variant="outline" :disabled="loading" @click="closeDialog">
              Не возвращать
            </UButton>
          </template>
          <UButton v-else color="neutral" variant="outline" @click="closeDialog">Закрыть</UButton>
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
