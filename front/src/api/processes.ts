import { apiDelete, apiGet, apiGetPage, apiPost, apiPostWithStatus, apiPut } from './client'
import type { Paginated, Process, ProcessListFilters, ProcessObjectRun, ProcessPayload, RunResult } from '@/types'

export const processesApi = {
  list(filters: ProcessListFilters = {}): Promise<Paginated<Process>> {
    return apiGetPage<Process>('/processes', filters)
  },
  /** Все ведомые процессы (для панели «Запустить следующий процесс») */
  async listChildren(): Promise<Process[]> {
    const page = await apiGetPage<Process>('/processes', { is_child: true, per_page: 1000 })
    return page.items
  },
  get(id: number | string): Promise<Process> {
    return apiGet<Process>(`/processes/${id}`)
  },
  create(payload: ProcessPayload): Promise<Process> {
    // ошибки валидации показывает редактор
    return apiPost<Process>('/processes', payload, { silent: true })
  },
  update(id: number | string, payload: ProcessPayload): Promise<Process> {
    return apiPut<Process>(`/processes/${id}`, payload, { silent: true })
  },
  remove(id: number | string): Promise<{ deleted: boolean }> {
    return apiDelete<{ deleted: boolean }>(`/processes/${id}`)
  },
  clone(id: number | string): Promise<Process> {
    return apiPost<Process>(`/processes/${id}/clone`)
  },
  /** Отметки однократного запуска процесса */
  listRuns(id: number | string, params: { object_id?: string; page?: number; per_page?: number } = {}): Promise<Paginated<ProcessObjectRun>> {
    return apiGetPage<ProcessObjectRun>(`/processes/${id}/runs`, params)
  },
  /** Снять отметку: следующее событие записи снова запустит процесс */
  resetRun(id: number | string, objectType: string, objectId: string): Promise<{ deleted: boolean }> {
    return apiDelete<{ deleted: boolean }>(
      `/processes/${id}/runs/${encodeURIComponent(objectType)}/${encodeURIComponent(objectId)}`,
    )
  },
  run(id: number | string, object: unknown, force: boolean): Promise<{ status: number; data: RunResult }> {
    return apiPostWithStatus<RunResult>(`/processes/${id}/run`, { object, force })
  },
}
