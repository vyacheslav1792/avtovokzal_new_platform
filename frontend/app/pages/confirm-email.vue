<script setup lang="ts">
/*
 * Старый адрес подтверждения e-mail по ссылке из письма. Теперь адрес
 * подтверждается кодом прямо в профиле (письма со ссылками уходили
 * в спам), поэтому страница лишь отправляет туда — ради писем,
 * отправленных до перехода.
 */
await navigateTo({ path: '/lk/profile', query: { 'add-email': '1' }, replace: true })
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <p class="text-muted">Переходим в профиль…</p>
  </UContainer>
</template>
