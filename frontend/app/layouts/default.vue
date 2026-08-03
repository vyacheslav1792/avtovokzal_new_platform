<script setup lang="ts">
interface NavLink {
  label: string
  to: string
  icon: string
  /** Префиксы путей, при которых пункт считается активным. */
  match?: string[]
}

const links: NavLink[] = [
  { label: 'Купить билеты', to: '/', icon: 'i-lucide-ticket', match: ['/trips', '/buy', '/payment'] },
  { label: 'Возврат билетов', to: '/backbycode', icon: 'i-lucide-undo-2' },
  { label: 'Расписание автобусов', to: '/raspisanie-avtobusov', icon: 'i-lucide-calendar-days', match: ['/tarif'] },
  { label: 'Контакты', to: '/contacts', icon: 'i-lucide-phone' },
]

const { user, isLoggedIn, logout } = useAuth()
const route = useRoute()

// Меню кабинета в шапке (десктоп).
const accountItems = computed(() => [[
  { label: 'Мои билеты', icon: 'i-lucide-ticket', to: '/lk' },
  { label: 'Профиль', icon: 'i-lucide-settings', to: '/lk/profile' },
], [
  { label: 'Выйти', icon: 'i-lucide-log-out', onSelect: signOut },
]])

// Мобильное меню: закрываем на любом переходе, иначе после клика по ссылке
// панель остаётся поверх новой страницы.
const menuOpen = ref(false)
watch(() => route.fullPath, () => {
  menuOpen.value = false
})

function isActive(link: NavLink): boolean {
  if (route.path === link.to) return true
  return (link.match ?? []).some(prefix => route.path === prefix || route.path.startsWith(`${prefix}/`))
}

async function signOut() {
  menuOpen.value = false
  await logout()
  await navigateTo('/')
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-default">
    <header class="bg-neutral-900 text-white sticky top-0 z-40">
      <UContainer class="flex items-center gap-3 sm:gap-6 py-3">
        <NuxtLink to="/" class="flex items-center gap-2 min-w-0">
          <img src="/img/logo.png" alt="Лого" class="h-8 sm:h-9 w-auto shrink-0">
          <span class="font-semibold truncate text-sm sm:text-base">АО «Орелавтотранс»</span>
        </NuxtLink>

        <nav class="hidden md:flex items-center gap-5 ml-auto text-sm">
          <NuxtLink
            v-for="link in links"
            :key="link.label"
            :to="link.to"
            class="transition-colors"
            :class="isActive(link) ? 'text-white' : 'text-neutral-300 hover:text-white'"
          >
            {{ link.label }}
          </NuxtLink>
        </nav>

        <div class="hidden md:flex items-center">
          <UDropdownMenu v-if="isLoggedIn" :items="accountItems">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-user"
              trailing-icon="i-lucide-chevron-down"
              class="text-neutral-200 hover:text-white"
            >
              <span class="max-w-40 truncate">{{ user?.email }}</span>
            </UButton>
          </UDropdownMenu>
          <UButton
            v-else
            to="/login"
            color="neutral"
            variant="ghost"
            icon="i-lucide-user"
            class="text-neutral-200 hover:text-white"
          >
            Личный кабинет
          </UButton>
        </div>

        <UButton
          class="md:hidden ml-auto shrink-0 text-neutral-200 hover:text-white"
          color="neutral"
          variant="ghost"
          size="xl"
          icon="i-lucide-menu"
          aria-label="Меню"
          :aria-expanded="menuOpen"
          @click="menuOpen = true"
        />
      </UContainer>
    </header>

    <!-- Мобильное меню -->
    <USlideover
      v-model:open="menuOpen"
      title="Меню"
      :ui="{ content: 'max-w-xs w-full', body: 'p-0 sm:p-0' }"
    >
      <template #body>
        <nav class="py-2">
          <NuxtLink
            v-for="link in links"
            :key="link.label"
            :to="link.to"
            class="flex items-center gap-3 px-4 py-3 text-base transition-colors"
            :class="isActive(link)
              ? 'text-primary font-medium bg-primary/10'
              : 'text-default hover:bg-elevated'"
          >
            <UIcon :name="link.icon" class="size-5 shrink-0 text-dimmed" />
            {{ link.label }}
          </NuxtLink>
        </nav>

        <USeparator />

        <div class="py-2">
          <template v-if="isLoggedIn">
            <div class="px-4 py-2 text-sm text-muted truncate">
              {{ user?.email }}
            </div>
            <NuxtLink
              to="/lk"
              class="flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
            >
              <UIcon name="i-lucide-ticket" class="size-5 shrink-0 text-dimmed" />
              Мои билеты
            </NuxtLink>
            <NuxtLink
              to="/lk/profile"
              class="flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
            >
              <UIcon name="i-lucide-settings" class="size-5 shrink-0 text-dimmed" />
              Профиль
            </NuxtLink>
            <button
              type="button"
              class="w-full flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
              @click="signOut"
            >
              <UIcon name="i-lucide-log-out" class="size-5 shrink-0 text-dimmed" />
              Выйти
            </button>
          </template>
          <template v-else>
            <div class="px-4 py-3 grid gap-2">
              <UButton to="/login" block icon="i-lucide-log-in" size="lg">
                Личный кабинет
              </UButton>
              <UButton to="/register" block color="neutral" variant="subtle" size="lg">
                Регистрация
              </UButton>
            </div>
          </template>
        </div>

        <USeparator />

        <div class="px-4 py-4 text-sm text-muted space-y-1">
          <a href="tel:+74862721111" class="flex items-center gap-3 text-default">
            <UIcon name="i-lucide-phone-call" class="size-5 shrink-0 text-dimmed" />
            8 (4862) 72-11-11
          </a>
          <a href="mailto:support@orelavtotrans.ru" class="flex items-center gap-3 text-default">
            <UIcon name="i-lucide-mail" class="size-5 shrink-0 text-dimmed" />
            support@orelavtotrans.ru
          </a>
        </div>
      </template>
    </USlideover>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="bg-neutral-900 text-neutral-400 mt-12">
      <UContainer class="py-8 grid gap-6 md:grid-cols-2">
        <div>
          <div class="text-white font-semibold mb-2">АО «Орелавтотранс»</div>
          <div class="text-sm">
            Официальный сайт автовокзалов Орла и Орловской области.<br>
            Покупка билетов онлайн без комиссии.
          </div>
        </div>
        <div class="md:text-right text-sm space-y-2">
          <div>
            <a href="mailto:support@orelavtotrans.ru" class="hover:text-white">support@orelavtotrans.ru</a>
            · 8 (4862) 72-11-11
          </div>
          <img src="/img/we-accept.png" alt="Мы принимаем к оплате Visa, Master Card" class="inline-block h-6">
          <div>© {{ new Date().getFullYear() }}</div>
        </div>
      </UContainer>
    </footer>
  </div>
</template>
