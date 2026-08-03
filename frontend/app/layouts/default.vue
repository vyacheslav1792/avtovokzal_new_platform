<script setup lang="ts">
const links = [
  { label: 'Купить билеты', to: '/' },
  { label: 'Возврат билетов', to: '/backbycode' },
  { label: 'Расписание автобусов', to: '/raspisanie-avtobusov' },
  { label: 'Контакты', to: '/' },
]

const { user, isLoggedIn, logout } = useAuth()

// Меню кабинета в шапке.
const accountItems = computed(() => [[
  { label: 'Мои билеты', icon: 'i-lucide-ticket', to: '/lk' },
  { label: 'Профиль', icon: 'i-lucide-settings', to: '/lk/profile' },
], [
  { label: 'Выйти', icon: 'i-lucide-log-out', onSelect: signOut },
]])

async function signOut() {
  await logout()
  await navigateTo('/')
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-default">
    <header class="bg-neutral-900 text-white">
      <UContainer class="flex items-center gap-6 py-3">
        <NuxtLink to="/" class="flex items-center gap-2 shrink-0">
          <img src="/img/logo.png" alt="Лого" class="h-9 w-auto">
          <span class="font-semibold whitespace-nowrap">АО «Орелавтотранс»</span>
        </NuxtLink>
        <nav class="hidden md:flex items-center gap-5 ml-auto text-sm">
          <NuxtLink
            v-for="link in links"
            :key="link.label"
            :to="link.to"
            class="text-neutral-300 hover:text-white transition-colors"
          >
            {{ link.label }}
          </NuxtLink>
        </nav>

        <div class="ml-auto md:ml-0 flex items-center">
          <UDropdownMenu v-if="isLoggedIn" :items="accountItems">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-user"
              trailing-icon="i-lucide-chevron-down"
              class="text-neutral-200 hover:text-white"
            >
              <span class="hidden sm:inline max-w-40 truncate">{{ user?.email }}</span>
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
      </UContainer>
    </header>

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
