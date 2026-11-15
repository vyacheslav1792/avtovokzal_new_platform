<script setup lang="ts">
/**
 * Страница новости. Своего адреса у объявления в legacy не было — его
 * нельзя было ни переслать, ни найти поиском: оно жило абзацем в
 * avtovokzal57/vidjets/news.php. Теперь у каждой новости свой /news/<slug>,
 * заголовок вкладки и картинка для пересылки в мессенджерах.
 */
interface NewsItem {
  slug: string
  title: string
  meta_title: string
  meta_description: string | null
  lead: string | null
  content: string
  cover_url: string | null
  cover_alt: string | null
  published_at: string
  updated_at: string | null
}

const route = useRoute()
const api = useApi()
const config = useRuntimeConfig()

const slug = computed(() => String(route.params.slug ?? ''))

const { data: item } = await useAsyncData<NewsItem | null>(
  () => `news-item:${slug.value}`,
  async () => {
    try {
      const resp = await api<{ success: boolean, data: NewsItem }>(`/news/${encodeURIComponent(slug.value)}`)

      return resp.data
    }
    catch (e: any) {
      // Новости нет, она выключена или срок показа вышел — обычный 404.
      // Ошибку наружу не бросаем: она осела бы в error, и страница ушла бы
      // с кодом 200 (как на обычных страницах сайта).
      if (e?.response?.status === 404) return null
      throw e
    }
  },
  { watch: [slug] },
)

if (!item.value) {
  throw createError({ statusCode: 404, statusMessage: 'Новость не найдена', fatal: true })
}

const date = computed(() => new Date(item.value!.published_at).toLocaleDateString('ru-RU', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
}))

/**
 * Картинка для пересылки — абсолютным адресом: относительный мессенджеры
 * не разворачивают. Берём его из `siteUrl`, а не из `useRequestURL()`:
 * при SSR внутри docker-сети origin равен http://frontend:3000.
 */
const siteUrl = String(config.public.siteUrl || '').replace(/\/+$/, '')

/** Описание для поисковиков: из краткого содержания, без разметки. */
const description = computed(() => {
  if (item.value?.meta_description) return item.value.meta_description

  return String(item.value?.lead || '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 300)
})

useSeoMeta({
  title: () => item.value?.meta_title || item.value?.title,
  description,
  ogTitle: () => item.value?.meta_title || item.value?.title,
  ogDescription: description,
  ogType: 'article',
  ogImage: () => (item.value?.cover_url ? `${siteUrl}${item.value.cover_url}` : undefined),
  articlePublishedTime: () => item.value?.published_at,
  articleModifiedTime: () => item.value?.updated_at || undefined,
})
</script>

<template>
  <UContainer class="py-8 max-w-3xl">
    <BackButton fallback="/news" />

    <article v-if="item" class="mt-4">
      <p class="text-sm text-muted mb-2">
        {{ date }}
      </p>
      <h1 class="text-3xl font-light mb-5">
        {{ item.title }}
      </h1>

      <img
        v-if="item.cover_url"
        :src="item.cover_url"
        :alt="item.cover_alt || ''"
        class="w-full rounded-lg mb-6"
      >

      <!-- Краткое содержание выводим перед текстом только если он есть:
           у коротких объявлений всё сказано в нём одном. -->
      <div v-if="item.lead && item.content" class="site-html font-medium mb-5" v-html="item.lead" />
      <div class="site-html" v-html="item.content || item.lead || ''" />
    </article>

    <div class="mt-10">
      <UButton to="/news" color="neutral" variant="outline" icon="i-lucide-newspaper">
        Все новости
      </UButton>
    </div>
  </UContainer>
</template>
