<script setup>
// Главная АИС: что доступно вошедшему сотруднику. Отчёты сервер отдаёт уже
// отфильтрованными по правам, разделы администрирования отбираем по тем же
// правам, что и пункты меню.
import { computed, onMounted, ref } from 'vue'
import { fetchReports } from '../api'
import { can } from '../auth'

const reports = ref([])
const err = ref('')

const SECTIONS = [
  { to: '/contacts', title: 'Контакты сайта', group: 'Администрирование', permission: 'contacts.view' },
  { to: '/pages', title: 'Страницы сайта', group: 'Администрирование', permission: 'pages.view' },
  { to: '/menu', title: 'Меню сайта', group: 'Администрирование', permission: 'menu.view' },
  { to: '/feedback', title: 'Обращения с сайта', group: 'Администрирование', permission: 'feedback.view' },
  { to: '/spam-rules', title: 'Антиспам-словарь', group: 'Администрирование', permission: 'spam-rules.view' },
  { to: '/back-ticket', title: 'Возврат билета', group: 'Продажи и возвраты', permission: 'back-ticket.inspect' },
  { to: '/employees', title: 'Сотрудники', group: 'Сотрудники и права', permission: 'employees.view' },
  { to: '/roles', title: 'Роли и права', group: 'Сотрудники и права', permission: 'roles.view' },
]

const sections = computed(() => SECTIONS.filter((s) => can(s.permission)))
const empty = computed(() => !reports.value.length && !sections.value.length)

onMounted(async () => {
  document.title = 'АИС · Отчёты'
  try {
    const d = await fetchReports()
    reports.value = d.reports || []
  } catch (e) {
    err.value = e.message
  }
})
</script>

<template>
  <div>
    <h1>АИС</h1>
    <div v-if="err" class="alert">{{ err }}</div>

    <!-- Сотрудник без ролей входит, но не видит ничего: об этом лучше
         сказать прямо, иначе пустая страница читается как поломка. -->
    <div v-if="empty" class="alert warn">
      Вам пока не назначено ни одной роли, поэтому разделы недоступны.
      Обратитесь к администратору АИС.
    </div>

    <template v-if="reports.length">
      <p class="muted">Отчёты. По мере переноса сюда добавятся остальные.</p>
      <div class="cards">
        <router-link v-for="r in reports" :key="r.slug" class="card" :to="`/${r.slug}`">
          <div class="card-title">{{ r.title }}</div>
          <div class="card-group" v-if="r.group">{{ r.group }}</div>
        </router-link>
      </div>
    </template>

    <template v-if="sections.length">
      <h2 class="home-sub">Разделы</h2>
      <div class="cards">
        <router-link v-for="s in sections" :key="s.to" class="card" :to="s.to">
          <div class="card-title">{{ s.title }}</div>
          <div class="card-group">{{ s.group }}</div>
        </router-link>
      </div>
    </template>
  </div>
</template>
