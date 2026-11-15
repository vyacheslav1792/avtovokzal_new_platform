<script setup lang="ts">
/**
 * Новость в списке: обложка, дата, заголовок и краткое содержание.
 *
 * Обложки может не быть (объявление об изменении расписания — это текст),
 * поэтому карточка одинаково хорошо выглядит и без картинки: место под неё
 * не резервируется.
 */
const props = defineProps<{
  item: {
    slug: string
    title: string
    lead: string | null
    cover_url: string | null
    cover_alt: string | null
    published_at: string
  }
}>()

const date = computed(() => new Date(props.item.published_at).toLocaleDateString('ru-RU', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
}))
</script>

<template>
  <NuxtLink
    :to="`/news/${item.slug}`"
    class="block rounded-lg border border-default hover:border-primary transition-colors overflow-hidden"
  >
    <div class="flex flex-col sm:flex-row">
      <img
        v-if="item.cover_url"
        :src="item.cover_url"
        :alt="item.cover_alt || ''"
        class="w-full sm:w-56 h-40 sm:h-auto object-cover shrink-0"
        loading="lazy"
      >
      <div class="p-4 min-w-0">
        <p class="text-sm text-muted mb-1">
          {{ date }}
        </p>
        <h2 class="text-lg font-medium mb-2">
          {{ item.title }}
        </h2>
        <div v-if="item.lead" class="site-html text-sm text-dimmed" v-html="item.lead" />
      </div>
    </div>
  </NuxtLink>
</template>
