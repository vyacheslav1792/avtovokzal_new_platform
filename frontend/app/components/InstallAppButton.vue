<script setup lang="ts">
/**
 * Пункт «Установить приложение» в мобильном меню.
 *
 * Виден ВСЕГДА, пока сайт открыт браузером: если браузер готов установить
 * приложение сам (Chrome на Android, Chrome/Edge на компьютере), пункт
 * открывает системное окно установки; если нет — показывает инструкцию,
 * как это сделать руками. Так и задумано: `beforeinstallprompt` не
 * приходит в Safari и во встроенных браузерах приложений, и без
 * инструкции покупатель просто не нашёл бы, где установка.
 *
 * Скрываем пункт в одном случае — когда приложение уже запущено с
 * домашнего экрана: предлагать установить то, что установлено, незачем.
 */
const { canInstall, install, installed } = useInstallPrompt()

const hintOpen = ref(false)
const standalone = ref(false)

onMounted(() => {
  // Проверяем только в браузере: при SSR режим отображения неизвестен, и
  // разметка сервера разошлась бы с клиентской.
  standalone.value = isStandaloneApp()
})

async function onClick() {
  // Браузер умеет ставить приложение сам — показываем системное окно.
  // Отказался покупатель или согласился, инструкцию ему подсовывать уже
  // не нужно: решение он принял.
  if (canInstall.value) {
    await install()
    return
  }

  hintOpen.value = true
}
</script>

<template>
  <template v-if="!standalone && !installed">
    <button
      type="button"
      class="w-full flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
      @click="onClick"
    >
      <UIcon name="i-lucide-smartphone" class="size-5 shrink-0 text-dimmed" />
      Установить приложение
    </button>

    <InstallAppDialog v-model:open="hintOpen" />
  </template>
</template>
