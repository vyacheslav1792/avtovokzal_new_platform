<script setup lang="ts">
/**
 * Последние новости — блок на главной и на стартовом экране приложения.
 * Показываются только отмеченные «на главной»: мелкие объявления не должны
 * вытеснять с главной форму покупки.
 *
 * Если новостей нет, блок не рисуется вовсе — пустого заголовка «Новости»
 * на главной быть не должно (в legacy он стоял всегда, даже когда под ним
 * были одни памятки).
 */
const props = withDefaults(defineProps<{
  limit?: number
  title?: string
}>(), {
  limit: 3,
  title: 'Новости',
})

interface NewsListItem {
  slug: string
  title: string
  lead: string | null
  cover_url: string | null
  cover_alt: string | null
  published_at: string
}

const api = useApi()

const { data: news } = await useAsyncData<NewsListItem[]>(
  `news-featured:${props.limit}`,
  async () => {
    try {
      const resp = await api<{ success: boolean, data: { items: NewsListItem[] } }>('/news', {
        params: { featured: 1, per_page: props.limit },
      })

      return resp.data.items
    }
    catch {
      // Новости — не повод ронять главную.
      return []
    }
  },
  { default: (): NewsListItem[] => [] },
)
</script>

<template>
  <section v-if="news.length">
    <div class="flex flex-wrap items-baseline justify-between gap-3 mb-5">
      <h2 class="text-2xl font-light">
        {{ props.title }}
      </h2>
      <NuxtLink to="/news" class="text-primary hover:underline text-sm">
        Все новости →
      </NuxtLink>
    </div>

    <div class="space-y-4">
      <NewsCard v-for="item in news" :key="item.slug" :item="item" />
    </div>
  </section>
</template>
