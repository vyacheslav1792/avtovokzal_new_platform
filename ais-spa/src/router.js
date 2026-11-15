import { createRouter, createWebHistory } from 'vue-router'
import BackTicketView from './views/BackTicketView.vue'
import BlocksView from './views/BlocksView.vue'
import ContactsView from './views/ContactsView.vue'
import DataSyncView from './views/DataSyncView.vue'
import EmployeesView from './views/EmployeesView.vue'
import FeedbackView from './views/FeedbackView.vue'
import HomeView from './views/HomeView.vue'
import MenuView from './views/MenuView.vue'
import NewsEditView from './views/NewsEditView.vue'
import NewsListView from './views/NewsListView.vue'
import PageEditView from './views/PageEditView.vue'
import PagesView from './views/PagesView.vue'
import ProfileView from './views/ProfileView.vue'
import ReportView from './views/ReportView.vue'
import RolesView from './views/RolesView.vue'
import SettingsView from './views/SettingsView.vue'
import SpamRulesView from './views/SpamRulesView.vue'

// Маршрутизация без # (history mode): / — список отчётов, /<slug> — отчёт.
// Админские страницы объявляем ДО catch-all, иначе их съест ReportView:
// /back-ticket — возврат билета (legacy back_bilet.php),
// /contacts — контакты сайта (legacy avtovokzal57/url/contacts.php),
// /pages и /menu — страницы и меню сайта (legacy url/<slug>.php + index.php),
// /news — новости и объявления, /blocks — блоки сайта (legacy vidjets/*.php),
// /settings — выключатели продажи и возврата билетов,
// /sync — немедленное обновление расписания и пунктов назначения,
// /feedback и /spam-rules — обращения с сайта и антиспам-словарь
// (legacy url/contact.php: письмо на почту и словарь в исходнике),
// /employees, /roles и /profile — сотрудники, роли и свой профиль
// (в legacy авторизации не было вовсе, переносить нечего).
//
// Прав маршруты не проверяют: это делает сервер на каждом запросе, а меню
// показывает только доступное (см. App.vue). Проверять в двух местах значит
// расходиться с сервером при первой же правке роли.
const routes = [
  { path: '/', component: HomeView },
  { path: '/back-ticket', component: BackTicketView },
  { path: '/contacts', component: ContactsView },
  { path: '/pages', component: PagesView },
  { path: '/pages/:id', component: PageEditView, props: true },
  { path: '/news', component: NewsListView },
  { path: '/news/:id', component: NewsEditView, props: true },
  { path: '/blocks', component: BlocksView },
  { path: '/settings', component: SettingsView },
  { path: '/sync', component: DataSyncView },
  { path: '/menu', component: MenuView },
  { path: '/feedback', component: FeedbackView },
  { path: '/spam-rules', component: SpamRulesView },
  { path: '/employees', component: EmployeesView },
  { path: '/roles', component: RolesView },
  { path: '/profile', component: ProfileView },
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
