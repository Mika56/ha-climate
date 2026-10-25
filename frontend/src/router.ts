import { createRouter, createWebHashHistory, type RouterHistory } from 'vue-router'

/** Hash mode: the ingress path is dynamic, so the fragment is the only part we can own. */
export function createAppRouter(history: RouterHistory = createWebHashHistory()) {
  return createRouter({
    history,
    routes: [
      { path: '/', name: 'overview', component: () => import('@/pages/OverviewPage.vue') },
      // `/rooms`, `/rooms/:id` and `/rooms/new` share one page (list + editor).
      { path: '/rooms/:id?', name: 'rooms', component: () => import('@/pages/RoomsPage.vue') },
      {
        path: '/schedules/:id?',
        name: 'schedules',
        component: () => import('@/pages/SchedulesPage.vue'),
      },
      { path: '/settings', name: 'settings', component: () => import('@/pages/SettingsPage.vue') },
      {
        path: '/notifications',
        name: 'notifications',
        component: () => import('@/pages/NotificationsPage.vue'),
      },
      { path: '/history', name: 'history', component: () => import('@/pages/HistoryPage.vue') },
      { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
  })
}
