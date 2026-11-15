<script setup lang="ts">
/**
 * Стартовый экран приложения (`start_url` манифеста).
 *
 * Главная страница сайта для этого не годится: она про ОДНО действие —
 * поиск рейса, и половину экрана занимает картинка с формой. Приложение
 * же открывают и ради билета в дороге, и ради телефона автовокзала, и
 * ради объявлений — поэтому здесь крупные плитки разделов, а поиск
 * билетов стал одной из них.
 *
 * Экран обязан открываться БЕЗ СЕТИ (`ssr: false` в `nuxt.config.ts`,
 * оболочку кэширует service worker): без интернета с него доступны
 * сохранённые билеты и контакты, а разделы, которым нужна сеть, честно
 * помечены — иначе покупатель тыкал бы в плитку и получал «нет
 * подключения».
 */
useSeoMeta({
  title: 'Автовокзал57',
  description: 'Билеты на автобус, сохранённые билеты, расписание и контакты автовокзалов Орловской области',
})

const online = useOnline()
const standalone = useStandalone()
const { isLoggedIn, user } = useAuth()
const { canInstall, install, installed } = useInstallPrompt()

const installHintOpen = ref(false)
const ticketCount = ref(0)

onMounted(async () => {
  ticketCount.value = await offlineOrdersCount()
  // Экран — точка входа в приложение, и именно отсюда чаще всего идут к
  // билетам: держим их оболочку в кэше наготове.
  await warmOfflineShell()
})

interface Tile {
  title: string
  description: string
  icon: string
  to: string
  /** Раздел живыми данными: без сети открывать его нечем. */
  needsNetwork: boolean
  badge?: string
}

const tiles = computed<Tile[]>(() => [
  {
    title: 'Новости',
    description: 'Объявления автовокзалов',
    icon: 'i-lucide-newspaper',
    to: '/news',
    needsNetwork: true,
  },
  {
    title: 'Покупка билета',
    description: 'Поиск рейсов и оплата',
    icon: 'i-lucide-ticket',
    to: '/',
    needsNetwork: true,
  },
  {
    title: 'Мои билеты',
    description: 'Открываются без интернета',
    icon: 'i-lucide-ticket-check',
    to: '/tickets',
    needsNetwork: false,
    badge: ticketCount.value ? String(ticketCount.value) : undefined,
  },
  {
    title: 'Контакты',
    description: 'Адреса, телефоны, время работы',
    icon: 'i-lucide-phone',
    to: '/contacts',
    needsNetwork: false,
  },
])

function unavailable(tile: Tile): boolean {
  return tile.needsNetwork && !online.value
}

async function installApp() {
  if (canInstall.value) {
    await install()
    return
  }
  installHintOpen.value = true
}
</script>

<template>
  <UContainer class="py-8 max-w-3xl">
    <div class="flex items-center gap-3 mb-6">
      <!-- Логотип сайта — белый силуэт на прозрачном фоне: на светлой
           странице его просто не видно, поэтому здесь иконка приложения. -->
      <img src="/icons/icon-192.png" alt="" class="size-11 rounded-lg shrink-0">
      <div>
        <h1 class="text-2xl font-light">
          Автовокзал57
        </h1>
        <p v-if="isLoggedIn" class="text-sm text-muted">
          Вы вошли как {{ userLabel(user) }}
        </p>
        <p v-else class="text-sm text-muted">
          Автовокзалы Орла и Орловской области
        </p>
      </div>
    </div>

    <UAlert
      v-if="!online"
      color="warning"
      variant="subtle"
      icon="i-lucide-wifi-off"
      class="mb-5"
      title="Нет подключения к интернету"
      description="Доступны сохранённые билеты и контакты. Покупка и новости появятся, когда будет связь."
    />

    <!-- Объявление для тех, кто поставил сайт на домашний экран -->
    <SiteBlock
      v-if="online"
      name="app_top"
      class="mb-5 rounded-lg border border-default bg-elevated/50 p-4"
    />

    <!-- Предложение войти: карточка на экране + окно не чаще раза в неделю. -->
    <AppLoginPrompt class="mb-5" />

    <div class="grid grid-cols-2 gap-3 sm:gap-4">
      <!-- Недоступная без сети плитка остаётся ссылкой, но клик по ней
           гасится: подменять тег на `div` значило бы рисовать плитку
           дважды, а `pointer-events: none` отнимает её и у чтения с
           экрана. -->
      <NuxtLink
        v-for="tile in tiles"
        :key="tile.to"
        :to="tile.to"
        :aria-disabled="unavailable(tile) || undefined"
        class="rounded-lg border border-default bg-elevated/50 p-4 sm:p-5 flex flex-col gap-2 transition-colors"
        :class="unavailable(tile)
          ? 'opacity-50 cursor-not-allowed'
          : 'hover:bg-elevated hover:border-primary'"
        @click="unavailable(tile) && $event.preventDefault()"
      >
        <div class="flex items-start justify-between gap-2">
          <UIcon :name="tile.icon" class="size-8 text-primary shrink-0" />
          <UBadge v-if="tile.badge" color="primary" variant="solid" size="sm">
            {{ tile.badge }}
          </UBadge>
        </div>
        <div>
          <div class="font-medium text-highlighted">
            {{ tile.title }}
          </div>
          <div class="text-sm text-muted">
            {{ unavailable(tile) ? 'Нужен интернет' : tile.description }}
          </div>
        </div>
      </NuxtLink>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-2">
      <UButton v-if="isLoggedIn" to="/lk" color="neutral" variant="outline" icon="i-lucide-user">
        Личный кабинет
      </UButton>
      <UButton to="/raspisanie-avtobusov" color="neutral" variant="outline" icon="i-lucide-calendar-days">
        Расписание автобусов
      </UButton>
      <UButton to="/backbycode" color="neutral" variant="outline" icon="i-lucide-undo-2">
        Возврат билета
      </UButton>
    </div>

    <!-- Сайт открыт браузером: предлагаем поставить приложение. -->
    <UAlert
      v-if="!standalone && !installed"
      color="primary"
      variant="subtle"
      icon="i-lucide-smartphone"
      class="mt-6"
      title="Установите приложение"
      description="Этот экран будет открываться с домашнего экрана, а купленные билеты — даже без связи."
      :actions="[{ label: 'Установить', color: 'primary', onClick: installApp }]"
    />

    <InstallAppDialog v-model:open="installHintOpen" />
  </UContainer>
</template>
