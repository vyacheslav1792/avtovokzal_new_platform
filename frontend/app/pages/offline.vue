<script setup lang="ts">
/**
 * Страница «нет сети»: её отдаёт service worker, когда без интернета
 * открывают адрес, которого нет в кэше (поиск рейсов, кабинет, оплата).
 *
 * Главное на ней — дорога к сохранённым билетам: ради них покупатель и
 * открывает приложение в автобусе.
 *
 * `ssr: false` (см. `nuxt.config.ts`) — оболочка должна кэшироваться
 * целиком и не зависеть от backend, который в этот момент недоступен.
 */
useSeoMeta({ title: 'Нет подключения к интернету' })

const online = useOnline()
const ticketCount = ref(0)

onMounted(async () => {
  ticketCount.value = await offlineOrdersCount()
})

function retry() {
  window.location.reload()
}
</script>

<template>
  <UContainer class="py-16 max-w-xl text-center space-y-6">
    <UIcon name="i-lucide-wifi-off" class="size-12 text-muted mx-auto" />

    <div class="space-y-2">
      <h1 class="text-2xl font-light">
        Нет подключения к интернету
      </h1>
      <p class="text-muted">
        Покупка билетов, расписание и кабинет станут доступны, как только появится
        сеть. Купленные билеты можно открыть и сейчас — они сохранены на устройстве.
      </p>
    </div>

    <div class="grid gap-2 sm:flex sm:justify-center">
      <UButton to="/tickets" color="error" icon="i-lucide-ticket">
        Мои билеты<span v-if="ticketCount"> ({{ ticketCount }})</span>
      </UButton>
      <UButton color="neutral" variant="outline" icon="i-lucide-refresh-cw" @click="retry">
        Повторить
      </UButton>
    </div>

    <p v-if="online" class="text-sm text-muted">
      Связь восстановилась — нажмите «Повторить».
    </p>
  </UContainer>
</template>
