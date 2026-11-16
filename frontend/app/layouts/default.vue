<script setup lang="ts">
/**
 * Шапка и подвал сайта. Состав меню приходит из backend (таблица
 * menu_items): в legacy оба списка были захардкожены в вёрстке
 * avtovokzal57/index.php, и добавление страницы требовало правки кода.
 */
const { data: menu } = await useSiteMenu()

const { user, isLoggedIn, logout } = useAuth()
const route = useRoute()

/*
 * Приложение с домашнего экрана открывается на своём стартовом экране
 * (`/app`), и логотип должен возвращать туда же: в браузере «домой» —
 * это главная с поиском билетов, а в приложении — экран с разделами.
 */
const standalone = useStandalone()
const homeLink = computed(() => (standalone.value ? '/app' : '/'))

/*
 * Версия для людей с нарушениями зрения (ГОСТ Р 52872-2019): классы режима
 * на `<html>`. Единственная точка подключения — печатные формы идут с
 * `layout: false` и специального режима не знают.
 */
const { enabled: a11yEnabled } = useAccessibilityHead()

/**
 * Разделы сайта, у которых свои внутренние адреса: пункт меню должен
 * подсвечиваться и на них (страница покупки — часть «Купить билеты»).
 */
const MATCH_PREFIXES: Record<string, string[]> = {
  '/': ['/trips', '/buy', '/payment'],
  '/raspisanie-avtobusov': ['/tarif'],
}

/** Иконки мобильного меню — по адресу пункта, как в legacy mobile-menu. */
const ICONS: Record<string, string> = {
  '/': 'i-lucide-ticket',
  '/backbycode': 'i-lucide-undo-2',
  '/raspisanie-avtobusov': 'i-lucide-calendar-days',
  '/contacts': 'i-lucide-phone',
  '/payways': 'i-lucide-credit-card',
  '/faq': 'i-lucide-circle-help',
  '/reglament-docs': 'i-lucide-file-text',
  '/uslugi': 'i-lucide-bus',
}

function iconFor(url: string): string {
  return ICONS[url] ?? 'i-lucide-file-text'
}

// Меню кабинета в шапке (десктоп).
const accountItems = computed(() => [[
  { label: 'Мои билеты', icon: 'i-lucide-ticket', to: '/lk' },
  // Билеты на устройстве: открываются без сети (PWA), поэтому пункт есть
  // и у гостя — в мобильном меню ниже.
  { label: 'Билеты на устройстве', icon: 'i-lucide-smartphone', to: '/tickets' },
  { label: 'Пассажиры', icon: 'i-lucide-users', to: '/lk/passengers' },
  { label: 'Профиль', icon: 'i-lucide-settings', to: '/lk/profile' },
], [
  { label: 'Выйти', icon: 'i-lucide-log-out', onSelect: signOut },
]])

/** Выпадающий раздел шапки («Пассажирам») в формате UDropdownMenu. */
function groupItems(children: SiteMenuLink[]) {
  return [children.map(child => ({ label: child.title, to: child.url }))]
}

// Мобильное меню: закрываем на любом переходе, иначе после клика по ссылке
// панель остаётся поверх новой страницы.
const menuOpen = ref(false)
watch(() => route.fullPath, () => {
  menuOpen.value = false
})

function isActive(url: string): boolean {
  if (route.path === url) return true
  return (MATCH_PREFIXES[url] ?? []).some(
    prefix => route.path === prefix || route.path.startsWith(`${prefix}/`),
  )
}

/** Раздел шапки активен, если открыта любая его страница. */
function isGroupActive(children: SiteMenuLink[]): boolean {
  return children.some(child => isActive(child.url))
}

