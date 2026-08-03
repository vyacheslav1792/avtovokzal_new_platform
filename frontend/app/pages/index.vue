<script setup lang="ts">
const api = useApi()
const router = useRouter()
const toast = useToast()

const { data: stationsResp } = await useAsyncData('stations', () =>
  api<{ success: boolean, data: { awtcod: number, name: string }[] }>('/stations'))

const stations = computed(() =>
  (stationsResp.value?.data ?? []).map(s => ({ label: s.name, value: s.awtcod })))

const from = ref<number | null>(null)
const to = ref<string | null>(null)
const today = new Date().toISOString().slice(0, 10)
const date = ref(today)

const destinations = ref<{ label: string, value: string }[]>([])
const destinationsLoading = ref(false)

watch(from, async (awtcod) => {
  to.value = null
  destinations.value = []
  if (!awtcod) return
  destinationsLoading.value = true
  try {
    const resp = await api<{ success: boolean, data: { name: string }[] }>(
      `/stations/${awtcod}/destinations`,
    )
    destinations.value = resp.data.map(d => ({ label: d.name, value: d.name }))
  }
  catch {
    toast.add({ title: 'Не удалось загрузить пункты назначения', color: 'error' })
  }
  finally {
    destinationsLoading.value = false
  }
})

function search() {
  if (!from.value || !to.value || !date.value) {
    toast.add({ title: 'Выберите маршрут и дату поездки', color: 'warning' })
    return
  }
  const fromName = stations.value.find(s => s.value === from.value)?.label ?? ''
  router.push({
    path: '/trips',
    query: { awtcod: from.value, from: fromName, to: to.value, date: date.value },
  })
}

// Популярные направления (как на legacy-главной)
const popular = [
  { title: 'Орел – Красная Заря', img: '/img/zarya.jpg', from: 5701, fromName: 'Орел АВ', to: 'Красная Заря КП' },
  { title: 'Красная Заря – Орел', img: '/img/zarya.jpg', from: 5722, fromName: 'Красная Заря КП', to: 'Орел АВ' },
  { title: 'Орел – Брянск', img: '/img/bryansk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Брянск' },
  { title: 'Орел – Железногорск', img: '/img/zheleznogorsk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Железногорск' },
  { title: 'Орел – Курск', img: '/img/kursk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Курск' },
  { title: 'Орел – Ливны', img: '/img/livni.jpg', from: 5701, fromName: 'Орел АВ', to: 'Ливны АС' },
  { title: 'Орел – Воронеж', img: '/img/vrn.jpg', from: 5701, fromName: 'Орел АВ', to: 'Воронеж' },
  { title: 'Орел – Тула', img: '/img/tula.jpg', from: 5701, fromName: 'Орел АВ', to: 'Тула' },
]
</script>

<template>
  <div>
    <!-- Hero с картинкой главной из legacy (img/bus.jpg) -->
    <section
      class="relative bg-cover bg-center"
      :style="{ backgroundImage: 'url(/img/bus.jpg)' }"
    >
      <div class="absolute inset-0 bg-black/40" />
      <UContainer class="relative py-16 md:py-24">
        <h1 class="text-2xl md:text-4xl font-light text-white text-center mb-8 drop-shadow">
          Покупка билетов на официальном сайте автовокзала Орёл
        </h1>

        <UCard class="max-w-4xl mx-auto shadow-xl">
          <div class="grid gap-3 md:grid-cols-[1fr_1fr_180px_auto] items-end">
            <UFormField label="Откуда">
              <USelectMenu
                v-model="from"
                :items="stations"
                value-key="value"
                placeholder="Станция отправления"
                searchable
                class="w-full"
                size="lg"
              />
            </UFormField>
            <UFormField label="Куда">
              <USelectMenu
                v-model="to"
                :items="destinations"
                value-key="value"
                :loading="destinationsLoading"
                :disabled="!from"
                placeholder="Пункт назначения"
                searchable
                class="w-full"
                size="lg"
              />
            </UFormField>
            <UFormField label="Дата поездки">
              <UInput v-model="date" type="date" :min="today" size="lg" class="w-full" />
            </UFormField>
            <UButton size="lg" color="error" class="justify-center" @click="search">
              Найти билеты
            </UButton>
          </div>
        </UCard>
      </UContainer>
    </section>

    <UContainer class="py-10 space-y-10">
      <UAlert
        color="info"
        variant="subtle"
        title="Покупка билетов онлайн возможна на рейсы с выбранного автовокзала не позднее чем за 1 час до отправления."
        description="Билеты на транзитные рейсы доступны к продаже только в кассах автовокзала. При успешной оплате электронный билет и чек ККМ высылаются на указанную почту."
      />

      <section>
        <h2 class="text-2xl font-light text-center mb-6">Популярные направления</h2>
        <div class="grid gap-4 grid-cols-2 lg:grid-cols-4">
          <NuxtLink
            v-for="p in popular"
            :key="p.title"
            :to="{ path: '/trips', query: { awtcod: p.from, from: p.fromName, to: p.to, date: today } }"
            class="relative rounded-lg overflow-hidden group h-36"
          >
            <img :src="p.img" :alt="p.title" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent" />
            <span class="absolute bottom-2 left-3 text-white font-medium drop-shadow">{{ p.title }}</span>
          </NuxtLink>
        </div>
      </section>
    </UContainer>
  </div>
</template>
