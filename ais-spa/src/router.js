import { createRouter, createWebHistory } from 'vue-router'
import BackTicketView from './views/BackTicketView.vue'
import HomeView from './views/HomeView.vue'
import ReportView from './views/ReportView.vue'

// Маршрутизация без # (history mode): / — список отчётов, /<slug> — отчёт.
// /back-ticket — до catch-all: админский возврат билета (legacy back_bilet.php).
const routes = [
  { path: '/', component: HomeView },
  { path: '/back-ticket', component: BackTicketView },
  { path: '/:slug', component: ReportView, props: true },
]

const router = createRouter({
  history: createWebHistory('/'),
  routes,
})

// Заголовок вкладки; для отчёта его уточнит ReportView после загрузки meta.
router.afterEach((to) => {
  document.title = to.path === '/' ? 'АИС · Отчёты' : 'АИС'
})

export default router
