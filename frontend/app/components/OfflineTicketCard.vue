<script setup lang="ts">
/**
 * Купленный билет из офлайн-хранилища (страница `/tickets`).
 *
 * Состав полей — как в печатной форме (`/print/{id}`): на посадке
 * спрашивают рейс, место, фамилию и документ, а при возврате — код.
 * Разница в том, что эта карточка рисуется из данных на устройстве и
 * ничего не запрашивает по сети.
 */
import type { OfflineOrder, OfflineTicket } from '~/composables/useOfflineTickets'

const props = defineProps<{
  row: OfflineOrder
  /** Есть сеть: ссылка на печать ведёт на страницу, которой офлайн нет. */
  online: boolean
}>()

const emit = defineEmits<{ forget: [row: OfflineOrder] }>()

const order = computed(() => props.row.order)

const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']

/** «2026-08-03» → «3 августа 2026». */
function rusDate(iso: string | null): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)

  return `${d} ${MONTHS[(m ?? 1) - 1]} ${y}`
}

const savedAt = computed(() => new Date(props.row.saved_at).toLocaleString('ru-RU', {
  day: 'numeric',
  month: 'long',
  hour: '2-digit',
  minute: '2-digit',
}))

function money(value: number): string {
  return value.toFixed(2)
}

/** Квитанции багажа: по одной на место (как в печатной форме). */
function baggageUnits(ticket: OfflineTicket) {
  if (ticket.baggage_tickets.length) return ticket.baggage_tickets

  return Array.from({ length: ticket.baggage }, () => ({ serial: ticket.serial, number: ticket.number }))
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="text-lg font-medium">
            {{ order.from_name }} → {{ order.to_name }}
          </div>
          <div class="text-sm text-muted">
            Отправление {{ rusDate(order.date_start) }} в
            <b class="text-highlighted">{{ order.time_start }}</b>
          </div>
          <div v-if="order.date_end" class="text-sm text-muted">
            Прибытие {{ rusDate(order.date_end) }} в {{ order.time_end }}
          </div>
          <div class="text-sm text-muted">
            Рейс {{ order.trip_name }}<span v-if="order.carrier"> · {{ order.carrier }}</span>
          </div>
        </div>
        <div class="text-right">
          <div class="text-xl font-semibold">
            {{ money(order.total_price) }} ₽
          </div>
          <div class="text-xs text-muted">
            обновлено {{ savedAt }}
          </div>
        </div>
      </div>
    </template>

    <div class="divide-y divide-default">
      <div
        v-for="ticket in order.tickets"
        :key="ticket.number"
        class="py-3 first:pt-0 last:pb-0"
      >
        <div class="flex flex-wrap items-center gap-3">
          <UBadge color="neutral" variant="soft" size="lg">
            Место {{ ticket.place }}
          </UBadge>
          <div class="flex-1 min-w-48">
            <div class="font-medium">
              {{ ticket.passenger }}
            </div>
            <div class="text-sm text-muted">
              Билет серия {{ ticket.serial }} № {{ ticket.number }} · {{ ticket.tariff }}
            </div>
          </div>
          <div class="font-medium">
            {{ money(ticket.price + ticket.baggage * ticket.price_bag) }} ₽
          </div>
        </div>

        <div class="mt-1 text-sm text-muted">
          {{ ticket.doc_name }} {{ ticket.doc_number }} · {{ ticket.birth_date }} · {{ ticket.citizenship }}
        </div>

        <div v-if="ticket.baggage > 0" class="mt-1 text-sm text-muted">
          Багаж: {{ ticket.baggage }} мест. —
          <span v-for="(unit, i) in baggageUnits(ticket)" :key="unit.number + i">
            <template v-if="i"> · </template>
            квитанция {{ unit.serial }} № {{ unit.number }}
          </span>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="order.back_code" class="text-sm text-muted">
          Код для возврата: <b class="text-highlighted">{{ order.back_code }}</b>
        </span>
        <div class="flex flex-wrap items-center gap-2 ml-auto">
          <UButton
            v-if="online"
            :to="`/print/${row.order_id}`"
            target="_blank"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-printer"
          >
            Распечатать
          </UButton>
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-trash-2"
            @click="emit('forget', row)"
          >
            Убрать с устройства
          </UButton>
        </div>
      </div>
    </template>
  </UCard>
</template>
