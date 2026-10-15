import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/api/auth'
import type { AuthResponse, Role, User } from '@/types'

const ACCESS_KEY = 'wps_access_token'
const REFRESH_KEY = 'wps_refresh_token'

function readStorage(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function writeStorage(key: string, value: string | null): void {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch {
    /* storage недоступен — работаем только в памяти */
  }
}

export const useAuthStore = defineStore('auth', () => {
  const accessToken = ref<string | null>(readStorage(ACCESS_KEY))
  const refreshToken = ref<string | null>(readStorage(REFRESH_KEY))
  const user = ref<User | null>(null)
  /** Был ли уже выполнен начальный fetchMe */
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!accessToken.value)
  const role = computed<Role | null>(() => user.value?.role ?? null)
  const isAdmin = computed(() => role.value === 'admin')
  const canEdit = computed(() => role.value === 'admin' || role.value === 'editor')

  function applyTokens(resp: AuthResponse): void {
    accessToken.value = resp.access_token
    refreshToken.value = resp.refresh_token
    writeStorage(ACCESS_KEY, resp.access_token)
    writeStorage(REFRESH_KEY, resp.refresh_token)
    if (resp.user) user.value = resp.user
  }

  async function login(email: string, password: string): Promise<void> {
    const resp = await authApi.login(email, password)
    applyTokens(resp)
    if (!user.value) await fetchMe()
    initialized.value = true
  }

  async function refresh(): Promise<void> {
    if (!refreshToken.value) throw new Error('No refresh token')
    const resp = await authApi.refresh(refreshToken.value)
    applyTokens(resp)
  }

  async function fetchMe(): Promise<User | null> {
    if (!accessToken.value) return null
    try {
      user.value = await authApi.getMe()
    } catch {
      // при невалидном токене интерцептор уже выполнил logout
      if (!accessToken.value) user.value = null
    }
    return user.value
  }

  /** Вызывается один раз при старте приложения (из guard) */
  async function init(): Promise<void> {
    if (initialized.value) return
    initialized.value = true
    if (accessToken.value) await fetchMe()
  }

  function logout(): void {
    accessToken.value = null
    refreshToken.value = null
    user.value = null
    writeStorage(ACCESS_KEY, null)
    writeStorage(REFRESH_KEY, null)
  }

  function hasRole(roles?: Role[]): boolean {
    if (!roles || roles.length === 0) return true
    return !!role.value && roles.includes(role.value)
  }

  return {
    accessToken,
    refreshToken,
    user,
    initialized,
    isAuthenticated,
    role,
    isAdmin,
    canEdit,
    login,
    refresh,
    fetchMe,
    init,
    logout,
    hasRole,
  }
})
