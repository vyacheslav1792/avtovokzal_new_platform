<script setup lang="ts">
/**
 * Как поставить сайт на телефон приложением.
 *
 * Окно показывается там, где браузер НЕ предлагает установку сам: в Safari
 * её вообще нет в виде системного окна, а во встроенных браузерах
 * (ВКонтакте, Telegram, Яндекс-браузер на части устройств, Firefox)
 * `beforeinstallprompt` не приходит. Самая частая причина «кнопка не
 * работает» — сайт открыт не в том браузере, поэтому с этого и начинаем.
 *
 * Инструкция показывается для обеих систем, но та, что подходит
 * устройству, стоит первой и раскрыта.
 */
const open = defineModel<boolean>('open', { default: false })

const ios = computed(() => isIosDevice())
</script>

<template>
  <UModal v-model:open="open" title="Как установить приложение">
    <template #body>
      <p class="text-muted mb-4">
        Приложение — это тот же сайт, но с иконкой на домашнем экране и без адресной
        строки. Купленные билеты в нём открываются без интернета.
      </p>

      <!-- Подходящая устройству инструкция стоит первой: список `order-*`
           переставляет секции, не дублируя разметку. -->
      <div class="flex flex-col gap-5">
        <section :class="ios ? 'order-1' : 'order-2'">
          <h3 class="font-medium mb-2">
            iPhone и iPad
          </h3>
          <ol class="space-y-1 list-decimal pl-5 text-sm">
            <li>Откройте сайт в браузере <b>Safari</b> — в других браузерах на iPhone установка недоступна.</li>
            <li>Нажмите кнопку «Поделиться» (квадрат со стрелкой вверх) внизу экрана.</li>
            <li>Выберите пункт «На экран „Домой“».</li>
            <li>Нажмите «Добавить».</li>
          </ol>
        </section>

        <section :class="ios ? 'order-2' : 'order-1'">
          <h3 class="font-medium mb-2">
            Android
          </h3>
          <ol class="space-y-1 list-decimal pl-5 text-sm">
            <li>Откройте сайт в браузере <b>Google Chrome</b> — во встроенных браузерах приложений установка не предлагается.</li>
            <li>Нажмите кнопку меню <b>⋮</b> в правом верхнем углу.</li>
            <li>Выберите «Установить приложение» (в некоторых версиях — «Добавить на главный экран»).</li>
            <li>Подтвердите установку.</li>
          </ol>
        </section>

        <section class="order-3">
          <h3 class="font-medium mb-2">
            Компьютер
          </h3>
          <p class="text-sm">
            В Chrome или Edge нажмите значок установки в правой части адресной строки —
            или меню <b>⋮</b> → «Установить».
          </p>
        </section>
      </div>
    </template>

    <template #footer>
      <UButton color="neutral" variant="outline" @click="open = false">
        Понятно
      </UButton>
    </template>
  </UModal>
</template>
