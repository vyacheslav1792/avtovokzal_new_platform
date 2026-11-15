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
// Дата в поясе автовокзала, а не UTC: иначе ночью форма предлагает вчера.
const today = siteDate()
const date = ref(today)

const destinations = ref<{ label: string, value: string }[]>([])
const destinationsLoading = ref(false)

/*
 * Форма поиска переживает возврат «назад»: на странице рейсов есть кнопка
 * «Назад», и после неё главная не должна встречать пустыми полями. Состояние
 * формы в адресе не живёт (главная — это просто форма), поэтому запоминаем
 * его на вкладку, как и отказ от регистрации на форме покупки.
 */
const SEARCH_STATE = 'home-search'

/** Пункт назначения ждёт, пока догрузится список станции (восстановление). */
let pendingTo: string | null = null

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
    // Остановку подставляем только если она ещё есть в расписании станции.
    if (pendingTo && destinations.value.some(d => d.value === pendingTo)) {
      to.value = pendingTo
    }
  }
  catch {
    toast.add({ title: 'Не удалось загрузить пункты назначения', color: 'error' })
  }
  finally {
    destinationsLoading.value = false
    pendingTo = null
  }
})

onMounted(() => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(SEARCH_STATE) ?? 'null')
    if (!saved?.from) return
    if (saved.date) date.value = saved.date
    pendingTo = saved.to ?? null
    from.value = saved.from
  }
  catch {
    // Значение испорчено (чужая вкладка, ручная правка) — просто игнорируем.
  }
})

function search() {
  if (!from.value || !to.value || !date.value) {
    toast.add({ title: 'Выберите маршрут и дату поездки', color: 'warning' })
    return
  }
  const fromName = stations.value.find(s => s.value === from.value)?.label ?? ''
  sessionStorage.setItem(SEARCH_STATE, JSON.stringify({
    from: from.value,
    to: to.value,
    date: date.value,
  }))
  router.push({
    path: '/trips',
    query: { awtcod: from.value, from: fromName, to: to.value, date: date.value },
  })
}

// Популярные направления (как на legacy-главной). Показываем только те,
// чья станция отправления реально есть в конфиге (доступна её база продаж).
// Ведут, как в legacy, на «ближайшие» рейсы маршрута (blij=1) — без даты
// и со всеми рейсами, включая недоступные к покупке онлайн.
const popularAll = [
  { title: 'Орел – Красная Заря', img: '/img/zarya.jpg', from: 5701, fromName: 'Орел АВ', to: 'Красная Заря КП' },
  { title: 'Красная Заря – Орел', img: '/img/zarya.jpg', from: 5722, fromName: 'Красная Заря КП', to: 'Орел АВ' },
  { title: 'Орел – Брянск', img: '/img/bryansk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Брянск' },
  { title: 'Орел – Железногорск', img: '/img/zheleznogorsk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Железногорск' },
  { title: 'Орел – Курск', img: '/img/kursk.jpg', from: 5701, fromName: 'Орел АВ', to: 'Курск' },
  { title: 'Орел – Ливны', img: '/img/livni.jpg', from: 5701, fromName: 'Орел АВ', to: 'Ливны АС' },
  { title: 'Орел – Воронеж', img: '/img/vrn.jpg', from: 5701, fromName: 'Орел АВ', to: 'Воронеж' },
  { title: 'Орел – Тула', img: '/img/tula.jpg', from: 5701, fromName: 'Орел АВ', to: 'Тула' },
]
const popular = computed(() =>
  popularAll.filter(p => stations.value.some(s => s.value === p.from)))

/** Фон hero — картинка главной из legacy. */
const heroImage = { backgroundImage: 'url(/img/bus.jpg)' }

/**
 * Затемнение под подписью плитки — градиент legacy
 * (`.popular-routes-list-item a:after`) один в один: до 60% высоты
 * прозрачный, дальше уходит в чёрный, иначе белый текст на светлом
 * снимке не читается.
 */
const tileOverlay = {
  backgroundImage: 'linear-gradient(transparent 0%, transparent 60%,'
    + ' rgba(0,0,0,0.1) 66%, rgba(0,0,0,0.5) 93%, rgba(0,0,0,0.49) 100%)',
}
</script>

