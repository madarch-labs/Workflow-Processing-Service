// ===== Общие =====

export type Role = 'admin' | 'editor' | 'viewer'

export interface ApiErrorDetail {
  field: string
  message: string
}

export interface ApiError {
  code: string
  message: string
  details?: ApiErrorDetail[] | Record<string, unknown> | null
}

export interface PaginationMeta {
  page: number
  per_page: number
  total: number
}

export interface ApiEnvelope<T> {
  success: boolean
  data: T
  error: ApiError | null
  meta?: PaginationMeta
}

export interface Paginated<T> {
  items: T[]
  meta: PaginationMeta
}

export interface DictionaryItem {
  value: string
  label: string
  description?: string
}

// ===== Пользователи / авторизация =====

export interface User {
  id: number
  email: string
  role: Role
  created_at?: string
  updated_at?: string
}

export interface AuthResponse {
  access_token: string
  refresh_token: string
  token_type: string
  expires_in: number
  user: User
}

export interface UserPayload {
  email?: string
  password?: string
  role?: Role
}

// ===== Определение процесса =====

export type ConditionOperator = 'equals' | 'not_equals' | 'contains' | 'not_contains' | string
export type ConditionValueType = 'static' | 'from_object_field'

export interface Condition {
  field: string
  operator: ConditionOperator
  value_type: ConditionValueType
  value: string
}

/** Вложенная форма условий (будущая OR-логика) */
export interface ConditionGroup {
  logical_operator: 'AND' | 'OR'
  conditions: Condition[]
  groups?: ConditionGroup[]
}

export type PreloadSource = 'object_field' | 'variable_field' | 'manual'

export interface PreloadAction {
  var: string
  source: PreloadSource
  module: string
  by_field: string
  value: string
}

export type ChangeType = 'set_value' | 'set_from_variable' | 'calculate'

export interface ModifyAction {
  type: 'modify'
  target: string
  change_type: ChangeType
  value: string
  save_to_crm: boolean
}

export interface SaveAction {
  type: 'save_object'
  target: string
  module?: string
}

export interface StartProcessAction {
  type: 'start_process'
  process_id?: number
  process_ids?: number[]
}

export interface PreloadInActions extends PreloadAction {
  type: 'preload'
}

/** Действие неизвестного (или не редактируемого в UI) типа — сохраняется как есть */
export interface UnknownAction {
  type: string
  [key: string]: unknown
}

export type ProcessAction =
  | ModifyAction
  | SaveAction
  | StartProcessAction
  | PreloadInActions
  | UnknownAction

export interface ProcessDefinition {
  conditions: Condition[] | ConditionGroup
  preload: PreloadAction[]
  actions: ProcessAction[]
}

export interface ProcessRef {
  id: number
  name: string
  is_active?: boolean
  is_child?: boolean
}

export interface Process {
  id: number
  name: string
  object_type: string
  is_child: boolean
  is_active: boolean
  priority: number
  /** Однократный запуск: не более одного запуска процесса на запись CRM */
  run_once: boolean
  version: number
  definition: ProcessDefinition
  created_at: string
  updated_at: string
  references?: {
    children: ProcessRef[]
    parents: ProcessRef[]
  }
}

export interface ProcessPayload {
  name: string
  object_type: string
  is_child: boolean
  is_active?: boolean
  priority?: number
  run_once?: boolean
  definition: ProcessDefinition
}

export interface ProcessListFilters {
  search?: string
  object_type?: string
  is_child?: boolean | ''
  is_active?: boolean | ''
  page?: number
  per_page?: number
}

// ===== Форма редактора =====

/** Строка панели «Изменить объект/переменную»: modify, save_object или «чужое» действие (preload и т.п.), которое нужно сохранить без потерь */
export type ModifyRow =
  | (ModifyAction & { _key: string })
  | (SaveAction & { _key: string })
  | { type: 'passthrough'; _key: string; raw: ProcessAction }

export interface StartProcessRow {
  _key: string
  process_id: number | null
}

export interface ProcessFormData {
  id: number | null
  name: string
  object_type: string
  is_child: boolean
  is_active: boolean
  priority: number
  run_once: boolean
  conditions: Condition[]
  /** Если условия пришли во вложенной форме (OR/группы) — храним как есть, UI их не редактирует */
  conditionsRaw: ConditionGroup | null
  preload: PreloadAction[]
  modifyActions: ModifyRow[]
  startActions: StartProcessRow[]
}

// ===== Запуск =====

export interface ConditionTrace {
  field: string
  operator: string
  value_type: string
  value: unknown
  actual: unknown
  expected: unknown
  result: boolean | null
}

export interface RunResult {
  conditions_met: boolean
  execution_id: string | null
  conditions: ConditionTrace[]
  /** Проверка однократного запуска выполнялась (у процесса run_once и у объекта есть id) */
  run_once_applied?: boolean
  /** Процесс уже запускался для этой записи — новое выполнение не создано */
  run_once_blocked?: boolean
  previous_execution_id?: string | null
}

/** Отметка однократного запуска: процесс уже запускался для записи CRM */
export interface ProcessObjectRun {
  process_id: number
  object_type: string
  object_id: string
  execution_id: string
  event_id: string
  trigger: 'stream' | 'manual' | 'child'
  created_at: string
  blocked_count: number
  last_blocked_at: string | null
}

// ===== Выполнения =====

export type ExecutionStatus = 'running' | 'retrying' | 'waiting' | 'completed' | 'failed' | 'cancelled'
export type ExecutionTrigger = 'stream' | 'manual' | 'child'

export interface Execution {
  id: string
  process_id: number
  process_name: string
  process_version: number
  parent_execution_id: string | null
  trigger: ExecutionTrigger
  event_id: string | null
  object_type: string
  object_id: string | null
  status: ExecutionStatus
  retry_count: number
  error_message: string | null
  started_at: string
  finished_at: string | null
  duration_ms: number | null
}

export type ExecutionActionStatus = 'success' | 'failed' | 'skipped'

export interface ExecutionAction {
  id: number
  step_index: number
  action_type: string
  status: ExecutionActionStatus
  attempts: number
  started_at: string | null
  duration_ms: number | null
  request_data: unknown
  result_data: unknown
  error_message: string | null
  /** flat program (md/tasks_list/done/0.1-program-compiler.md): instruction, tree path, loop iteration, user step id */
  pc?: number | null
  step_path?: string | null
  iteration?: string
  step_id?: string | null
}

export interface ExecutionContext {
  event?: unknown
  variables?: Record<string, unknown>
  conditions?: unknown
}

export interface ExecutionDetail extends Execution {
  context: ExecutionContext | null
  actions: ExecutionAction[]
  children: Execution[]
}

export interface ExecutionFilters {
  status?: ExecutionStatus | ''
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}
