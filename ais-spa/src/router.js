import { createRouter, createWebHistory } from 'vue-router'
import BackTicketView from './views/BackTicketView.vue'
import ContactsView from './views/ContactsView.vue'
import HomeView from './views/HomeView.vue'
import ReportView from './views/ReportView.vue'

// Маршрутизация без # (history mode): / — список отчётов, /<slug> — отчёт.
// Админские страницы объявляем ДО catch-all, иначе их съест ReportView:
// /back-ticket — возврат билета (legacy back_bilet.php),
// /contacts — контакты сайта (legacy avtovokzal57/url/contacts.php).
const routes = [
  { path: '/', component: HomeView },
  { path: '/back-ticket', component: BackTicketView },
  { path: '/contacts', component: ContactsView },
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
