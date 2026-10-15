import { apiDelete, apiGetPage, apiPost, apiPut } from './client'
import type { Paginated, User, UserPayload } from '@/types'

export const usersApi = {
  list(page = 1, perPage = 20): Promise<Paginated<User>> {
    return apiGetPage<User>('/users', { page, per_page: perPage })
  },
  create(payload: UserPayload): Promise<User> {
    return apiPost<User>('/users', payload)
  },
  update(id: number, payload: UserPayload): Promise<User> {
    return apiPut<User>(`/users/${id}`, payload)
  },
  remove(id: number): Promise<{ deleted: boolean }> {
    return apiDelete<{ deleted: boolean }>(`/users/${id}`)
  },
}
