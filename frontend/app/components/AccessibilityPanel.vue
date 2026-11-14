<script setup lang="ts">
/**
 * Панель настроек версии для людей с нарушениями зрения.
 *
 * Состав настроек — обязательный набор ГОСТ Р 52872-2019 (см. подробности
 * в `useAccessibility.ts`): размер шрифта, цветовая схема, изображения,
 * межбуквенный и межстрочный интервал, шрифт с засечками, возврат к
 * обычной версии.
 *
 * Разметка сознательно на нативных `<button>`, а не на `UButton`: панель
 * видна ТОЛЬКО в специальном режиме, где тема Nuxt UI всё равно обнулена, а
 * `aria-pressed` на родных кнопках и озвучивается скринридером, и служит
 * зацепкой для стилей выбранного значения (`accessibility.css`).
 */
const { settings, apply, disable, reset } = useAccessibility()

/*
 * Панель по умолчанию СВЁРНУТА: включение режима не должно отодвигать
 * содержимое страницы вниз экраном настроек — человек пришёл читать сайт, а
 * не настраивать его. Настройки открываются кнопкой и остаются открытыми при
 * переходах между страницами (`useState` — одно состояние и при SSR, и
 * после гидрации).
 */
const open = useState('a11y-panel-open', () => false)

/*
 * Образец размера — буква «А» трёх кеглей. Шаги взяты НЕ подряд от самого
 * крупного: при `text-3xl` на экране в 320px буква вылезала за рамку своей
 * кнопки (в режиме у текста свой межстрочный интервал, и строка становилась
 * выше кнопки). Разницу между тремя шагами видно и так.
 */
const FONTS: { value: A11yStep, label: string, size: string }[] = [
  { value: 1, label: 'Обычный размер шрифта', size: 'text-base' },
  { value: 2, label: 'Крупный шрифт', size: 'text-xl' },
  { value: 3, label: 'Очень крупный шрифт', size: 'text-2xl' },
]

const LETTERS: { value: A11yStep, label: string }[] = [
  { value: 1, label: 'Стандартный' },
  { value: 2, label: 'Средний' },
  { value: 3, label: 'Большой' },
]

const LINES: { value: A11yStep, label: string }[] = [
  { value: 1, label: 'Одинарный' },
  { value: 2, label: 'Полуторный' },
  { value: 3, label: 'Двойной' },
]

const BUTTON = 'px-3 py-1.5 border-2 cursor-pointer leading-none'
</script>

<template>
  <section
    class="a11y-panel w-full"
    aria-label="Настройки версии для слабовидящих"
  >
    <UContainer class="py-3">
      <div class="flex flex-wrap items-center gap-3">
        <strong class="mr-auto">Версия для слабовидящих</strong>

        <button
          type="button"
          :class="BUTTON"
          :aria-expanded="open"
          aria-controls="a11y-settings"
          @click="open = !open"
        >
          {{ open ? 'Свернуть настройки' : 'Настройки отображения' }}
        </button>

        <button type="button" :class="BUTTON" @click="disable()">
          Обычная версия сайта
        </button>
      </div>

      <div v-show="open" id="a11y-settings" class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <!-- Размер шрифта -->
        <div role="group" aria-labelledby="a11y-font-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-font-label" class="w-full md:w-auto md:mr-1">Размер шрифта:</span>
          <button
            v-for="f in FONTS"
            :key="f.value"
            type="button"
            :class="[BUTTON, f.size]"
            :aria-pressed="settings.font === f.value"
            :aria-label="f.label"
            :title="f.label"
            @click="apply({ font: f.value })"
          >
            А
          </button>
        </div>

        <!-- Цветовая схема -->
        <div role="group" aria-labelledby="a11y-scheme-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-scheme-label" class="w-full md:w-auto md:mr-1">Цвета сайта:</span>
          <button
            v-for="s in A11Y_SCHEMES"
            :key="s.value"
            type="button"
            :class="[BUTTON, 'flex items-center gap-2']"
            :aria-pressed="settings.scheme === s.value"
            :aria-label="s.label"
            :title="s.label"
            @click="apply({ scheme: s.value })"
          >
            <!-- Кружок-образец: единственное место режима, где цвет значим
                 сам по себе, поэтому у него свои стили в accessibility.css. -->
            <span
              class="a11y-swatch inline-flex items-center justify-center size-6 rounded-full font-bold"
              :style="{ '--a11y-swatch-bg': s.bg, '--a11y-swatch-fg': s.fg }"
              aria-hidden="true"
            >А</span>
          </button>
        </div>

        <!-- Изображения -->
        <div role="group" aria-labelledby="a11y-img-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-img-label" class="w-full md:w-auto md:mr-1">Изображения:</span>
          <button
            type="button"
            :class="BUTTON"
            :aria-pressed="settings.images"
            @click="apply({ images: true })"
          >
            Включить
          </button>
          <button
            type="button"
            :class="BUTTON"
            :aria-pressed="!settings.images"
            @click="apply({ images: false })"
          >
            Выключить
          </button>
        </div>

        <!-- Межбуквенный интервал (кернинг) -->
        <div role="group" aria-labelledby="a11y-letter-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-letter-label" class="w-full md:w-auto md:mr-1">Кернинг:</span>
          <button
            v-for="l in LETTERS"
            :key="l.value"
            type="button"
            :class="BUTTON"
            :aria-pressed="settings.letter === l.value"
            @click="apply({ letter: l.value })"
          >
            {{ l.label }}
          </button>
        </div>

        <!-- Межстрочный интервал -->
        <div role="group" aria-labelledby="a11y-line-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-line-label" class="w-full md:w-auto md:mr-1">Интервал:</span>
          <button
            v-for="l in LINES"
            :key="l.value"
            type="button"
            :class="BUTTON"
            :aria-pressed="settings.line === l.value"
            @click="apply({ line: l.value })"
          >
            {{ l.label }}
          </button>
        </div>

        <!-- Начертание -->
        <div role="group" aria-labelledby="a11y-serif-label" class="flex flex-wrap items-center gap-2">
          <span id="a11y-serif-label" class="w-full md:w-auto md:mr-1">Шрифт:</span>
          <button
            type="button"
            :class="BUTTON"
            :aria-pressed="!settings.serif"
            @click="apply({ serif: false })"
          >
            Без засечек
          </button>
          <button
            type="button"
            :class="BUTTON"
            :aria-pressed="settings.serif"
            @click="apply({ serif: true })"
          >
            С засечками
          </button>
        </div>

        <div class="md:col-span-2 xl:col-span-3">
          <button type="button" :class="BUTTON" @click="reset()">
            Настройки по умолчанию
          </button>
        </div>
      </div>
    </UContainer>
  </section>
</template>
