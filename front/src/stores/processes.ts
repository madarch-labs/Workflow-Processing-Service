import { defineStore } from 'pinia'
import { ref } from 'vue'
import { processesApi } from '@/api/processes'
import type { PaginationMeta, Process, ProcessFormData, ProcessListFilters } from '@/types'
import { emptyForm, formFromProcess, payloadFromForm } from '@/utils/processForm'

export { emptyForm, formFromProcess, definitionFromForm, payloadFromForm } from '@/utils/processForm'

export const useProcessesStore = defineStore('processes', () => {
  const processes = ref<Process[]>([])
  const meta = ref<PaginationMeta>({ page: 1, per_page: 20, total: 0 })
  const loading = ref(false)
  const currentProcess = ref<Process | null>(null)

  async function fetchList(filters: ProcessListFilters): Promise<void> {
    loading.value = true
    try {
      const page = await processesApi.list(filters)
      processes.value = page.items
      meta.value = page.meta
    } finally {
      loading.value = false
    }
  }

  async function fetchOne(id: number | string): Promise<Process> {
    const p = await processesApi.get(id)
    currentProcess.value = p
    return p
  }

  async function saveProcess(form: ProcessFormData, activate = false): Promise<Process> {
    const payload = payloadFromForm(form, activate)
    const saved = form.id ? await processesApi.update(form.id, payload) : await processesApi.create(payload)
    currentProcess.value = saved
    return saved
  }

  return {
    processes,
    meta,
    loading,
    currentProcess,
    fetchList,
    fetchOne,
    saveProcess,
    emptyForm,
    formFromProcess,
  }
})
