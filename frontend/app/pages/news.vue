<script setup lang="ts">
/**
 * Новости — пока заглушка.
 *
 * В legacy новостей как раздела не было: объявления («День поля»,
 * «Троицкие хороводы», заказные рейсы) лежали вперемешку с вёрсткой в
 * `avtovokzal57/vidjets/news.php` и показывались по условию
 * `time() < strtotime('…')` — каждая новость означала правку исходника и
 * выкладку на боевой сайт.
 *
 * Полноценный раздел (таблица `news`, API, редактор в АИС по образцу
 * «Страниц») — отдельная задача; плитка на стартовом экране приложения
 * ведёт сюда уже сейчас, чтобы не перекраивать экран потом.
 */
useSeoMeta({
  title: 'Новости',
  description: 'Новости и объявления автовокзалов Орловской области',
})
</script>

<template>
  <UContainer class="py-10 max-w-3xl">
    <BackButton fallback="/app" />

    <h1 class="text-3xl font-light mt-4 mb-6">
      Новости
    </h1>

    <UCard>
      <div class="py-8 text-center space-y-4">
        <UIcon name="i-lucide-newspaper" class="size-10 text-muted mx-auto" />
        <p>
          Раздел готовится. Объявления об изменениях в расписании и заказных рейсах
          пока публикуются на главной странице сайта и в кассах автовокзалов.
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
  </UContainer>
</template>
