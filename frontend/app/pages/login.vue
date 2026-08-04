<script setup lang="ts">
const { login, isLoggedIn } = useAuth()
const { data: providers } = await useAuthProviders()
const route = useRoute()

useSeoMeta({
  title: 'Вход в личный кабинет',
  description: 'Вход в личный кабинет покупателя билетов Орловского автовокзала.',
})

const email = ref('')
const password = ref('')
const loading = ref(false)

/** Причины, по которым вход через VK не состоялся (?vk_error=…). */
const VK_ERRORS: Record<string, string> = {
  denied: 'Вход через VK отменён. Можно войти по e-mail и паролю.',
  state: 'Вход через VK не завершился: страница была открыта слишком давно. Попробуйте ещё раз.',
  failed: 'Не удалось войти через VK. Попробуйте ещё раз или войдите по e-mail и паролю.',
  unavailable: 'Вход через VK сейчас недоступен. Войдите по e-mail и паролю.',
}

const errorMessage = ref(VK_ERRORS[String(route.query.vk_error ?? '')] ?? '')

const redirect = computed(() => String(route.query.redirect ?? '/lk'))

// Уже вошедшего сразу отправляем в кабинет.
watchEffect(() => {
  if (isLoggedIn.value) navigateTo(redirect.value)
})

async function submit() {
  errorMessage.value = ''
  loading.value = true
  try {
    await login(email.value, password.value)
    await navigateTo(redirect.value)
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e, 'Не удалось войти, попробуйте позже')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <h1 class="text-3xl font-light mb-6">Вход в личный кабинет</h1>

    <UAlert v-if="errorMessage" color="error" variant="subtle" :title="errorMessage" class="mb-4" />

    <UCard>
      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="E-Mail" required>
          <UInput v-model="email" type="email" autocomplete="email" size="lg" class="w-full" />
        </UFormField>
        <UFormField label="Пароль" required>
          <UInput
            v-model="password" type="password" autocomplete="current-password"
            size="lg" class="w-full"
          />
        </UFormField>

        <div class="flex items-center gap-3 pt-2">
          <UButton type="submit" color="error" size="lg" :loading="loading">Войти</UButton>
          <UButton to="/register" variant="ghost" color="neutral">Зарегистрироваться</UButton>
        </div>
        <NuxtLink to="/forgot" class="inline-block text-sm text-primary underline underline-offset-2">
          Забыли пароль?
        </NuxtLink>
      </form>

      <template v-if="providers?.vk" #footer>
        <div class="flex items-center gap-3 mb-4 text-sm text-muted">
          <span class="h-px flex-1 bg-default" />
          или
          <span class="h-px flex-1 bg-default" />
        </div>
        <VkLoginButton :redirect="redirect" />
        <p class="text-xs text-muted mt-3">
          Если VK не сообщит нам вашу электронную почту, кабинет всё равно будет создан —
          адрес можно указать позже в профиле.
        </p>
      </template>
    </UCard>

    <p class="text-sm text-muted mt-6">
      Билет можно вернуть и без входа —
      <NuxtLink to="/backbycode" class="text-primary underline underline-offset-2">по коду с билета</NuxtLink>.
    </p>
  </UContainer>
</template>
