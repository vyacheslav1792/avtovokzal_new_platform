<script setup lang="ts">
const api = useApi()

useSeoMeta({
  title: 'Восстановление пароля',
  description: 'Сброс пароля от личного кабинета Орловского автовокзала.',
})

const email = ref('')
const loading = ref(false)
const errorMessage = ref('')
const sent = ref(false)

async function submit() {
  errorMessage.value = ''
  loading.value = true
  try {
    await api('/auth/password/forgot', { method: 'POST', body: { email: email.value } })
    sent.value = true
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e)
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

    <UCard v-if="!sent">
      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="Электронная почта" required help="Адрес, на который покупали билеты">
          <UInput v-model="email" type="email" autocomplete="email" size="lg" class="w-full" />
        </UFormField>
        <div class="flex items-center gap-3">
          <UButton type="submit" color="error" size="lg" :loading="loading">Сбросить пароль</UButton>
          <UButton to="/login" variant="ghost" color="neutral">Войти</UButton>
        </div>
      </form>
    </UCard>

    <div v-else class="space-y-4">
      <UAlert
        color="success"
        variant="subtle"
        title="Письмо отправлено"
        :description="`Если адрес ${email} зарегистрирован, на него отправлена ссылка для смены пароля.`"
      />
      <UAlert
        color="warning"
        variant="subtle"
        title="Письма нет?"
        description="Проверьте папку «Спам». На Gmail загляните также в «Промоакции», «Несортированные» и «Оповещения»."
      />
      <UButton to="/login" color="neutral" variant="outline">Вернуться ко входу</UButton>
    </div>
  </UContainer>
</template>
