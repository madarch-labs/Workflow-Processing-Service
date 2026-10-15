import { apiGet, apiPost } from './client'
import type { AuthResponse, User } from '@/types'

export const authApi = {
  login(email: string, password: string): Promise<AuthResponse> {
    // ошибку логина показывает сама форма
    return apiPost<AuthResponse>('/auth/login', { email, password }, { silent: true })
  },
  refresh(refreshToken: string): Promise<AuthResponse> {
    return apiPost<AuthResponse>('/auth/refresh', { refresh_token: refreshToken }, { silent: true })
  },
  getMe(): Promise<User> {
    return apiGet<User>('/auth/me', undefined, { silent: true })
  },
}
