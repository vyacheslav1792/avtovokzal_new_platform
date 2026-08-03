<script setup lang="ts">
/**
 * Контакты (перенос legacy url/contacts.php).
 *
 * В legacy страница была статическим HTML, где одни и те же данные лежали
 * ДВАЖДЫ: таблица для десктопа и карточки для мобильных (из-за чего они
 * успели разойтись — у Орла в мобильной версии стояло «55:00 – 23:00»).
 * Здесь данные приходят из API одним списком, а таблица и карточки — это
 * две вёрстки одних и тех же данных. Правятся контакты в админке АИС.
 *
 * Кнопка «Написать сообщение» (общая модалка сайта с капчей и антиспамом)
 * переносится отдельной задачей — здесь её пока нет.
 */
const api = useApi()

interface ContactDetail {
  label: string
  kind: 'text' | 'phone' | 'email' | 'fax'
  items: string[]
}

interface ContactPoint {
  name: string
  work_hours: string[]
  phones: string[]
  address: string | null
  breaks: string[]
}

const { data } = await useAsyncData('contacts', () =>
  api<{ success: boolean, data: { details: ContactDetail[], points: ContactPoint[] } }>('/contacts'))

const details = computed(() => data.value?.data.details ?? [])
const points = computed(() => data.value?.data.points ?? [])

/** Ссылка для значения реквизита: tel:/fax:/mailto: — как в legacy. */
function detailHref(detail: ContactDetail, value: string): string | null {
  if (detail.kind === 'email') return `mailto:${value}`
  if (detail.kind === 'phone' || detail.kind === 'fax') {
    const digits = value.replace(/[^\d+]/g, '')
    return `${detail.kind === 'fax' ? 'fax' : 'tel'}:${digits}`
  }
  return null
}

useSeoMeta({
  title: 'Контакты – адреса и телефоны автовокзалов Орловской области',
  description: 'Адреса и телефоны автовокзалов Орловской области',
})
</script>

<template>
  <UContainer class="py-8 max-w-5xl">
    <h1 class="text-3xl font-light mb-6">Контакты</h1>

    <!-- Реквизиты организации (верхняя таблица legacy) -->
    <table class="mb-8">
      <tbody>
        <tr v-for="d in details" :key="d.label" class="align-top">
          <td class="py-1.5 pr-6 text-muted">{{ d.label }}</td>
          <td class="py-1.5 font-medium">
            <template v-for="(value, i) in d.items" :key="value">
              <br v-if="i">
              <a
                v-if="detailHref(d, value)"
                :href="detailHref(d, value)!"
                class="text-primary hover:underline"
              >{{ value }}</a>
              <span v-else>{{ value }}</span>
            </template>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="mb-4">
      Перечень контактов автовокзалов, автостанций и кассовых пунктов АО «Орелавтотранс»
    </p>

    <!-- Десктоп: таблица, как в legacy -->
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-default text-left">
            <th class="py-2 pr-3 font-medium whitespace-nowrap">№ п/п</th>
            <th class="py-2 pr-3 font-medium">Остановочный пункт</th>
            <th class="py-2 pr-3 font-medium">Телефоны</th>
            <th class="py-2 pr-3 font-medium">Адрес</th>
            <th class="py-2 font-medium">Перерыв</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(p, i) in points"
            :key="p.name"
            class="border-b border-default align-top hover:bg-elevated/50"
          >
            <td class="py-2 pr-3">{{ i + 1 }}.</td>
            <td class="py-2 pr-3">
              <strong>{{ p.name }}</strong>
              <template v-if="p.work_hours.length">
                <br>Время работы:
                <template v-for="(h, hi) in p.work_hours" :key="h">
                  <br v-if="hi">{{ h }}
                </template>
              </template>
            </td>
            <td class="py-2 pr-3 whitespace-nowrap">
              <template v-for="(phone, pi) in p.phones" :key="phone">
                <br v-if="pi">{{ phone }}
              </template>
            </td>
            <td class="py-2 pr-3">{{ p.address }}</td>
            <td class="py-2">
              <template v-for="(b, bi) in p.breaks" :key="b">
                <br v-if="bi">{{ b }}
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Мобильные: карточки (в legacy — отдельная копия тех же данных) -->
    <div class="md:hidden space-y-4">
      <div v-for="(p, i) in points" :key="p.name" class="border border-default rounded-lg p-4">
        <div class="font-semibold">{{ i + 1 }}. {{ p.name }}</div>
        <div v-if="p.work_hours.length" class="mt-1 text-sm">
          Время работы:
          <template v-for="(h, hi) in p.work_hours" :key="h">
            <br v-if="hi">{{ h }}
          </template>
        </div>
        <div v-if="p.phones.length" class="mt-2 text-sm">
          <span class="text-muted">Телефон: </span>
          <template v-for="(phone, pi) in p.phones" :key="phone">
            <br v-if="pi">
            <a :href="`tel:${phone.replace(/[^\d+]/g, '')}`" class="text-primary">{{ phone }}</a>
          </template>
        </div>
        <div v-if="p.address" class="mt-2 text-sm">
          <span class="text-muted">Адрес: </span>{{ p.address }}
        </div>
        <div v-if="p.breaks.length" class="mt-2 text-sm">
          <span class="text-muted">Перерыв:</span>
          <template v-for="b in p.breaks" :key="b">
            <br>{{ b }}
          </template>
        </div>
      </div>
    </div>
  </UContainer>
</template>
