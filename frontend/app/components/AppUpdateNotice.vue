<script setup lang="ts">
/**
 * «Сайт обновился» — уведомление для установленного приложения.
 *
 * Service worker кэширует скрипты, поэтому открытая вкладка может
 * оставаться на прошлой сборке сколько угодно долго (приложение с
 * домашнего экрана живёт неделями). Перезагружать страницу сами мы НЕ
 * имеем права: покупатель в этот момент может заполнять форму покупки —
 * предлагаем нажать кнопку.
 */
const { available, apply } = useAppUpdate()
const toast = useToast()

watch(available, (value) => {
  if (!value) return

  toast.add({
    title: 'Вышла новая версия сайта',
    description: 'Обновите страницу, чтобы её загрузить.',
    icon: 'i-lucide-download',
    color: 'primary',
    duration: 0,
    actions: [{ label: 'Обновить', color: 'primary', onClick: apply }],
  })
})
</script>

<template>
  <span class="hidden" />
</template>
