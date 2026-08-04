<script setup lang="ts">
/**
 * Кнопка «Войти через VK». Это обычная ссылка на серверный маршрут Nuxt
 * `/auth/vk`, который уводит покупателя на id.vk.ru — поэтому вход
 * работает и без JS, и без стороннего SDK на странице.
 */
const props = defineProps<{
  /** Куда вернуть покупателя после входа (адрес внутри сайта). */
  redirect?: string
  /** Текст кнопки: на регистрации уместнее «Зарегистрироваться». */
  label?: string
}>()

const href = computed(() => {
  const target = props.redirect?.startsWith('/') ? props.redirect : '/lk'

  return `/auth/vk?redirect=${encodeURIComponent(target)}`
})
</script>

<template>
  <a
    :href="href"
    class="inline-flex items-center justify-center gap-2 w-full rounded-md px-4 py-2.5 text-base
           font-medium text-white bg-[#0077FF] hover:bg-[#0068DE] transition-colors
           focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0077FF] focus-visible:ring-offset-2"
  >
    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path
        d="M13.162 18.994c.609 0 .858-.406.851-.915-.031-1.917.714-2.949 2.059-1.604 1.488 1.488 1.796 2.519 3.603 2.519h3.2c.808 0 1.126-.26 1.126-.668 0-.863-1.421-2.386-2.625-3.504-1.686-1.565-1.765-1.602-.313-3.486 1.801-2.339 4.157-5.336 2.073-5.336h-3.981c-.772 0-.828.435-1.103 1.083-.995 2.347-2.886 5.387-3.604 4.922-.751-.485-.407-2.406-.35-5.261.015-.754.012-1.271-1.135-1.539-.658-.154-1.301-.214-1.891-.214-2.364 0-3.99.968-3.066 1.14 1.628.304 1.472 3.911 1.098 5.466-.652 2.719-3.211-2.115-4.415-5.1-.289-.717-.359-1.316-1.463-1.316H1.03c-.623 0-.997.203-.997.65 0 .757 2.978 6.192 5.783 9.286 2.673 2.95 5.38 2.877 7.346 2.877z"
      />
    </svg>
    {{ label ?? 'Войти через VK' }}
  </a>
</template>
