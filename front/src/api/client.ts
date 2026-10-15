import axios, {
  AxiosError,
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiEnvelope, ApiError, Paginated, PaginationMeta } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/errors'
import { currentLocale } from '@/i18n'

declare module 'axios' {
  interface AxiosRequestConfig {
    /** Не показывать ElMessage при ошибке (вызывающий код обработает сам) */
    silent?: boolean
    /** Внутренний флаг: запрос уже повторялся после refresh */
    _retry?: boolean
  }
}

/** Ошибка API в нормализованном виде */
export class ApiRequestError extends Error {
  status: number
  code: string
  details: ApiError['details']

  constructor(status: number, error: ApiError) {
    super(error.message)
    this.name = 'ApiRequestError'
    this.status = status
    this.code = error.code
    this.details = error.details ?? null
  }
}

export const http = axios.create({
  baseURL: '/api',
  timeout: 30000,
  headers: { 'Content-Type': 'application/json' },
})

// ---------- обработчик «сессия потеряна» (устанавливается в main.ts, чтобы избежать циклических импортов с router) ----------
let onSessionExpired: () => void = () => {
  window.location.href = '/login'
}
export function setSessionExpiredHandler(fn: () => void): void {
  onSessionExpired = fn
}

// ---------- request: Bearer-токен ----------
http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const auth = useAuthStore()
  if (auth.accessToken && !isAuthEndpoint(config.url)) {
    config.headers.set('Authorization', `Bearer ${auth.accessToken}`)
  }
  config.headers.set('Accept-Language', currentLocale())
  return config
})

function isAuthEndpoint(url?: string): boolean {
  if (!url) return false
  return /\/auth\/(login|refresh)$/.test(url.split('?')[0])
}

// ---------- refresh с очередью ----------
let refreshPromise: Promise<string> | null = null

function refreshAccessToken(): Promise<string> {
  if (!refreshPromise) {
    const auth = useAuthStore()
    refreshPromise = auth
      .refresh()
      .then(() => auth.accessToken as string)
      .finally(() => {
        refreshPromise = null
      })
  }
  return refreshPromise
}

function toApiError(error: AxiosError): ApiRequestError {
  const status = error.response?.status ?? 0
  const body = error.response?.data as Partial<ApiEnvelope<unknown>> | undefined
  if (body && typeof body === 'object' && body.error) {
    return new ApiRequestError(status, body.error)
  }
  if (!error.response) {
    return new ApiRequestError(0, {
      code: 'NETWORK_ERROR',
      message: 'Server is unavailable',
    })
  }
  return new ApiRequestError(status, {
    code: 'HTTP_' + status,
    message: `HTTP ${status}`,
  })
}

http.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const original = error.config as AxiosRequestConfig | undefined
    const status = error.response?.status

    if (status === 401 && original && !original._retry && !isAuthEndpoint(original.url)) {
      const auth = useAuthStore()
      if (auth.refreshToken) {
        original._retry = true
        try {
          const token = await refreshAccessToken()
          original.headers = { ...(original.headers ?? {}), Authorization: `Bearer ${token}` }
          return http(original)
        } catch {
          auth.logout()
          onSessionExpired()
          return Promise.reject(
            new ApiRequestError(401, { code: 'SESSION_EXPIRED', message: 'Session expired' }),
          )
        }
      }
      auth.logout()
      onSessionExpired()
      return Promise.reject(toApiError(error))
    }

    const apiError = toApiError(error)
    if (!original?.silent) {
      showApiError(apiError)
    }
    return Promise.reject(apiError)
  },
)

/** Показать ошибку API пользователю (для 422 — с деталями) */
export function showApiError(err: unknown): void {
  const text = formatApiError(err)
  ElMessage({
    type: 'error',
    message: text,
    duration: text.includes('\n') ? 8000 : 4000,
    showClose: true,
    customClass: 'api-error-message',
  })
}

// ---------- распаковка конверта ----------
function unwrap<T>(response: AxiosResponse): T {
  const body = response.data as ApiEnvelope<T> | T
  if (body && typeof body === 'object' && 'success' in (body as object) && 'data' in (body as object)) {
    return (body as ApiEnvelope<T>).data
  }
  return body as T
}

function unwrapPage<T>(response: AxiosResponse): Paginated<T> {
  const body = response.data as ApiEnvelope<T[]>
  const items = Array.isArray(body?.data) ? body.data : []
  const meta: PaginationMeta = body?.meta ?? { page: 1, per_page: items.length, total: items.length }
  return { items, meta }
}

export async function apiGet<T>(url: string, params?: object, config?: AxiosRequestConfig): Promise<T> {
  return unwrap<T>(await http.get(url, { ...config, params: cleanParams(params) }))
}

export async function apiGetPage<T>(url: string, params?: object, config?: AxiosRequestConfig): Promise<Paginated<T>> {
  return unwrapPage<T>(await http.get(url, { ...config, params: cleanParams(params) }))
}

export async function apiPost<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
  return unwrap<T>(await http.post(url, data, config))
}

/** POST с доступом к HTTP-статусу (нужно для /run: 201 vs 200) */
export async function apiPostWithStatus<T>(
  url: string,
  data?: unknown,
  config?: AxiosRequestConfig,
): Promise<{ status: number; data: T }> {
  const response = await http.post(url, data, config)
  return { status: response.status, data: unwrap<T>(response) }
}

export async function apiPut<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
  return unwrap<T>(await http.put(url, data, config))
}

export async function apiDelete<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  return unwrap<T>(await http.delete(url, config))
}

/** Убирает пустые параметры запроса */
function cleanParams(params?: object): Record<string, unknown> | undefined {
  if (!params) return undefined
  const result: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue
    result[k] = v
  }
  return result
}
