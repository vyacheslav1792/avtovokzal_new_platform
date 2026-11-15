<script setup lang="ts">
/**
 * Пункт мобильного меню «Версия для слабовидящих».
 *
 * В шапке на эту кнопку остался только значок (подпись не помещалась в ряд
 * с меню), а ГОСТ Р 52872-2019 требует, чтобы переход на альтернативную
 * версию был понятен и доступен с любой страницы — поэтому здесь он
 * подписан словами. Когда режим уже включён, пункт не нужен: его место
 * занимает панель настроек над шапкой.
 */
const { available, enabled, enable } = useAccessibility()
</script>

<template>
  <button
    v-if="available && !enabled"
    type="button"
    class="flex w-full items-center gap-3 px-4 py-3 text-base text-default
           hover:bg-elevated transition-colors"
    @click="enable()"
  >
    <UIcon name="i-lucide-eye" class="size-5 shrink-0 text-dimmed" />
    Для слабовидящих
  </button>
</template>
