<script setup lang="ts">
/**
 * Кнопка «Назад», закреплённая вверху слева.
 *
 * Это НАСТОЯЩИЙ переход назад по истории, а не ссылка на главную: со
 * страницы рейсов надо возвращаться туда, откуда пришли — в расписание, в
 * тариф, на главную, — и с теми же параметрами поиска. Они живут в адресе
 * страницы, поэтому `router.back()` восстанавливает форму сам; ссылка на
 * `/` сбрасывала и маршрут, и дату.
 *
 * `window.history.state.back` появляется только после перехода ВНУТРИ
 * приложения. При заходе по прямой ссылке (из письма, поиска, новой
 * вкладки) возвращаться некуда — тогда уходим на `fallback`.
 *
 * Полоса `sticky` стоит под шапкой сайта (она сама `sticky top-0`,
 * высота 64px = `top-16`), поэтому кнопка видна и после прокрутки длинного
 * расписания.
 */
const props = withDefaults(defineProps<{
  /** Куда уйти, если истории нет (прямой заход по ссылке). */
  fallback?: string
  label?: string
}>(), {
  fallback: '/',
  label: 'Назад',
})

const router = useRouter()

function goBack() {
  if (import.meta.client && window.history.state?.back) router.back()
  else router.push(props.fallback)
}
</script>

<template>
  <div class="sticky top-16 z-30 -mt-2 mb-1 py-2 bg-default no-print">
    <UButton
      icon="i-lucide-arrow-left"
      variant="ghost"
      color="neutral"
      size="sm"
      @click="goBack"
    >
      {{ label }}
    </UButton>
  </div>
</template>
