<script setup>
import { ref, onMounted } from 'vue'
import { fetchReports } from './api'

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

onMounted(async () => {
  try {
    const d = await fetchReports()
    reports.value = d.reports || []
  } catch (e) {
    err.value = e.message
  }
})
</script>

<template>
  <div class="app">
    <header class="topbar">
      <button class="burger" @click="toggleNav" :title="collapsed ? 'Показать меню' : 'Скрыть меню'">☰</button>
      <div class="topbar-title">АИС · Отчёты</div>
    </header>

    <div class="layout" :class="{ 'nav-collapsed': collapsed }">
      <aside class="sidebar">
        <nav>
          <router-link class="nav-item" to="/">Все отчёты</router-link>
          <div class="nav-group-title">Отчёты</div>
          <router-link
            v-for="r in reports"
            :key="r.slug"
            class="nav-item"
            :to="`/${r.slug}`"
          >{{ r.title }}</router-link>
        </nav>
        <div v-if="err" class="nav-err">{{ err }}</div>
      </aside>
      <main class="content">
        <router-view />
      </main>
    </div>
  </div>
</template>
