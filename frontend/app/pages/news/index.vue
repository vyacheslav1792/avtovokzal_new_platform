<script setup lang="ts">
/**
 * Новости и объявления автовокзалов.
 *
 * В legacy раздела новостей не было: объявления («День поля», заказные
 * рейсы, изменения в расписании) лежали вперемешку с вёрсткой в
 * avtovokzal57/vidjets/news.php и показывались по условию
 * `time() < strtotime('…')` — каждая новость означала правку исходника и
 * выкладку на боевой сайт. Теперь они живут в базе и публикуются из АИС.
 */
interface NewsListItem {
  slug: string
  title: string
  lead: string | null
  cover_url: string | null
  cover_alt: string | null
  published_at: string
}

interface NewsPage {
  items: NewsListItem[]
  total: number
  page: number
  per_page: number
  last_page: number
}

const route = useRoute()
const router = useRouter()
const api = useApi()

const page = computed(() => Math.max(1, Number(route.query.page) || 1))

const { data: news, status } = await useAsyncData<NewsPage>(
  () => `news:${page.value}`,
  async () => {
    const resp = await api<{ success: boolean, data: NewsPage }>('/news', {
      params: { page: page.value },
    })

    return resp.data
  },
  {
    watch: [page],
    default: (): NewsPage => ({ items: [], total: 0, page: 1, per_page: 10, last_page: 1 }),
  },
)

function openPage(value: number) {
  router.push({ path: '/news', query: value > 1 ? { page: value } : {} })
}

useSeoMeta({
  title: 'Новости',
  description: 'Новости и объявления автовокзалов Орла и Орловской области: '
    + 'изменения в расписании, заказные рейсы, работа касс.',
})
</script>

<template>
  <UContainer class="py-8 max-w-4xl">
    <BackButton fallback="/" />

    <h1 class="text-3xl font-light mt-4 mb-6">
      Новости
    </h1>

    <div v-if="status === 'pending'" class="space-y-4">
      <USkeleton v-for="i in 3" :key="i" class="h-28 w-full" />
    </div>

    <!-- Пусто — это нормальное состояние раздела, а не ошибка -->
    <UCard v-else-if="!news.items.length">
      <div class="py-8 text-center space-y-4">
        <UIcon name="i-lucide-newspaper" class="size-10 text-muted mx-auto" />
        <p>
          Новостей пока нет. Об изменениях в расписании и заказных рейсах
          можно узнать в кассах автовокзалов и в справочной службе.
        </p>
        <div class="grid gap-2 sm:flex sm:justify-center">
          <UButton to="/" color="error" icon="i-lucide-ticket">
            Купить билет
          </UButton>
          <UButton to="/contacts" color="neutral" variant="outline" icon="i-lucide-phone">
            Контакты автовокзалов
          </UButton>
        </div>
      </div>
    </UCard>

    <template v-else>
      <div class="space-y-4">
        <NewsCard v-for="item in news.items" :key="item.slug" :item="item" />
      </div>

      <!-- Постраничная навигация: новости накапливаются годами -->
      <div v-if="news.last_page > 1" class="flex justify-center mt-8">
        <UPagination
          :page="news.page"
          :total="news.total"
          :items-per-page="news.per_page"
          @update:page="openPage"
        />
      </div>
    </template>
  </UContainer>
</template>
