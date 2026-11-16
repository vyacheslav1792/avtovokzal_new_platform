<script setup lang="ts">
/**
 * Полоса «вы в кабинете покупателя» — видна сотруднику, который вошёл в
 * кабинет из АИС (право `customers.impersonate`).
 *
 * Нужна по двум причинам: сотрудник не должен принять чужой кабинет за
 * свой (в нём чужие ФИО, документы и покупки), и он должен сразу видеть,
 * что в этом режиме можно только смотреть — покупку и возврат сервер
 * отсюда не пропустит. Закрыть полосу нельзя: это не уведомление, а
 * признак режима.
 *
 * «Липкой» её не делаем: шапка сайта уже закреплена `sticky top-0`, и две
 * липкие полосы перекрывали бы друг друга (по той же причине не липкая и
 * панель настроек версии для слабовидящих).
 */
const { user, logout } = useAuth()
const router = useRouter()
const leaving = ref(false)

const label = computed(() => user.value?.email || user.value?.name || `кабинет №${user.value?.id}`)

async function leave() {
  leaving.value = true
  try {
    await logout()
    await router.push('/')
  }
  finally {
    leaving.value = false
  }
}
</script>

<template>
  <div
    v-if="user?.impersonated"
    class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-warning px-3 py-2 text-sm text-black"
  >
    <UIcon name="i-lucide-eye" class="size-5 shrink-0" />
    <span>
      Вы смотрите кабинет покупателя <strong>{{ label }}</strong> от имени сотрудника
      <template v-if="user.impersonated_by">{{ user.impersonated_by }}</template>.
      Покупка, возврат и изменение данных в этом режиме запрещены.
    </span>
    <UButton
      size="xs"
      color="neutral"
      variant="solid"
      :loading="leaving"
      class="ms-auto"
      @click="leave"
    >
      Выйти из кабинета
    </UButton>
  </div>
</template>
