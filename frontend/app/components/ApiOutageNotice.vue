<script setup lang="ts">
/**
 * Полоса «сайт на обслуживании» / «сервис недоступен».
 *
 * Показывается, когда backend ответил 503 на обслуживании или не ответил
 * вовсе. Без неё страница просто оставалась с пустыми полями: покупатель
 * нажимал «Найти билеты» и ничего не происходило.
 *
 * Полоса сверху, а не модальное окно: читать уже загруженные страницы
 * (контакты, расписание из кэша, сохранённые билеты) это не мешает.
 */
const outage = useApiOutage()

const titles: Record<string, string> = {
  maintenance: 'Техническое обслуживание',
  down: 'Сервис временно недоступен',
}

function retry() {
  // Перезагружаем страницу целиком: сайт мог не получить ни станций, ни
  // расписания — точечно обновлять нечего.
  if (import.meta.client) window.location.reload()
}
</script>

<template>
  <UAlert
    v-if="outage"
    :color="outage.kind === 'maintenance' ? 'warning' : 'error'"
    variant="subtle"
    :icon="outage.kind === 'maintenance' ? 'i-lucide-wrench' : 'i-lucide-triangle-alert'"
    :title="titles[outage.kind]"
    :description="outage.message"
    class="rounded-none no-print"
  >
    <template #actions>
      <UButton color="neutral" variant="outline" size="sm" @click="retry">
        Обновить страницу
      </UButton>
      <UButton to="/tickets" color="neutral" variant="ghost" size="sm" icon="i-lucide-ticket">
        Мои билеты на устройстве
      </UButton>
    </template>
  </UAlert>
</template>
