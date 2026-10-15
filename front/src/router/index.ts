import { watch } from 'vue'
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { i18n, t } from '@/i18n'
import type { Role } from '@/types'

declare module 'vue-router' {
  interface RouteMeta {
    /** Страница доступна без авторизации */
    public?: boolean
    /** Разрешённые роли (пусто — все авторизованные) */
    roles?: Role[]
    /** Ключ перевода заголовка страницы (route.*) */
    titleKey?: `route.${string}`
  }
}

const EDITORS: Role[] = ['admin', 'editor']

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, titleKey: 'route.login' },
  },
  { path: '/', redirect: '/processes' },
  {
    path: '/processes',
    name: 'processes',
    component: () => import('@/views/ProcessesView.vue'),
    meta: { titleKey: 'route.processes' },
  },
  {
    path: '/processes/create',
    name: 'process-create',
    component: () => import('@/views/ProcessEditView.vue'),
    meta: { roles: EDITORS, titleKey: 'route.processCreate' },
  },
  {
    path: '/processes/:id(\\d+)',
    name: 'process-detail',
    component: () => import('@/views/ProcessDetailView.vue'),
    meta: { titleKey: 'route.processDetail' },
  },
  {
    path: '/processes/:id(\\d+)/edit',
    name: 'process-edit',
    component: () => import('@/views/ProcessEditView.vue'),
    meta: { roles: EDITORS, titleKey: 'route.processEdit' },
  },
  {
    path: '/executions/:id',
    name: 'execution-detail',
    component: () => import('@/views/ExecutionDetailView.vue'),
    meta: { titleKey: 'route.executionDetail' },
  },
  {
    path: '/users',
    name: 'users',
    component: () => import('@/views/UsersView.vue'),
    meta: { roles: ['admin'], titleKey: 'route.users' },
  },
  { path: '/:pathMatch(.*)*', redirect: '/processes' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.init()

  if (to.meta.public) {
    if (to.name === 'login' && auth.isAuthenticated && auth.user) {
      return { path: '/processes' }
    }
    return true
  }

  if (!auth.isAuthenticated) {
    return { path: '/login', query: to.fullPath !== '/processes' ? { redirect: to.fullPath } : undefined }
  }

  if (!auth.user) {
    await auth.fetchMe()
    if (!auth.isAuthenticated || !auth.user) {
      return { path: '/login' }
    }
  }

  if (!auth.hasRole(to.meta.roles)) {
    return { path: '/processes' }
  }
  return true
})

function updateDocumentTitle(): void {
  const key = router.currentRoute.value.meta.titleKey
  document.title = key ? `${t(key)} — WPS` : 'WPS'
}

router.afterEach(updateDocumentTitle)
watch(i18n.global.locale, updateDocumentTitle)

export default router
