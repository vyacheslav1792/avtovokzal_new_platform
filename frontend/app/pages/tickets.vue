<script setup lang="ts">
/**
 * Купленные билеты, сохранённые на устройстве.
 *
 * Страница работает БЕЗ СЕТИ: данные читаются из IndexedDB
 * (`useOfflineTickets`), обязательных запросов здесь нет ни одного.
 * При наличии сети список молча обновляется — номера билетов, цена и
 * состав заказа могли поменяться (возврат, доплата багажа).
 *
 * `ssr: false` задан в `nuxt.config.ts`: оболочку страницы service worker
 * кладёт в кэш целиком, и она не зависит ни от backend, ни от того, кто
 * вошёл в кабинет.
 */
import type { OfflineOrder } from '~/composables/useOfflineTickets'

useSeoMeta({
  title: 'Мои билеты',
  description: 'Купленные билеты на этом устройстве — открываются без интернета',
})

const online = useOnline()
const { isLoggedIn } = useAuth()
const { sync } = useOfflineTickets()
const { canInstall, install, installed } = useInstallPrompt()

const rows = ref<OfflineOrder[]>([])
const loading = ref(true)
const syncing = ref(false)
const installHintOpen = ref(false)

/**
 * Приложение уже открыто с домашнего экрана — предлагать установку
 * незачем. Проверяем после монтирования: при SSR режим отображения
 * неизвестен (страница клиентская, но порядок тот же).
 */
const standalone = ref(false)

/** Предстоящие сверху: в дороге нужен ближайший рейс, а не архив. */
const upcoming = computed(() => rows.value.filter(row => !isDeparted(row.order)))
const departed = computed(() => rows.value.filter(row => isDeparted(row.order)))

async function load() {
  rows.value = await offlineOrders()
}

async function refresh() {
  if (!online.value || syncing.value) return

  syncing.value = true
  try {
    await sync({ withCabinet: isLoggedIn.value })
    await load()
  }
  finally {
    syncing.value = false
  }
}

onMounted(async () => {
  standalone.value = isStandaloneApp()

  await pruneOfflineOrders()
  await load()
  loading.value = false

  // Прогрев кэша нужен и тем, кто пришёл сюда сам: иначе без сети
  // откроется только то, что уже успело в кэш попасть.
  await warmOfflineShell()
  await refresh()
})

// Сеть появилась, пока покупатель смотрел билеты, — обновляем.
watch(online, (value) => {
  if (value) refresh()
})

async function forget(row: OfflineOrder) {
  await removeOfflineOrder(row.order_id)
  await load()
}

async function installApp() {
  if (canInstall.value) {
    await install()
    return
  }
  // Системного окна установки нет (Safari, встроенные браузеры) —
  // показываем инструкцию для iPhone и для Android.
  installHintOpen.value = true
}
</script>

<template>
  <UContainer class="py-8 max-w-3xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-3xl font-light">
          Мои билеты
        </h1>
        <p class="text-sm text-muted mt-1">
          Сохранены на этом устройстве и открываются без интернета
        </p>
      </div>
      <UButton
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        :loading="syncing"
        :disabled="!online"
        @click="refresh"
      >
        Обновить
      </UButton>
    </div>

    <UAlert
      v-if="!online"
      color="warning"
      variant="subtle"
      icon="i-lucide-wifi-off"
      class="mt-4"
      title="Нет подключения к интернету"
      description="Показаны билеты, сохранённые на устройстве. Покупка и возврат станут доступны, когда появится сеть."
    />

    <UAlert
      v-if="!standalone && !installed"
      color="primary"
      variant="subtle"
      icon="i-lucide-smartphone"
      class="mt-4"
      title="Установите приложение"
      description="Билеты будут открываться с домашнего экрана — даже там, где нет связи."
      :actions="[{ label: 'Установить', color: 'primary', onClick: installApp }]"
    />

    <div v-if="loading" class="py-16 text-center text-muted">
      Открываем сохранённые билеты…
    </div>

    <UCard v-else-if="!rows.length" class="mt-6">
      <div class="py-8 text-center space-y-4">
        <UIcon name="i-lucide-ticket" class="size-10 text-muted mx-auto" />
        <p>
          Здесь появятся купленные билеты — сразу после покупки, автоматически.
          Их видно и без интернета.
        </p>
        <div class="grid gap-2 sm:flex sm:justify-center">
          <UButton to="/" color="error" icon="i-lucide-ticket">
            Купить билет
          </UButton>
          <UButton v-if="!isLoggedIn" to="/login" color="neutral" variant="outline" icon="i-lucide-user">
            Войти в кабинет
          </UButton>
        </div>
        <p v-if="isLoggedIn && online" class="text-sm text-muted">
          Билеты предстоящих поездок подгружаются из кабинета сами —
          нажмите «Обновить», если список пуст.
        </p>
      </div>
    </UCard>

    <template v-else>
      <div v-if="upcoming.length" class="mt-6 space-y-5">
        <OfflineTicketCard
          v-for="row in upcoming"
          :key="row.order_id"
          :row="row"
          :online="online"
          @forget="forget"
        />
      </div>

      <template v-if="departed.length">
        <h2 class="text-lg font-medium text-muted mt-8 mb-3">
          Прошедшие поездки
        </h2>
        <div class="space-y-5">
          <OfflineTicketCard
            v-for="row in departed"
            :key="row.order_id"
            :row="row"
            :online="online"
            @forget="forget"
          />
        </div>
      </template>

      <p class="mt-8 text-sm text-muted">
        Билеты хранятся только на этом устройстве и удаляются сами через сутки после
        отправления рейса. Посадка в автобус — по документу, указанному при покупке;
        печатать билет не обязательно.
      </p>
    </template>

    <InstallAppDialog v-model:open="installHintOpen" />
  </UContainer>
</template>
