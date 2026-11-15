<script setup lang="ts">
/*
 * Старый адрес смены пароля по ссылке из письма. Писем со ссылками больше
 * не рассылаем (из-за них письма попадали в спам) — пароль меняется кодом
 * на /forgot. Страницу держим ради писем, отправленных до перехода: ссылка
 * из них должна приводить к рабочей форме, а не к 404.
 */
const route = useRoute()

const email = String(route.query.email ?? '')

await navigateTo({
  path: '/forgot',
  query: email ? { email } : undefined,
  replace: true,
})
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <p class="text-muted">Переходим к восстановлению пароля…</p>
  </UContainer>
</template>
