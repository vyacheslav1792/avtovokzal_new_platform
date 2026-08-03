<script setup lang="ts">
const links = [
  { label: 'Купить билеты', to: '/' },
  { label: 'Расписание автобусов', to: '/' },
  { label: 'Контакты', to: '/' },
]
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
