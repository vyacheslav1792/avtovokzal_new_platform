<script setup lang="ts">
/**
 * Выбор станции («Откуда» / «Куда») с полноэкранным списком на мобильном.
 *
 * Выпадающий список USelectMenu на телефоне открывается небольшим окошком:
 * поле поиска видно не всегда, а пунктов назначения у станции бывает больше
 * двух сотен — приходится листать весь список. Поэтому на узких экранах
 * список раскрывается на весь экран: сверху закреплены стрелка «назад» и
 * поле поиска, прокручивается только сам список. На десктопе остаётся
 * обычный USelectMenu.
 *
 * Обе версии рендерятся всегда и переключаются классами (`md:hidden` /
 * `hidden md:flex`), а не проверкой ширины в JS: при SSR ширина неизвестна,
 * и разметка сервера разошлась бы с клиентской.
 */
interface Option { label: string, value: string | number }

const props = withDefaults(defineProps<{
  items: Option[]
  placeholder?: string
  /** Заголовок полноэкранного списка (по умолчанию — placeholder). */
  title?: string
  searchPlaceholder?: string
  loading?: boolean
  disabled?: boolean
  highlight?: boolean
  size?: 'md' | 'lg' | 'xl'
}>(), {
  placeholder: 'Выберите',
  searchPlaceholder: 'Начните вводить название',
  // Как и у остальных полей сайта (см. app.config.ts).
  size: 'xl',
})

const model = defineModel<string | number | null>()

const open = ref(false)
const query = ref('')
const searchInput = useTemplateRef<{ inputRef: HTMLInputElement | null }>('searchInput')

const selectedLabel = computed(() =>
  props.items.find(i => i.value === model.value)?.label ?? '')

/**
 * Фильтр и порядок — как у Nuxt UI на десктопе: совпадения с начала названия
 * поднимаются наверх, внутри групп сохраняется порядок сервера (он уже
 * отсортирован по-человечески — см. StationNameSorter в sales-api).
 */
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.items

  const starts: Option[] = []
  const contains: Option[] = []

  for (const item of props.items) {
    const label = item.label.toLowerCase()
    if (label.startsWith(q)) starts.push(item)
    else if (label.includes(q)) contains.push(item)
  }

  return [...starts, ...contains]
})

function openPanel() {
  if (props.disabled) return
  query.value = ''
  open.value = true
}

function closePanel() {
  open.value = false
}

function pick(value: string | number) {
  model.value = value
  closePanel()
}

// Полноэкранный список подменяет собой страницу — фон под ним скроллиться
// не должен, иначе после закрытия покупатель оказывается не там, где был.
watch(open, async (value) => {
  if (!import.meta.client) return
  document.body.style.overflow = value ? 'hidden' : ''
  if (value) {
    await nextTick()
    searchInput.value?.inputRef?.focus()
  }
})

onBeforeUnmount(() => {
  if (import.meta.client) document.body.style.overflow = ''
})

// Переход по ссылке (выбор станции меняет адрес страницы) — закрываем список.
const route = useRoute()
watch(() => route.fullPath, closePanel)
</script>

<template>
  <div class="w-full">
    <!-- Десктоп: обычный выпадающий список -->
    <USelectMenu
      v-model="model"
      :items="items"
      value-key="value"
      :placeholder="placeholder"
      :loading="loading"
      :disabled="disabled"
      :highlight="highlight"
      :size="size"
      class="hidden md:flex w-full"
    />

    <!-- Мобильный: поле-кнопка, список открывается на весь экран -->
    <button
      type="button"
      :disabled="disabled"
      :class="[
        'md:hidden w-full flex items-center justify-between gap-2 rounded-md bg-default',
        'ring ring-inset transition-colors disabled:cursor-not-allowed disabled:opacity-75',
        highlight ? 'ring-error' : 'ring-accented',
        // Отступы и шрифт — те же, что у UInput соответствующего размера,
        // иначе поле-кнопка на телефоне выбивалось бы из ряда.
        size === 'md' ? 'px-2.5 py-1.5 text-sm' : 'px-3 py-2 text-base',
      ]"
      @click="openPanel"
    >
      <span :class="selectedLabel ? 'text-default truncate' : 'text-dimmed truncate'">
        {{ selectedLabel || placeholder }}
      </span>
      <UIcon
        :name="loading ? 'i-lucide-loader-circle' : 'i-lucide-chevron-down'"
        :class="['size-5 shrink-0 text-dimmed', loading && 'animate-spin']"
      />
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        class="md:hidden fixed inset-0 z-50 flex flex-col bg-default"
        role="dialog"
        @keydown.esc="closePanel"
      >
        <!-- Закреплённая шапка: «назад» и поиск -->
        <div class="shrink-0 border-b border-default bg-default px-3 pt-3 pb-2 space-y-2">
          <div class="flex items-center gap-2">
            <UButton
              icon="i-lucide-arrow-left"
              color="neutral"
              variant="ghost"
             
              aria-label="Назад"
              @click="closePanel"
            />
            <span class="font-medium truncate">{{ title || placeholder }}</span>
          </div>
          <UInput
            ref="searchInput"
            v-model="query"
            :placeholder="searchPlaceholder"
            icon="i-lucide-search"
           
            class="w-full"
            autocomplete="off"
            :ui="{ trailing: 'pe-1' }"
          >
            <template v-if="query" #trailing>
              <UButton
                icon="i-lucide-x"
                color="neutral"
                variant="link"
                aria-label="Очистить"
                @click="query = ''"
              />
            </template>
          </UInput>
        </div>

        <!-- Прокручивается только список -->
        <div class="flex-1 overflow-y-auto overscroll-contain">
          <div v-if="loading" class="p-6 text-center text-muted">
            <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin" />
          </div>
          <p v-else-if="!filtered.length" class="p-6 text-center text-muted">
            Ничего не найдено
          </p>
          <button
            v-for="item in filtered"
            :key="item.value"
            type="button"
            class="w-full text-left px-4 py-3 border-b border-default last:border-b-0 flex items-center justify-between gap-2"
            :class="item.value === model ? 'text-primary font-medium' : 'text-default'"
            @click="pick(item.value)"
          >
            <span>{{ item.label }}</span>
            <UIcon v-if="item.value === model" name="i-lucide-check" class="size-5 shrink-0" />
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
