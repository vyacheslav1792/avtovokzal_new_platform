<script setup lang="ts">
/**
 * Страница ошибки сайта. До неё фронт её не имел вовсе: Nuxt показывал
 * свою служебную — с кодом, стектрейсом в разработке и без единой ссылки,
 * куда идти дальше. Для 503 это выглядело как «сайт сломался», хотя идёт
 * плановое обслуживание.
 *
 * Три случая, которые надо различать:
 *   503 — обслуживание или выключенный backend: сайт вернётся сам;
 *   404 — страницы нет: уводим на главную и в расписание;
 *   прочее — неполадка: предлагаем обновить и даём телефон справочной.
 *
 * Сохранённые билеты (PWA, IndexedDB) доступны в любом из этих случаев —
 * ссылка на них есть всегда: в автобусе билет нужен независимо от того,
 * что с сайтом.
 */
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()

const status = computed(() => Number(props.error?.statusCode) || 500)
const maintenance = computed(() => status.value === 503)
const notFound = computed(() => status.value === 404)

/** Текст обслуживания приходит от backend; иначе — свой. */
const message = computed(() => {
  const data = props.error?.data as any

  if (typeof data === 'string') {
    try {
      return JSON.parse(data)?.error || ''
    }
    catch {
      return ''
    }
  }

  return data?.error || ''
})

const title = computed(() => {
  if (maintenance.value) return 'Техническое обслуживание'
  if (notFound.value) return 'Страница не найдена'

  return 'Что-то пошло не так'
})

useSeoMeta({
  title: () => title.value,
  // Страницу ошибки поисковику индексировать незачем.
  robots: 'noindex',
})

function retry() {
  if (import.meta.client) window.location.reload()
}
</script>

<template>
  <UContainer class="py-16 max-w-xl text-center">
    <UIcon
      :name="maintenance ? 'i-lucide-wrench' : (notFound ? 'i-lucide-map-pin-off' : 'i-lucide-triangle-alert')"
      class="size-12 text-primary mx-auto mb-5"
    />

    <h1 class="text-2xl md:text-3xl font-light mb-3">
      {{ title }}
    </h1>

    <p v-if="maintenance" class="text-muted mb-8">
      {{ message || 'Сайт на техническом обслуживании. Покупка билетов будет доступна через несколько минут.' }}
    </p>
    <p v-else-if="notFound" class="text-muted mb-8">
      Такой страницы на сайте нет. Возможно, ссылка устарела или в адресе опечатка.
    </p>
    <p v-else class="text-muted mb-8">
      Сайт не смог получить данные. Попробуйте обновить страницу — если не поможет,
      билеты продаются в кассах автовокзалов.
    </p>

    <div class="grid gap-2 sm:flex sm:justify-center sm:flex-wrap">
      <UButton v-if="!notFound" color="error" icon="i-lucide-refresh-cw" @click="retry">
        Обновить страницу
      </UButton>
      <UButton to="/" color="neutral" variant="outline" icon="i-lucide-house">
        На главную
      </UButton>
      <UButton to="/raspisanie-avtobusov" color="neutral" variant="outline" icon="i-lucide-calendar-days">
        Расписание
      </UButton>
      <!-- Купленные билеты лежат на устройстве и открываются без сети -->
      <UButton to="/tickets" color="neutral" variant="ghost" icon="i-lucide-ticket">
        Мои билеты
      </UButton>
      <UButton to="/contacts" color="neutral" variant="ghost" icon="i-lucide-phone">
        Контакты
      </UButton>
    </div>
  </UContainer>
</template>
