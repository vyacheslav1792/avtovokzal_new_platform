<script setup lang="ts">
/**
 * Напоминание о незавершённой покупке — показывается на любой странице сайта
 * (подключено в layouts/default.vue).
 *
 * Два случая, и они требуют разного тона:
 *
 * - **оплачен, но не оформлен** — деньги списаны, билетов нет. Это окно,
 *   которое нельзя не заметить: покупатель должен довести оформление до
 *   конца, иначе он никуда не поедет. Скрыть его насовсем нельзя — закрытое,
 *   оно вернётся на следующей странице, пока покупка не завершится;
 * - **не оплачен** — полоса внизу экрана с обратным отсчётом брони: места
 *   держатся ограниченное время, и это главное, что нужно знать покупателю.
 *
 * В legacy оба случая были одинаковым белым прямоугольником поверх вёрстки,
 * без сведений о рейсе и без запаса времени.
 */
const route = useRoute()
const api = useApi()
const toast = useToast()
const { items, load } = usePendingOrders()
const { isLoggedIn } = useAuth()

/** Отменённые отсюда заказы — чтобы полоса пропала сразу, без перезапроса. */
const dismissed = ref<string[]>([])

const pending = computed(() =>
  items.value.filter(o => !dismissed.value.includes(o.order_id)))

/** Оплаченный без билетов важнее неоплаченного: там уже чужие деньги. */
const paid = computed(() => pending.value.find(o => o.state === 'awaiting_confirmation') ?? null)
const unpaid = computed(() => pending.value.find(o => o.state === 'awaiting_payment') ?? null)

/**
 * На странице возврата со шлюза напоминать не о чем: она прямо сейчас
 * оформляет эту же покупку. Печатная форма уходит в PDF — там посторонним
 * блокам тоже не место.
 */
const silent = computed(() =>
  route.path.startsWith('/payment/')
  || route.path.startsWith('/print/')
  // На форме покупки о том же заказе говорит её собственная полоса
  // «Заказ создан и ждёт оплаты» — две одинаковые подсказки разом только
  // мешают, а полоса внизу ещё и перекрывает окно оплаты.
  || route.path === '/buy')

const paidOpen = ref(false)
watch(paid, value => (paidOpen.value = !!value))

/*
 * Сообщаем остальным, что низ экрана занят: предложение установить
 * приложение внизу той же страницы уступит это место (см.
 * `InstallAppPrompt`).
 */
const pendingNotice = usePendingNotice()
watchEffect(() => {
  pendingNotice.value = !silent.value && !!(paid.value || unpaid.value)
})
onBeforeUnmount(() => {
  pendingNotice.value = false
})

onMounted(load)
// Вход в кабинет открывает заказы, сделанные с другого устройства.
watch(isLoggedIn, () => load())
// Переход по сайту — повод перепроверить: покупку могли завершить в
// соседней вкладке, а бронь — истечь.
watch(() => route.path, () => load())

/** Оставшееся время брони, пересчитывается раз в секунду. */
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  ticker = setInterval(() => (now.value = Date.now()), 1000)
})
onBeforeUnmount(() => clearInterval(ticker))

const timeLeft = computed(() => {
  if (!unpaid.value?.pay_until) return ''
  const left = new Date(unpaid.value.pay_until).getTime() - now.value
  if (left <= 0) return ''
  const total = Math.floor(left / 1000)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
})

// Бронь истекла прямо на глазах — убираем полосу, платить уже нечего.
watch(timeLeft, (value) => {
  if (!value && unpaid.value) forgetPendingOrder(unpaid.value.order_id)
})

function describe(order: { from_name: string, to_name: string, date_start: string, time_start: string }) {
  const d = new Date(order.date_start)
  const date = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
  return `${order.from_name} → ${order.to_name}, ${date} в ${order.time_start}`
}

/** Довести оформление до конца — на той же странице, что и возврат со шлюза. */
function finish(sberOrderId: string) {
  paidOpen.value = false
  navigateTo({ path: '/payment/result', query: { orderId: sberOrderId } })
}

