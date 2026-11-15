<script setup>
import { ref, watch } from 'vue'
import ConfirmCodeDialog from './components/ConfirmCodeDialog.vue'
import LoginView from './views/LoginView.vue'
import { fetchReports } from './api'
import { authReady, can, canAny, employee, isAuthenticated, signOut } from './auth'

const reports = ref([])
const err = ref('')

// Начальное состояние определяется тем, КАК открыли приложение:
// индексная страница (/v2/) — меню развёрнуто; прямая ссылка на отчёт — свёрнуто.
// Путь берём из location (он известен сразу), т.к. роутер разрешает маршрут
// асинхронно и route.path на этот момент ещё равен стартовому '/'.
// Дальше пользователь управляет вручную кнопкой ☰ (навигация состояние не меняет).
const base = import.meta.env.BASE_URL.replace(/\/+$/, '')
const path = window.location.pathname.replace(/\/+$/, '')
const collapsed = ref(path !== base)

function toggleNav() {
  collapsed.value = !collapsed.value
}

// Список отчётов сервер уже отфильтровал по правам сотрудника: чего нет в
// меню, того для него и не существует.
async function loadReports() {
  try {
    const d = await fetchReports()
    reports.value = d.reports || []
  } catch (e) {
    err.value = e.message
  }
}

async function logout() {
  await signOut()
  reports.value = []
}

// Список отчётов спрашиваем только у вошедшего: до входа запрос вернул бы
// 401, и в меню осталось бы сообщение об ошибке.
watch(isAuthenticated, (yes) => {
  if (yes) loadReports()
}, { immediate: true })
</script>

<template>
  <!-- Пока сервер не ответил, кто вошёл, не показываем ни меню, ни форму
       входа: иначе форма мигала бы при каждой перезагрузке у вошедшего. -->
  <div v-if="!authReady" class="boot muted">Загрузка…</div>

  <LoginView v-else-if="!isAuthenticated" />

  <div v-else class="app">
    <header class="topbar">
      <button class="burger" @click="toggleNav" :title="collapsed ? 'Показать меню' : 'Скрыть меню'">☰</button>
      <div class="topbar-title">АИС</div>
      <div class="topbar-user">
        <router-link class="topbar-name" to="/profile" :title="employee.position || 'Профиль'">
          {{ employee.full_name || employee.login }}
        </router-link>
        <button class="btn small" type="button" @click="logout">Выйти</button>
      </div>
    </header>

    <div class="layout" :class="{ 'nav-collapsed': collapsed }">
      <aside class="sidebar">
        <nav>
          <router-link class="nav-item" to="/">Все отчёты</router-link>

          <template v-if="reports.length">
            <div class="nav-group-title">Отчёты</div>
            <router-link
              v-for="r in reports"
              :key="r.slug"
              class="nav-item"
              :to="`/${r.slug}`"
            >{{ r.title }}</router-link>
          </template>

          <!-- Не только отчёты: сюда переезжают админские функции сайта.
               Пункт показывается, если у сотрудника есть право его открыть. -->
          <template v-if="canAny('contacts.view', 'pages.view', 'news.view', 'blocks.view', 'menu.view', 'feedback.view', 'spam-rules.view')">
            <div class="nav-group-title">Администрирование</div>
            <router-link v-if="can('contacts.view')" class="nav-item" to="/contacts">Контакты сайта</router-link>
            <router-link v-if="can('pages.view')" class="nav-item" to="/pages">Страницы сайта</router-link>
            <router-link v-if="can('news.view')" class="nav-item" to="/news">Новости сайта</router-link>
            <router-link v-if="can('blocks.view')" class="nav-item" to="/blocks">Блоки сайта</router-link>
            <router-link v-if="can('menu.view')" class="nav-item" to="/menu">Меню сайта</router-link>
            <router-link v-if="can('feedback.view')" class="nav-item" to="/feedback">Обращения с сайта</router-link>
            <router-link v-if="can('spam-rules.view')" class="nav-item" to="/spam-rules">Антиспам-словарь</router-link>
          </template>

          <!-- Выключатели сайта стоят отдельным разделом: это не содержимое,
               а то, работает ли продажа билетов. -->
          <template v-if="canAny('settings.view', 'data-sync.view')">
            <div class="nav-group-title">Настройки</div>
            <router-link v-if="can('settings.view')" class="nav-item" to="/settings">Настройки сайта</router-link>
            <router-link v-if="can('data-sync.view')" class="nav-item" to="/sync">Обновление данных</router-link>
          </template>

          <template v-if="canAny('employees.view', 'roles.view')">
            <div class="nav-group-title">Сотрудники и права</div>
            <router-link v-if="can('employees.view')" class="nav-item" to="/employees">Сотрудники</router-link>
            <router-link v-if="can('roles.view')" class="nav-item" to="/roles">Роли и права</router-link>
          </template>

          <div class="nav-group-title">Моё</div>
          <router-link class="nav-item" to="/profile">Профиль</router-link>
        </nav>
        <div v-if="err" class="nav-err">{{ err }}</div>
      </aside>
      <main class="content">
        <router-view />
      </main>
    </div>

    <ConfirmCodeDialog />
  </div>
</template>