async function signOut() {
  menuOpen.value = false
  await logout()
  await navigateTo('/')
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-default">
    <!--
      Настройки версии для слабовидящих. Стоят выше шапки (и не «липкие»):
      по ГОСТ Р 52872-2019 управление альтернативной версией должно быть в
      начале документа, а шапка остаётся sticky top-0 как была — иначе
      разъехались бы все `sticky top-16` на страницах (BackButton).
      В обычном режиме компонент не рендерит ничего.
    -->
    <AccessibilityPanel v-if="a11yEnabled" />

    <!--
      Сотрудник АИС, вошедший в кабинет покупателя: признак режима, а не
      уведомление — закрыть его нельзя. Стоит ВЫШЕ шапки: та закреплена
      `sticky top-0`, и будь полоса под ней, они перекрывали бы друг друга.
    -->
    <ImpersonationNotice />

    <header class="bg-neutral-900 text-white sticky top-0 z-40">
      <UContainer class="flex items-center gap-3 lg:gap-5 py-3">
        <!--
          Название НЕ обрезаем многоточием: «АО «Орелавтотр…»» выглядит как
          поломка. На узком экране оно переносится на вторую строку (перенос
          идёт по пробелу после «АО»), а логотип и кегль там мельче — так
          полное название помещается даже на 320px.
        -->
        <NuxtLink :to="homeLink" class="flex items-center gap-2 min-w-0 sm:shrink-0">
          <img src="/img/logo.png" alt="Лого" class="h-7 w-auto shrink-0">
          <!--
            На узком экране название переносится на вторую строку (по пробелу
            после «АО») и кегль там мельче — иначе оно не влезает и его
            приходилось бы обрезать многоточием. От `sm` места хватает, и
            перенос запрещён: иначе «АО» и «Орелавтотранс» встают в две
            строки, а меню наезжает на вторую.
          -->
          <span
            class="font-semibold text-xs leading-tight
                   sm:text-base sm:leading-normal sm:whitespace-nowrap"
          >
            АО «Орелавтотранс»
          </span>
        </NuxtLink>

        <!--
          Полное меню показываем только от `xl`. На 1024–1279px пять пунктов,
          кнопка «Для слабовидящих» и «Личный кабинет» в строку не влезали:
          ряд переполнялся, появлялась горизонтальная прокрутка всей страницы,
          а название наезжало на меню. Ниже `xl` — кнопка меню.
        -->
        <nav class="hidden xl:flex items-center gap-3 2xl:gap-4 ml-auto text-sm">
          <template v-for="entry in menu?.header ?? []" :key="entry.title">
            <UDropdownMenu
              v-if="isMenuGroup(entry)"
              :items="groupItems(entry.children)"
              :ui="{ content: 'min-w-56' }"
            >
              <button
                type="button"
                class="flex items-center gap-1 transition-colors cursor-pointer"
                :class="isGroupActive(entry.children) ? 'text-white' : 'text-neutral-300 hover:text-white'"
              >
                {{ entry.title }}
                <UIcon name="i-lucide-chevron-down" class="size-4" />
              </button>
            </UDropdownMenu>
            <NuxtLink
              v-else
              :to="entry.url"
              class="transition-colors whitespace-nowrap"
              :class="isActive(entry.url) ? 'text-white' : 'text-neutral-300 hover:text-white'"
            >
              {{ entry.title }}
            </NuxtLink>
          </template>
        </nav>

        <div class="hidden xl:flex items-center gap-1">
          <!--
            Только значок: подпись «Для слабовидящих» занимает ~150px, и
            вместе с пятью пунктами меню и кабинетом ряд выходил за
            контейнер (он ограничен 1280px) даже на широком экране.
            У кнопки есть aria-label и подсказка, а текстом тот же пункт
            стоит в меню — требование ГОСТ Р 52872-2019 выполнено.
          -->
          <AccessibilityToggle />
          <UDropdownMenu v-if="isLoggedIn" :items="accountItems">
            <UButton
              color="neutral"
              variant="ghost"
              icon="i-lucide-user"
              trailing-icon="i-lucide-chevron-down"
              class="text-neutral-200 hover:text-white"
            >
              <!-- Длинный адрес переносим, а не прячем за многоточием. -->
              <span class="max-w-60 text-left break-all whitespace-normal">{{ userLabel(user) }}</span>
            </UButton>
          </UDropdownMenu>
          <UButton
            v-else
            to="/login"
            color="neutral"
            variant="ghost"
            icon="i-lucide-user"
            class="text-neutral-200 hover:text-white whitespace-nowrap"
          >
            Личный кабинет
          </UButton>
        </div>

        <div class="xl:hidden ml-auto flex items-center shrink-0">
          <AccessibilityToggle />
          <UButton
            class="shrink-0 text-neutral-200 hover:text-white"
            color="neutral"
            variant="ghost"
            size="xl"
            icon="i-lucide-menu"
            aria-label="Меню"
            :aria-expanded="menuOpen"
            @click="menuOpen = true"
          />
        </div>
      </UContainer>
    </header>

    <!-- Мобильное меню -->
    <USlideover
      v-model:open="menuOpen"
      title="Меню"
      :ui="{ content: 'max-w-xs w-full', body: 'p-0 sm:p-0' }"
    >
      <template #body>
        <nav class="py-2">
          <!-- Версия для слабовидящих — текстом: в шапке на неё остался
               только значок, а ГОСТ Р 52872-2019 требует понятной ссылки
               с любой страницы. -->
          <AccessibilityMenuItem />
          <template v-for="entry in menu?.header ?? []" :key="entry.title">
            <!-- Раздел шапки («Пассажирам») в панели раскрыт: в legacy
                 мобильное меню тоже было плоским списком ссылок. -->
            <template v-if="isMenuGroup(entry)">
              <div class="px-4 pt-4 pb-1 text-xs uppercase tracking-wide text-muted">
                {{ entry.title }}
              </div>
              <NuxtLink
                v-for="child in entry.children"
                :key="child.url"
                :to="child.url"
                class="flex items-center gap-3 px-4 py-3 text-base transition-colors"
                :class="isActive(child.url)
                  ? 'text-primary font-medium bg-primary/10'
                  : 'text-default hover:bg-elevated'"
              >
                <UIcon :name="iconFor(child.url)" class="size-5 shrink-0 text-dimmed" />
                {{ child.title }}
              </NuxtLink>
            </template>
            <NuxtLink
              v-else
              :to="entry.url"
              class="flex items-center gap-3 px-4 py-3 text-base transition-colors"
              :class="isActive(entry.url)
                ? 'text-primary font-medium bg-primary/10'
                : 'text-default hover:bg-elevated'"
            >
              <UIcon :name="iconFor(entry.url)" class="size-5 shrink-0 text-dimmed" />
              {{ entry.title }}
            </NuxtLink>
          </template>
        </nav>

        <USeparator />

        <div class="py-2">
          <NuxtLink
            v-if="standalone"
            to="/app"
            class="flex items-center gap-3 px-4 py-3 text-base transition-colors"
            :class="isActive('/app')
              ? 'text-primary font-medium bg-primary/10'
              : 'text-default hover:bg-elevated'"
          >
            <UIcon name="i-lucide-layout-grid" class="size-5 shrink-0 text-dimmed" />
            Главный экран
          </NuxtLink>

          <!-- Купленные билеты лежат на устройстве и открываются без сети,
               поэтому пункт нужен и гостю: кабинета у него нет, а билет
               в автобусе показать надо. -->
          <NuxtLink
            to="/tickets"
            class="flex items-center gap-3 px-4 py-3 text-base transition-colors"
            :class="isActive('/tickets')
              ? 'text-primary font-medium bg-primary/10'
              : 'text-default hover:bg-elevated'"
          >
            <UIcon name="i-lucide-ticket-check" class="size-5 shrink-0 text-dimmed" />
            Мои билеты <span class="text-xs text-dimmed">(без интернета)</span>
          </NuxtLink>

          <InstallAppButton />

          <template v-if="isLoggedIn">
            <div class="px-4 py-2 text-sm text-muted break-all">
              {{ userLabel(user) }}
            </div>
            <NuxtLink
              to="/lk"
              class="flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
            >
              <UIcon name="i-lucide-ticket" class="size-5 shrink-0 text-dimmed" />
              Мои билеты
            </NuxtLink>
            <NuxtLink
              to="/lk/passengers"
              class="flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
            >
              <UIcon name="i-lucide-users" class="size-5 shrink-0 text-dimmed" />
              Пассажиры
            </NuxtLink>
            <NuxtLink
              to="/lk/profile"
              class="flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
            >
              <UIcon name="i-lucide-settings" class="size-5 shrink-0 text-dimmed" />
              Профиль
            </NuxtLink>
            <button
              type="button"
              class="w-full flex items-center gap-3 px-4 py-3 text-base text-default hover:bg-elevated transition-colors"
              @click="signOut"
            >
              <UIcon name="i-lucide-log-out" class="size-5 shrink-0 text-dimmed" />
              Выйти
            </button>
          </template>
          <template v-else>
            <div class="px-4 py-3 grid gap-2">
              <UButton to="/login" block icon="i-lucide-log-in">
                Личный кабинет
              </UButton>
              <UButton to="/register" block color="neutral" variant="subtle">
                Регистрация
              </UButton>
            </div>
          </template>
        </div>

        <USeparator />

        <div class="px-4 py-4 text-sm text-muted space-y-1">
          <a href="tel:+74862721111" class="flex items-center gap-3 text-default">
            <UIcon name="i-lucide-phone-call" class="size-5 shrink-0 text-dimmed" />
            8 (4862) 72-11-11
          </a>
          <a href="mailto:support@orelavtotrans.ru" class="flex items-center gap-3 text-default">
            <UIcon name="i-lucide-mail" class="size-5 shrink-0 text-dimmed" />
            support@orelavtotrans.ru
          </a>
        </div>
      </template>
    </USlideover>

    <!-- Обслуживание и недоступность backend: одно сообщение на все
         страницы, а не пустые формы на каждой (см. useApiOutage). -->
    <ApiOutageNotice />

    <main class="flex-1">
      <slot />
    </main>

    <!--
      Напоминание о незавершённой покупке (ушёл на шлюз и не вернулся).
      Живёт в макете, а не на отдельной странице: покупатель возвращается
      на сайт откуда угодно — с главной, из расписания, по ссылке из письма.
    -->
    <PendingOrderNotice />

    <!-- PWA: «вышла новая версия» для приложения с домашнего экрана и
         предложение установить его (не чаще раза в неделю). -->
    <AppUpdateNotice />
    <InstallAppPrompt />

    <footer class="bg-neutral-900 text-neutral-400 mt-12">
      <UContainer class="py-8 grid gap-8 md:grid-cols-2">
        <div>
          <div class="text-white font-semibold mb-2">АО «Орелавтотранс»</div>
          <div class="text-sm mb-4">
            Официальный сайт автовокзалов Орла и Орловской области.<br>
            Покупка билетов онлайн без комиссии.
          </div>
          <!-- Меню подвала — перенос footer-nav legacy index.php. -->
          <nav v-if="menu?.footer?.length" class="text-sm columns-2 gap-6">
            <NuxtLink
              v-for="link in menu.footer"
              :key="link.url + link.title"
              :to="link.url"
              class="block py-1 hover:text-white transition-colors break-inside-avoid"
            >
              {{ link.title }}
            </NuxtLink>
          </nav>
        </div>
        <div class="md:text-right text-sm space-y-2">
          <div class="text-white font-semibold">
            Напишите нам
          </div>
          <!-- В legacy это была модалка «Написать сообщение» прямо в подвале;
               теперь у формы свой адрес — ссылкой можно поделиться. -->
          <div>
            <UButton
              to="/feedback"
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-mail"
            >
              Написать сообщение
            </UButton>
          </div>
          <div>
            <a href="mailto:support@orelavtotrans.ru" class="hover:text-white">support@orelavtotrans.ru</a>
            · 8 (4862) 72-11-11
          </div>
          <div>© {{ new Date().getFullYear() }}</div>
        </div>
      </UContainer>
    </footer>
  </div>
</template>