/**
 * Отказ от неоплаченного заказа. Полосу недостаточно просто спрятать: места
 * останутся занятыми до конца брони, хотя покупатель уже передумал, — поэтому
 * кнопка снимает бронь на сервере и отменяет платёж.
 */
const cancelling = ref(false)

async function cancel(id: string) {
  cancelling.value = true
  try {
    await api(`/orders/${id}/cancel`, { method: 'POST' })
    toast.add({
      title: 'Заказ отменён',
      description: 'Бронь мест снята — их снова можно купить.',
      color: 'success',
    })
  }
  catch (e: any) {
    // Не вышло — бронь всё равно истечёт сама, покупателя этим не грузим.
    toast.add({
      title: 'Не удалось отменить заказ',
      description: apiErrorMessage(e, 'Бронь снимется сама по истечении времени'),
      color: 'error',
    })
  }
  finally {
    cancelling.value = false
    dismissed.value = [...dismissed.value, id]
    forgetPendingOrder(id)
  }
}
</script>

<template>
  <div v-if="!silent">
    <!-- Деньги списаны, билетов нет: окно, которое нельзя пропустить -->
    <UModal
      v-if="paid"
      v-model:open="paidOpen"
      title="Покупка не завершена"
      :dismissible="false"
      :ui="MOBILE_FULLSCREEN_MODAL"
    >
      <template #body>
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Билеты оплачены, но ещё не оформлены"
          description="После оплаты вы не вернулись на сайт, и оформление осталось незаконченным. С таким заказом в автобус не посадят — завершите покупку, билеты придут на почту."
          class="mb-4"
        />

        <div class="rounded-lg border border-default p-3 text-sm space-y-1">
          <div class="font-medium text-base">{{ describe(paid) }}</div>
          <div v-if="paid.trip_name" class="text-muted">Рейс {{ paid.trip_name }}</div>
          <div class="text-muted">
            Мест: {{ paid.places.length }} · Оплачено: <b class="text-highlighted">{{ paid.total_price.toFixed(2) }} ₽</b>
          </div>
        </div>

        <p class="text-sm text-muted mt-3">
          Если оформить не удастся, деньги вернутся — напишите нам, и мы разберёмся с покупкой.
        </p>
      </template>

      <template #footer>
        <UButton
          color="neutral" variant="ghost" :class="MODAL_BUTTON_CLASS"
          @click="paidOpen = false"
        >
          Позже
        </UButton>
        <UButton
          color="error" icon="i-lucide-ticket-check" :class="MODAL_BUTTON_CLASS"
          @click="finish(paid!.sber_order_id)"
        >
          Завершить оформление
        </UButton>
      </template>
    </UModal>

    <!-- Не оплачен: места ещё держатся, но недолго -->
    <div
      v-if="unpaid && timeLeft"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-default bg-default shadow-[0_-4px_16px_rgba(0,0,0,0.12)]"
    >
      <UContainer class="py-3 flex flex-wrap items-center gap-x-4 gap-y-2">
        <UIcon name="i-lucide-clock" class="size-6 text-error shrink-0" />
        <div class="min-w-50 flex-1">
          <div class="font-medium">Незавершённая покупка — билеты не оплачены</div>
          <div class="text-sm text-muted">
            {{ describe(unpaid) }} · {{ unpaid.total_price.toFixed(2) }} ₽ ·
            места держим ещё <b class="text-highlighted tabular-nums">{{ timeLeft }}</b>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <UButton
            :href="unpaid.payment_url ?? undefined"
            external
            color="error"
            icon="i-lucide-credit-card"
          >
            Продолжить оплату
          </UButton>
          <UButton
            color="neutral" variant="ghost" :loading="cancelling"
            @click="cancel(unpaid!.order_id)"
          >
            Отменить
          </UButton>
        </div>
      </UContainer>
    </div>
  </div>
</template>
