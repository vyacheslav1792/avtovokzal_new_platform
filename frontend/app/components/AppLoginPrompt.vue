<script setup lang="ts">
/**
 * Предложение войти в кабинет на стартовом экране приложения.
 *
 * Два слоя, и это сознательно:
 * - **карточка на экране** — висит постоянно, пока покупатель не вошёл;
 *   она ничего не перекрывает и её можно спокойно игнорировать;
 * - **окно при запуске** — показывается не чаще раза в неделю (отметка в
 *   `localStorage`). Кабинет нужен, чтобы билеты находились с любого
 *   устройства и возврат шёл без кода, но покупать можно и гостем:
 *   окно при каждом запуске превратилось бы в препятствие.
 *
 * Без сети не показываем ничего: войти всё равно не выйдет, а место на
 * экране займёт — вместо билетов, за которыми приложение и открыли.
 */
const PROMPT_KEY = 'login-prompt-shown-at'
const PROMPT_INTERVAL_MS = 7 * 24 * 60 * 60 * 1000

const online = useOnline()
const { isLoggedIn } = useAuth()
// Кнопку VK показываем только при заведённом приложении VK — как на
// страницах входа и регистрации (на локальном контуре его нет).
const { data: providers } = useAuthProviders()

const dialogOpen = ref(false)

/** Адрес возврата: после входа покупатель остаётся на стартовом экране. */
const redirect = '/app'

function shownRecently(): boolean {
  try {
    const at = Number(localStorage.getItem(PROMPT_KEY) ?? 0)

    return Number.isFinite(at) && Date.now() - at < PROMPT_INTERVAL_MS
  }
  catch {
    // Хранилище запрещено — молчим, навязываться вслепую не станем.
    return true
  }
}

onMounted(() => {
  if (isLoggedIn.value || !navigator.onLine || shownRecently()) return

  dialogOpen.value = true
  try {
    localStorage.setItem(PROMPT_KEY, String(Date.now()))
  }
  catch {
    // Не записалось — в этой сессии окно уже показано, и ладно.
  }
})

// Вошёл (в том числе в соседней вкладке) — окно больше не нужно.
watch(isLoggedIn, (value) => {
  if (value) dialogOpen.value = false
})
</script>

<template>
  <div v-if="!isLoggedIn && online">
    <div class="rounded-lg border border-default bg-elevated/50 p-4 flex flex-wrap items-center gap-3">
      <UIcon name="i-lucide-user-round" class="size-6 text-primary shrink-0" />
      <div class="flex-1 min-w-48 text-sm">
        <div class="font-medium text-highlighted">
          Войдите в личный кабинет
        </div>
        <div class="text-muted">
          Билеты будут под рукой с любого устройства, а возврат — без кода.
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <UButton :to="{ path: '/login', query: { redirect } }" color="error" size="sm">
          Войти
        </UButton>
        <UButton
          :to="{ path: '/register', query: { redirect } }"
          color="neutral"
          variant="outline"
          size="sm"
        >
          Регистрация
        </UButton>
      </div>
    </div>

    <UModal v-model:open="dialogOpen" title="Вход в личный кабинет" :ui="MOBILE_FULLSCREEN_MODAL">
      <template #body>
        <p>
          С кабинетом купленные билеты видны с любого устройства, возврат делается
          без кода из письма, а данные пассажиров подставляются в форму покупки.
        </p>
        <p class="mt-3 text-muted">
          Покупать можно и без кабинета — билеты всё равно придут на почту и
          сохранятся в этом приложении.
        </p>

        <!-- VK первым пунктом: вход в один тап (на телефоне ссылку
             подхватывает приложение VK), без пароля и кода из письма. -->
        <div class="mt-5 grid gap-2">
          <VkLoginButton v-if="providers?.vk" :redirect="redirect" />
          <UButton :to="{ path: '/login', query: { redirect } }" color="error" block>
            Войти
          </UButton>
          <UButton
            :to="{ path: '/register', query: { redirect } }"
            color="neutral"
            variant="outline"
            block
          >
            Зарегистрироваться
          </UButton>
        </div>
      </template>

      <template #footer>
        <UButton color="neutral" variant="ghost" block @click="dialogOpen = false">
          Позже
        </UButton>
      </template>
    </UModal>
  </div>
</template>