<template>
  <div>
    <!--
      Hero с картинкой главной — один в один legacy (`.hero` в
      `avtovokzal57/css/main.css`): `url(img/bus.jpg) no-repeat 50% 50%`,
      `background-size: cover`, высота 550px, а на узком экране
      (`@media max-width: 767px`) — `height: auto`, то есть по содержимому.
      Высота здесь `min-h`, а не `h`: при той же геометрии (содержимое в
      550px укладывается) блок не обрежет форму, если она вдруг станет выше.
    -->
    <section
      class="relative bg-cover bg-center bg-no-repeat md:min-h-[550px] flex items-center"
      :style="heroImage"
    >
      <div class="absolute inset-0 bg-black/40" />
      <UContainer class="relative py-12 md:py-0">
        <h1 class="text-2xl md:text-4xl font-light text-white text-center mb-8 drop-shadow">
          Покупка билетов на официальном сайте автовокзала Орёл
        </h1>

        <UCard class="max-w-4xl mx-auto shadow-xl">
          <div class="grid gap-3 md:grid-cols-[1fr_1fr_180px_auto] items-end">
            <UFormField label="Откуда">
              <ResponsiveSelect
                v-model="from"
                :items="stations"
                placeholder="Станция отправления"
                title="Откуда едем"
                search-placeholder="Название автовокзала или кассы"
               
              />
            </UFormField>
            <UFormField label="Куда">
              <ResponsiveSelect
                v-model="to"
                :items="destinations"
                :loading="destinationsLoading"
                :disabled="!from"
                placeholder="Пункт назначения"
                title="Куда едем"
                search-placeholder="Название остановки"
               
              />
            </UFormField>
            <UFormField label="Дата поездки">
              <UInput v-model="date" type="date" :min="today" class="w-full" />
            </UFormField>
            <UButton color="error" class="justify-center" @click="search">
              Найти билеты
            </UButton>
          </div>
        </UCard>

        <!--
          Баннер под формой поиска — перенос legacy vidjets/top.php (баннер
          и ссылки на билеты до мест проведения мероприятий): правится в
          АИС, раздел «Блоки сайта».

          Коробка по содержимому (`w-fit`, потолок 20rem — как 320px у
          legacy `vidjets/top.php`), а НЕ во всю ширину: растянутая на
          896px полоса с картинкой 200px выглядела как пустая плашка
          поперёк картинки главной.
        -->
        <SiteBlock
          name="home_hero"
          class="w-fit max-w-80 mx-auto mt-4 bg-default/95 rounded-lg px-4 py-3 text-center shadow-lg"
        />
      </UContainer>
    </section>

    <UContainer class="py-10 space-y-10">
      <!--
        Содержимое главной между поиском и направлениями правится в АИС
        (раздел «Блоки сайта»). В legacy обращение, приветствие и памятки
        были вбиты в avtovokzal57/url/index.php и vidjets/news.php: правка
        текста означала выкладку кода на боевой сервер.
      -->
      <SiteBlock name="home_top" class="site-appeal" />
      <SiteBlock name="home_welcome" class="site-welcome" />

      <LatestNews />

      <SiteBlock name="home_memos" class="site-notices" />

      <!--
        Плитки направлений — геометрия legacy (`.popular-routes-list-item`
        в `avtovokzal57/css/main.css`): 4 в ряд и высота 300px на десктопе,
        2 в ряд и 240px на планшете (`max-width: 991px`), один ряд в ширину
        экрана на телефоне (`max-width: 480px`). Подпись — внизу слева,
        20px, поверх градиента, который прозрачен до 60% высоты; наведение
        поднимает плитку на 10px с тенью.
      -->
      <SiteBlock name="home_bottom" class="text-center" />

      <section>
        <h2 class="text-2xl font-light text-center mb-6">Популярные направления</h2>
        <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
          <NuxtLink
            v-for="p in popular"
            :key="p.title"
            :to="{ path: '/trips', query: { awtcod: p.from, from: p.fromName, to: p.to, all: 1 } }"
            :title="`Расписание и билеты по маршруту ${p.title}`"
            class="popular-route relative rounded overflow-hidden h-60 lg:h-75 transition-all duration-100
                   hover:-translate-y-2.5 hover:shadow-[0_10px_20px_rgba(0,0,0,0.4)]"
          >
            <img :src="p.img" :alt="p.title" class="absolute inset-0 w-full h-full object-cover">
            <div class="absolute inset-0" :style="tileOverlay" />
            <span class="absolute bottom-3 left-4.5 right-4.5 text-xl text-white">{{ p.title }}</span>
          </NuxtLink>
        </div>
      </section>
    </UContainer>
  </div>
</template>
