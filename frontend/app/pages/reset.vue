<script setup lang="ts">
const route = useRoute()
const { resetPassword } = useAuth()

useSeoMeta({ title: 'Смена пароля' })

const token = String(route.query.token ?? '')
const email = String(route.query.email ?? '')

const password = ref('')
const passwordRepeat = ref('')
const loading = ref(false)
const errorMessage = ref('')

async function submit() {
  errorMessage.value = ''

  if (password.value.trim() !== passwordRepeat.value.trim()) {
    errorMessage.value = 'Пароль и подтверждение должны совпадать'
    return
  }

  loading.value = true
  try {
    await resetPassword(token, password.value)
    await navigateTo('/lk')
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e, 'Не удалось сменить пароль, попробуйте позже')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <h1 class="text-3xl font-light mb-6">Создание пароля</h1>

    <UAlert v-if="errorMessage" color="error" variant="subtle" :title="errorMessage" class="mb-4" />

    <UAlert
      v-if="!token"
      color="error"
      variant="subtle"
      title="Ссылка неполная"
      description="Откройте ссылку из письма целиком или запросите сброс пароля заново."
      class="mb-4"
    />

    <UCard v-else>
      <form class="space-y-4" @submit.prevent="submit">
        <p v-if="email" class="text-sm text-muted">Кабинет: <b>{{ email }}</b></p>

        <UFormField label="Придумайте пароль" required help="Не менее 8 символов">
          <UInput
            v-model="password" type="password" autocomplete="new-password"
            size="lg" class="w-full" autofocus
          />
        </UFormField>
        <UFormField label="Повторите этот пароль здесь" required>
          <UInput
            v-model="passwordRepeat" type="password" autocomplete="new-password"
            size="lg" class="w-full"
          />
        </UFormField>

        <UButton type="submit" color="error" size="lg" :loading="loading">Задать пароль</UButton>
      </form>
    </UCard>

    <p class="text-sm text-muted mt-6">
      Вспомнили пароль?
      <NuxtLink to="/login" class="text-primary underline underline-offset-2">Войти</NuxtLink>
    </p>
  </UContainer>
</template>
