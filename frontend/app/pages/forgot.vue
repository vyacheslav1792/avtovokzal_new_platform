<script setup lang="ts">
import type { AuthCodeState } from '~/composables/useAuth'

/*
 * Восстановление пароля в два шага: код из письма и сразу новый пароль.
 * Раньше письмо несло ссылку на /reset — но письма со ссылками чаще
 * уходят в спам, поэтому теперь приходит код из 6 цифр.
 */
const { requestPasswordReset, resendCode, resetPassword, isLoggedIn } = useAuth()
const route = useRoute()

useSeoMeta({
  title: 'Восстановление пароля',
  description: 'Сброс пароля от личного кабинета Орловского автовокзала.',
})

const email = ref(String(route.query.email ?? ''))
const password = ref('')
const passwordRepeat = ref('')
const loading = ref(false)
const errorMessage = ref('')
const codeError = ref('')

const step = ref<'email' | 'code'>('email')
const codeState = ref<AuthCodeState | null>(null)

watchEffect(() => {
  if (isLoggedIn.value) navigateTo('/lk')
})

async function requestCode() {
  errorMessage.value = ''
  loading.value = true
  try {
    codeState.value = await requestPasswordReset(email.value)
    codeError.value = ''
    step.value = 'code'
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e)
  }
  finally {
    loading.value = false
  }
}

async function resend() {
  codeError.value = ''
  loading.value = true
  try {
    codeState.value = await resendCode('password_reset', email.value)
  }
  catch (e: any) {
    codeError.value = apiErrorMessage(e, 'Не удалось отправить код, попробуйте позже')
  }
  finally {
    loading.value = false
  }
}

async function submitNewPassword(code: string) {
  codeError.value = ''

  if (password.value.trim() !== passwordRepeat.value.trim()) {
    codeError.value = 'Пароль и подтверждение должны совпадать'
    return
  }

  loading.value = true
  try {
    await resetPassword(email.value, code, password.value)
    await navigateTo('/lk')
  }
  catch (e: any) {
    codeError.value = apiErrorMessage(e, 'Не удалось сменить пароль, попробуйте позже')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <h1 class="text-3xl font-light mb-6">Восстановление пароля</h1>

    <UAlert v-if="errorMessage" color="error" variant="subtle" :title="errorMessage" class="mb-4" />

    <UCard v-if="step === 'email'">
      <form class="space-y-4" @submit.prevent="requestCode">
        <UFormField
          label="Электронная почта" required
          help="Адрес, на который покупали билеты — на него придёт код"
        >
          <UInput v-model="email" type="email" autocomplete="email" class="w-full" />
        </UFormField>
        <div class="flex flex-wrap items-center gap-3">
          <UButton type="submit" color="error" :loading="loading">Получить код</UButton>
          <UButton to="/login" variant="ghost" color="neutral">Войти</UButton>
        </div>
      </form>
    </UCard>

    <UCard v-else>
      <!-- `auto-submit` выключен: кроме кода нужен ещё новый пароль, иначе
           форма уходила бы на сервер по шестой цифре. -->
      <AuthCodeForm
        :email="email"
        :state="codeState"
        :error="codeError"
        :loading="loading"
        :auto-submit="false"
        submit-label="Задать новый пароль"
        hint="Если кабинет с таким адресом есть, код уже в письме."
        @submit="submitNewPassword"
        @resend="resend"
        @back="step = 'email'"
      >
        <UFormField label="Новый пароль" required help="Не менее 8 символов">
          <PasswordInput
            v-model="password" autocomplete="new-password" class="w-full"
          />
        </UFormField>
        <UFormField label="Повторите новый пароль" required>
          <PasswordInput
            v-model="passwordRepeat" autocomplete="new-password" class="w-full"
          />
        </UFormField>
      </AuthCodeForm>
    </UCard>
  </UContainer>
</template>
