<script setup lang="ts">
/**
 * Ввод кода подтверждения из письма (6 цифр): регистрация, восстановление
 * пароля, подтверждение e-mail в профиле.
 *
 * Отсчёты берутся из ответа сервера (`resend_after`, `expires_in`), а не
 * из своих констант: лимиты живут в backend (config/site.php →
 * auth_code), и расходиться им нельзя — иначе кнопка «Отправить снова»
 * включалась бы раньше, чем сервер готов принять запрос.
 *
 * В слот по умолчанию страница кладёт свои поля — на восстановлении
 * пароля это новый пароль; тогда `auto-submit` выключают, иначе форма
 * уходила бы на сервер по шестой цифре, до ввода пароля.
 */
import type { AuthCodeState } from '~/composables/useAuth'

const props = withDefaults(defineProps<{
  /** Адрес, на который ушёл код (показываем покупателю). */
  email: string
  /** Состояние кода от сервера: отсчёты и остатки попыток. */
  state: AuthCodeState | null
  loading?: boolean
  /** Ошибка от сервера (неверный код, лимиты). */
  error?: string
  /** Отправлять по шестой цифре. Выключается, если в слоте есть поля. */
  autoSubmit?: boolean
  submitLabel?: string
  /** Пояснение под заголовком. */
  hint?: string
  /** Подпись ссылки возврата к предыдущему шагу. */
  backLabel?: string
}>(), {
  autoSubmit: true,
  submitLabel: 'Подтвердить',
  backLabel: 'Указать другой адрес',
})

const emit = defineEmits<{
  submit: [code: string]
  resend: []
  back: []
}>()

const CODE_LENGTH = 6

const digits = ref<number[]>([])
const code = computed(() => digits.value.join(''))

/** Секунды до повторной отправки и до конца жизни кода — тикают локально. */
const resendIn = ref(0)
const expiresIn = ref(0)

let timer: ReturnType<typeof setInterval> | null = null

function syncFromState() {
  resendIn.value = props.state?.resend_after ?? 0
  expiresIn.value = props.state?.expires_in ?? 0
}

onMounted(() => {
  syncFromState()
  timer = setInterval(() => {
    if (resendIn.value > 0) resendIn.value--
    if (expiresIn.value > 0) expiresIn.value--
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

// Новый код — новые отсчёты (и очищенное поле: прежние цифры уже не годятся).
watch(() => props.state, (value, previous) => {
  syncFromState()
  if (value && previous && value.resend_after > previous.resend_after) {
    digits.value = []
  }
}, { deep: true })

function submit() {
  if (code.value.length < CODE_LENGTH) return
  emit('submit', code.value)
}

function onComplete() {
  if (props.autoSubmit) submit()
}

/** «2:30» — остаток времени в привычном виде. */
function clock(seconds: number): string {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60

  return `${m}:${String(s).padStart(2, '0')}`
}

const resendLabel = computed(() =>
  resendIn.value > 0
    ? `Отправить код снова (через ${clock(resendIn.value)})`
    : 'Отправить код снова')

/** Сколько попыток осталось — показываем, только когда их уже мало. */
const attemptsNote = computed(() => {
  const left = props.state?.attempts_left
  if (left === undefined || left <= 0 || left > 3) return ''

  const word = left === 1 ? 'попытка' : 'попытки'

  return `Осталось ${left} ${word} ввода кода — потом понадобится новый.`
})
</script>

<template>
  <div class="space-y-4">
    <UAlert v-if="error" color="error" variant="subtle" :title="error" />

    <p class="text-sm">
      Код из 6 цифр отправлен на <b class="text-highlighted">{{ email }}</b>.
      <template v-if="hint"> {{ hint }}</template>
    </p>

    <form class="space-y-4" @submit.prevent="submit">
      <UFormField label="Код из письма" required>
        <!-- `otp` подставляет autocomplete="one-time-code": на телефоне
             код предлагается прямо над клавиатурой, без перехода в почту. -->
        <UPinInput
          v-model="digits"
          :length="CODE_LENGTH"
          type="number"
          otp
          autofocus
          size="xl"
          :disabled="loading"
          @complete="onComplete"
        />
      </UFormField>

      <slot />

      <div class="flex flex-wrap items-center gap-3">
        <UButton
          type="submit" color="error" :loading="loading"
          :disabled="code.length < CODE_LENGTH"
        >
          {{ submitLabel }}
        </UButton>
        <UButton
          color="neutral" variant="outline"
          :disabled="resendIn > 0 || loading"
          @click="emit('resend')"
        >
          {{ resendLabel }}
        </UButton>
      </div>
    </form>

    <div class="text-sm text-muted space-y-1">
      <p v-if="expiresIn > 0">Код действует ещё {{ clock(expiresIn) }}.</p>
      <p v-else>Срок действия кода истёк — запросите новый.</p>
      <p v-if="attemptsNote" class="text-warning">{{ attemptsNote }}</p>
      <p>
        Письма нет? Проверьте папку «Спам».
        На Gmail загляните также в «Промоакции» и «Оповещения».
      </p>
    </div>

    <UButton color="neutral" variant="link" class="px-0" @click="emit('back')">
      {{ backLabel }}
    </UButton>
  </div>
</template>
