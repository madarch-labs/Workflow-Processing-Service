import { defineStore } from 'pinia'
import { ref } from 'vue'
import { processesApi } from '@/api/processes'
import type {
  Condition,
  ConditionGroup,
  ModifyAction,
  ModifyRow,
  PaginationMeta,
  Process,
  ProcessAction,
  ProcessDefinition,
  ProcessFormData,
  ProcessListFilters,
  ProcessPayload,
  SaveAction,
  StartProcessAction,
  StartProcessRow,
} from '@/types'
import { rowKey } from '@/utils/keys'

export function emptyForm(): ProcessFormData {
  return {
    id: null,
    name: '',
    object_type: '',
    is_child: false,
    is_active: false,
    priority: 0,
    run_once: false,
    conditions: [],
    conditionsRaw: null,
    preload: [],
    modifyActions: [],
    startActions: [],
  }
}

/** Process → данные формы редактора (actions раскладываются по панелям) */
export function formFromProcess(p: Process): ProcessFormData {
  const def: Partial<ProcessDefinition> = p.definition ?? {}
  const modifyActions: ModifyRow[] = []
  const startActions: StartProcessRow[] = []

  for (const action of def.actions ?? []) {
    if (!action || typeof action !== 'object') continue
    if (action.type === 'start_process') {
      const sp = action as StartProcessAction
      const ids: number[] = []
      if (sp.process_id !== undefined && sp.process_id !== null) ids.push(Number(sp.process_id))
      if (Array.isArray(sp.process_ids)) ids.push(...sp.process_ids.map(Number))
      for (const id of ids) startActions.push({ _key: rowKey(), process_id: id })
    } else if (action.type === 'modify') {
      const m = action as ModifyAction
      modifyActions.push({
        _key: rowKey(),
        type: 'modify',
        target: m.target ?? '',
        change_type: m.change_type ?? 'set_value',
        value: m.value === undefined || m.value === null ? '' : String(m.value),
        save_to_crm: !!m.save_to_crm,
      })
    } else if (action.type === 'save_object') {
      const s = action as SaveAction
      modifyActions.push({ _key: rowKey(), type: 'save_object', target: s.target ?? '', module: s.module ?? '' })
    } else {
      // неизвестные/нередактируемые типы (например preload внутри actions) — сохраняем без изменений
      modifyActions.push({ _key: rowKey(), type: 'passthrough', raw: JSON.parse(JSON.stringify(action)) })
    }
  }

  const rawConditions = def.conditions
  const isArray = Array.isArray(rawConditions)

  return {
    id: p.id,
    name: p.name,
    object_type: p.object_type,
    is_child: !!p.is_child,
    is_active: !!p.is_active,
    priority: p.priority ?? 0,
    run_once: !!p.run_once,
    conditions: isArray
      ? (rawConditions as Condition[]).map((c) => ({
          field: c.field ?? '',
          operator: c.operator ?? 'equals',
          value_type: c.value_type ?? 'static',
          value: c.value === undefined || c.value === null ? '' : String(c.value),
        }))
      : [],
    conditionsRaw: !isArray && rawConditions ? (rawConditions as ConditionGroup) : null,
    preload: (def.preload ?? []).map((pr) => ({
      var: pr.var ?? '',
      source: pr.source ?? 'object_field',
      module: pr.module ?? '',
      by_field: pr.by_field || 'id',
      value: pr.value === undefined || pr.value === null ? '' : String(pr.value),
    })),
    modifyActions,
    startActions,
  }
}

/** Данные формы → definition */
export function definitionFromForm(form: ProcessFormData): ProcessDefinition {
  const actions: ProcessAction[] = []
  for (const row of form.modifyActions) {
    if (row.type === 'modify') {
      actions.push({
        type: 'modify',
        target: row.target.trim(),
        change_type: row.change_type,
        value: row.value,
        save_to_crm: row.save_to_crm,
      })
    } else if (row.type === 'save_object') {
      const a: SaveAction = { type: 'save_object', target: row.target.trim() }
      if (row.module && row.module.trim()) a.module = row.module.trim()
      actions.push(a)
    } else {
      actions.push(row.raw)
    }
  }
  for (const s of form.startActions) {
    if (s.process_id !== null && s.process_id !== undefined) {
      actions.push({ type: 'start_process', process_id: s.process_id })
    }
  }

  return {
    conditions:
      form.conditionsRaw ??
      form.conditions.map((c) => ({
        field: c.field.trim(),
        operator: c.operator,
        value_type: c.value_type,
        value: c.value,
      })),
    preload: form.preload.map((p) => ({
      var: p.var.trim(),
      source: p.source,
      module: p.module.trim(),
      by_field: p.by_field.trim() || 'id',
      value: p.value,
    })),
    actions,
  }
}

export function payloadFromForm(form: ProcessFormData, activate = false): ProcessPayload {
  return {
    name: form.name.trim(),
    object_type: form.object_type.trim(),
    is_child: form.is_child,
    is_active: activate ? true : form.is_active,
    priority: Number(form.priority) || 0,
    run_once: form.run_once,
    definition: definitionFromForm(form),
  }
}

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
