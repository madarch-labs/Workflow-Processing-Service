import { apiGet, apiGetPage } from './client'
import type { Execution, ExecutionAction, ExecutionDetail, ExecutionFilters, Paginated } from '@/types'

export const executionsApi = {
  listByProcess(processId: number | string, filters: ExecutionFilters = {}): Promise<Paginated<Execution>> {
    return apiGetPage<Execution>(`/processes/${processId}/executions`, filters)
  },
  get(id: string, silent = false): Promise<ExecutionDetail> {
    return apiGet<ExecutionDetail>(`/executions/${id}`, undefined, { silent })
  },
  actions(id: string): Promise<ExecutionAction[]> {
    return apiGet<ExecutionAction[]>(`/executions/${id}/actions`)
  },
}
