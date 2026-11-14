<script setup lang="ts">
/**
 * Кнопка включения версии для людей с нарушениями зрения.
 *
 * Стоит в шапке на каждой странице публичной части — так требует
 * ГОСТ Р 52872-2019 (ссылка на альтернативную версию должна быть доступна
 * с любой страницы и в начале документа). Сам режим и панель настроек —
 * `useAccessibility.ts` и `AccessibilityPanel.vue`.
 *
 * Выключается целиком переменной окружения: `ACCESSIBILITY_VERSION=0`
 * в корневом `.env` (`available`), кнопки тогда просто нет.
 */
const { available, enabled, enable } = useAccessibility()

/** Подпись прячем на узком экране — там в шапке только логотип и меню. */
const props = defineProps<{ label?: boolean }>()
</script>

<template>
  <UButton
    v-if="available && !enabled"
    color="neutral"
    variant="ghost"
    icon="i-lucide-eye"
    class="text-neutral-200 hover:text-white whitespace-nowrap"
    aria-label="Версия сайта для слабовидящих"
    title="Версия сайта для слабовидящих"
    @click="enable()"
  >
    <span v-if="props.label">Для слабовидящих</span>
  </UButton>
</template>
