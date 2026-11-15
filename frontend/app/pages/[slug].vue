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
    <article class="site-html" v-html="page?.content" />
  </UContainer>
</template>
