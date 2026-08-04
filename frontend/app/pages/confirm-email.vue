<script setup lang="ts">
/**
 * Подтверждение e-mail по ссылке из письма: адрес, добавленный к кабинету
 * без почты (вход через VK), привязывается только отсюда — по e-mail
 * кабинет показывает покупки, сделанные до регистрации, и непроверенный
 * адрес открыл бы чужие билеты.
 */
const route = useRoute()
const { confirmEmail, isLoggedIn } = useAuth()

useSeoMeta({ title: 'Подтверждение электронной почты' })

const token = String(route.query.token ?? '')
const email = ref('')
const errorMessage = ref('')
const loading = ref(true)

onMounted(async () => {
  if (!token) {
    errorMessage.value = 'Ссылка неполная: в ней нет кода подтверждения. Откройте её из письма целиком.'
    loading.value = false
    return
  }

  try {
    email.value = await confirmEmail(token)
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e, 'Не удалось подтвердить адрес, попробуйте позже')
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <UContainer class="py-10 max-w-md">
    <h1 class="text-3xl font-light mb-6">Подтверждение почты</h1>

    <UCard>
      <div v-if="loading" class="flex items-center gap-3 text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" />
        Проверяем ссылку…
      </div>

      <template v-else-if="errorMessage">
        <UAlert color="error" variant="subtle" :title="errorMessage" class="mb-4" />
        <UButton to="/lk/profile" color="error">В профиль</UButton>
      </template>

      <template v-else>
        <UAlert
          color="success" variant="subtle"
          title="Адрес подтверждён"
          :description="`Почта ${email} привязана к кабинету: билеты и чеки будут приходить на неё, а прошлые покупки на этот адрес появились в списке билетов.`"
          class="mb-4"
        />
        <div class="flex gap-2">
          <UButton to="/lk" color="error">Мои билеты</UButton>
          <UButton v-if="!isLoggedIn" to="/login" color="neutral" variant="outline">Войти</UButton>
        </div>
      </template>
    </UCard>
  </UContainer>
</template>
