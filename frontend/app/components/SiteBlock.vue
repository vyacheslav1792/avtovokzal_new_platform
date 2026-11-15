<script setup lang="ts">
/**
 * Блок сайта в отведённом месте страницы: объявление, приветствие, баннер.
 * Содержимое правит сотрудник в АИС (раздел «Блоки сайта»), набор мест
 * задан в backend (config/site_blocks.php).
 *
 * Пустого места после себя не оставляет: нет блока — нет и разметки, иначе
 * выключенное объявление оставляло бы на странице дыру из отступов.
 */
const props = defineProps<{
  /** Ключ слота: home_welcome, schedule_top, … */
  name: string
  /** Классы обёртки — отступы задаёт страница, а не блок. */
  class?: string
}>()

const { data: blocks } = await useSiteBlocks()

const html = computed(() => blocks.value?.[props.name] || '')
</script>

<template>
  <div v-if="html" class="site-html" :class="props.class" v-html="html" />
</template>
