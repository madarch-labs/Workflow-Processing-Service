import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Role } from '@/types'

declare module 'vue-router' {
  interface RouteMeta {
    /** Страница доступна без авторизации */
    public?: boolean
    /** Разрешённые роли (пусто — все авторизованные) */
    roles?: Role[]
    title?: string
  }
}

const EDITORS: Role[] = ['admin', 'editor']

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, title: 'Вход' },
  },
  { path: '/', redirect: '/processes' },
  {
    path: '/processes',
    name: 'processes',
    component: () => import('@/views/ProcessesView.vue'),
    meta: { title: 'Процессы' },
  },
  {
    path: '/processes/create',
    name: 'process-create',
    component: () => import('@/views/ProcessEditView.vue'),
    meta: { roles: EDITORS, title: 'Новый процесс' },
  },
  {
    path: '/processes/:id(\\d+)',
    name: 'process-detail',
    component: () => import('@/views/ProcessDetailView.vue'),
    meta: { title: 'Процесс' },
  },
  {
    path: '/processes/:id(\\d+)/edit',
    name: 'process-edit',
    component: () => import('@/views/ProcessEditView.vue'),
    meta: { roles: EDITORS, title: 'Редактирование процесса' },
  },
  {
    path: '/executions/:id',
    name: 'execution-detail',
    component: () => import('@/views/ExecutionDetailView.vue'),
    meta: { title: 'Выполнение' },
  },
  {
    path: '/users',
    name: 'users',
    component: () => import('@/views/UsersView.vue'),
    meta: { roles: ['admin'], title: 'Пользователи' },
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

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — WPS` : 'WPS'
})

export default router
