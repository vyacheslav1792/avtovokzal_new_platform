<script setup lang="ts">
/**
 * Обычная (текстовая) страница сайта: оплата, документы, FAQ, соглашение
 * и политика ПД, услуги, 223-ФЗ, противодействие коррупции и мошенничеству.
 *
 * В legacy каждая была отдельным файлом avtovokzal57/url/<slug>.php, а её
 * <title>/description — веткой switch в index.php; правка текста означала
 * выкладку кода. Теперь содержимое лежит в базе и правится в админке АИС.
 *
 * Маршрут динамический и стоит последним: страницы с собственной логикой
 * (/trips, /buy, /contacts, …) объявлены файлами и имеют приоритет.
 */
interface SitePage {
  slug: string
  title: string
  meta_title: string
  meta_description: string | null
  content: string
}

const route = useRoute()
const api = useApi()
const slug = computed(() => String(route.params.slug ?? ''))

const { data: page } = await useAsyncData<SitePage | null>(
  () => `page:${slug.value}`,
  async () => {
    try {
      const resp = await api<{ success: boolean, data: SitePage }>(`/pages/${encodeURIComponent(slug.value)}`)

      return resp.data
    }
    catch (e: any) {
      // Нет такой страницы (или она выключена) — обычный 404 сайта.
      // Ошибку из обработчика useAsyncData наружу не бросаем: она осела бы
      // в error и страница отдалась бы с кодом 200.
      if (e?.response?.status === 404) return null
      throw e
    }
  },
  { watch: [slug] },
)

if (!page.value) {
  throw createError({ statusCode: 404, statusMessage: 'Страница не найдена', fatal: true })
}

useSeoMeta({
  title: () => page.value?.meta_title || page.value?.title,
  description: () => page.value?.meta_description || '',
  ogTitle: () => page.value?.meta_title || page.value?.title,
  ogDescription: () => page.value?.meta_description || '',
})
</script>

<template>
  <UContainer class="py-8">
    <!-- Содержимое страницы — HTML из админки (в legacy это был файл
         вёрстки), поэтому выводим как есть, вместе с его <style>. -->
    <article class="site-page" v-html="page?.content" />
  </UContainer>
</template>

<style scoped>
/*
 * Типографика перенесённых страниц. Разметка приехала из legacy как есть
 * (h1/h2/h3, абзацы, списки, свои <style> с text-align: justify), а
 * Tailwind-preflight нового фронта сбрасывает у тегов все стили — поэтому
 * возвращаем их здесь, а не правим содержимое страниц.
 */
.site-page :deep(h1) {
  font-size: 1.875rem;
  font-weight: 700;
  line-height: 1.2;
  margin-bottom: 1.25rem;
}

.site-page :deep(h2) {
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1.3;
  margin: 1.75rem 0 0.75rem;
}

.site-page :deep(h3) {
  font-size: 1.25rem;
  font-weight: 600;
  margin: 1.5rem 0 0.5rem;
}

.site-page :deep(p) {
  margin-bottom: 0.85rem;
  line-height: 1.6;
}

.site-page :deep(ul),
.site-page :deep(ol) {
  margin: 0 0 1rem 1.5rem;
  line-height: 1.6;
}

.site-page :deep(ul) {
  list-style: disc;
}

.site-page :deep(ol) {
  list-style: decimal;
}

.site-page :deep(li) {
  margin-bottom: 0.35rem;
}

.site-page :deep(a) {
  color: var(--ui-primary);
  text-decoration: underline;
}

.site-page :deep(img) {
  max-width: 100%;
  height: auto;
}

.site-page :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1rem;
}

.site-page :deep(th),
.site-page :deep(td) {
  border: 1px solid var(--ui-border);
  padding: 0.4rem 0.6rem;
  text-align: left;
}

/* Широкие таблицы прокручиваются сами, страница по горизонтали — нет. */
.site-page :deep(.table-scroll) {
  overflow-x: auto;
}
</style>
