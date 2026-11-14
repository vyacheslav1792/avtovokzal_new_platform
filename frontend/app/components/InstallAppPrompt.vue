<script setup lang="ts">
/**
 * Предложение поставить сайт приложением — полоса внизу экрана.
 *
 * Показывается тем, кто открыл сайт в обычном браузере, и **не чаще раза
 * в неделю**: предложение, мелькающее при каждом заходе, раздражает и
 * перестаёт читаться. Отметка о показе лежит в `localStorage`, поэтому
 * переживает перезапуск браузера.
 *
 * Чего избегаем сознательно:
 * - страниц покупки и оплаты (`/buy`, `/payment/*`, `/print/*`) — там
 *   перекрывать экран нельзя ни при каких обстоятельствах;
 * - первых секунд на странице: предложение всплывает, когда покупатель
 *   уже осмотрелся, а не поверх загрузки;
 * - соседства с напоминанием о незавершённой покупке — то важнее, там
 *   чужие деньги; обе полосы живут внизу и перекрыли бы друг друга.
 */
const PROMPT_KEY = 'install-prompt-shown-at'

/** Как часто можно напоминать об установке. */
const PROMPT_INTERVAL_MS = 7 * 24 * 60 * 60 * 1000

/** Сколько ждать после открытия страницы. */
const PROMPT_DELAY_MS = 8000

const route = useRoute()
const { canInstall, install, installed } = useInstallPrompt()
const pendingNotice = usePendingNotice()

const visible = ref(false)
const hintOpen = ref(false)

/** На этих страницах не показываем вообще. */
const silent = computed(() => route.path === '/buy'
  || route.path.startsWith('/payment/')
  || route.path.startsWith('/print/')
  // На странице билетов предложение и так стоит отдельной карточкой.
  || route.path === '/tickets')

function shownRecently(): boolean {
  try {
    const at = Number(localStorage.getItem(PROMPT_KEY) ?? 0)

    return Number.isFinite(at) && Date.now() - at < PROMPT_INTERVAL_MS
  }
  catch {
    // Хранилище может быть запрещено — тогда просто не навязываемся.
    return true
  }
}

function remember() {
  try {
    localStorage.setItem(PROMPT_KEY, String(Date.now()))
  }
  catch {
    // Не записалось — ничего страшного, в этой сессии полосу уже закрыли.
  }
}

onMounted(() => {
  if (isStandaloneApp() || installed.value || shownRecently()) return

  const timer = setTimeout(() => {
    // Условия проверяем ещё раз: за эти секунды покупатель мог уйти на
    // страницу покупки или получить напоминание о незавершённом заказе.
    if (silent.value || pendingNotice.value || isStandaloneApp() || installed.value) return

    visible.value = true
    remember()
  }, PROMPT_DELAY_MS)

  onBeforeUnmount(() => clearTimeout(timer))
})

// Напоминание о покупке важнее: уступаем ему место.
watch(pendingNotice, (value) => {
  if (value) visible.value = false
})

watch(silent, (value) => {
  if (value) visible.value = false
})

async function onInstall() {
  visible.value = false

  if (canInstall.value) {
    await install()
    return
  }

  // Системного окна нет (Safari, встроенные браузеры) — показываем,
  // как поставить приложение руками.
  hintOpen.value = true
}
</script>

<template>
  <div>
    <Transition
      enter-from-class="translate-y-full"
      enter-active-class="transition-transform duration-300"
      leave-to-class="translate-y-full"
      leave-active-class="transition-transform duration-200"
    >
      <div
        v-if="visible"
        class="fixed inset-x-0 bottom-0 z-50 bg-default border-t border-default shadow-lg print:hidden"
      >
        <UContainer class="py-3 flex flex-wrap items-center gap-3">
          <UIcon name="i-lucide-smartphone" class="size-6 shrink-0 text-primary" />
          <div class="flex-1 min-w-48 text-sm">
            <div class="font-medium text-highlighted">
              Установите приложение «Автовокзал57»
            </div>
            <div class="text-muted">
              Купленные билеты будут открываться с домашнего экрана даже без интернета.
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <UButton color="error" size="sm" icon="i-lucide-download" @click="onInstall">
              Установить
            </UButton>
            <UButton color="neutral" variant="ghost" size="sm" @click="visible = false">
              Не сейчас
            </UButton>
          </div>
        </UContainer>
      </div>
    </Transition>

    <InstallAppDialog v-model:open="hintOpen" />
  </div>
</template>
