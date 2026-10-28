import type {
  Condition,
  ConditionGroup,
  DictionaryItem,
  ModifyAction,
  ModifyRow,
  PreloadAction,
  Process,
  ProcessAction,
  ProcessDefinition,
  ProcessFormData,
  ProcessPayload,
  SaveAction,
  StartProcessAction,
  StartProcessRow,
} from '@/types'
import { rowKey } from '@/utils/keys'

/*
 * The form edits on top of the loaded definition: every row keeps its original object (_raw),
 * and saving merges the edited fields into it. Opening a process and saving it unchanged
 * must give back the same definition.
 */

type Obj = Record<string, unknown>

/** Keys carried over from _raw when the row kind (operator / change_type / source) was changed */
const KEEP_CONDITION = ['cast', 'cast_options', 'on_cast_error']
const KEEP_ACTION = ['id', 'on_error', 'retry']
const KEEP_PRELOAD = ['strategy']

const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T

function isScalar(v: unknown): boolean {
  return v === null || v === undefined || ['string', 'number', 'boolean'].includes(typeof v)
}

function toText(v: unknown): string {
  return v === undefined || v === null ? '' : String(v)
}

function isObj(v: unknown): v is Obj {
  return !!v && typeof v === 'object' && !Array.isArray(v)
}

/*
 * Value types and change types the table editor has inputs for. The integration test
 * "[editor form contract]" (back/tests/IntegrationTest.php) mirrors these lists: keep them in sync.
 */
const FORM_VALUE_TYPES = [undefined, 'static', 'from_object_field']
export const FORM_CHANGE_TYPES = ['set_value', 'set_from_variable', 'calculate', 'template', 'increment', 'decrement', 'clear']
const STEP_CHANGE_TYPES = ['increment', 'decrement']
/** Operand kinds (dictionary "operands") of operators without a right-hand side: is_empty, is_null, changed … */
const NO_VALUE_OPERANDS = ['none', 'presence', 'change']

export function isNoValueOperator(item: DictionaryItem | undefined): boolean {
  return !!item?.operands && NO_VALUE_OPERANDS.includes(item.operands)
}

/**
 * The form has no cast selector and one text value: it can save an operator without a value or one that compares
 * a single value as a string. Items without metadata (the offline fallback) are basic operators.
 */
export function isFormOperator(item: DictionaryItem): boolean {
  if (!item.operands) return true
  if (isNoValueOperator(item)) return true
  return ['value', 'change_value'].includes(item.operands) && (!item.casts || item.casts.includes('string'))
}

/** Options of a row's select: what the form can save, plus the row's current value (loaded rows keep their cast) */
function withCurrent(items: DictionaryItem[], allowed: (i: DictionaryItem) => boolean, current?: string): DictionaryItem[] {
  return items.filter((i) => allowed(i) || i.value === current)
}

export function operatorOptions(operators: DictionaryItem[], current?: string): DictionaryItem[] {
  return withCurrent(operators, isFormOperator, current)
}

export function changeTypeOptions(changeTypes: DictionaryItem[], current?: string): DictionaryItem[] {
  return withCurrent(changeTypes, (i) => FORM_CHANGE_TYPES.includes(i.value), current)
}

/** A plain condition row the table editor can show (not a group, scalar value, no expression) */
function isSimpleCondition(c: unknown): boolean {
  return (
    isObj(c) &&
    typeof c.field === 'string' &&
    !('conditions' in c) &&
    !('groups' in c) &&
    isScalar(c.value) &&
    FORM_VALUE_TYPES.includes(c.value_type as string | undefined)
  )
}

export function conditionFromRaw(c: Obj): Condition {
  return {
    field: toText(c.field),
    operator: (c.operator as string) ?? 'equals',
    value_type: (c.value_type as Condition['value_type']) ?? 'static',
    value: toText(c.value),
    _raw: clone(c),
  }
}

/** Simple rows of a raw conditions list (used by "convert to table conditions") */
export function simpleConditions(list: unknown[]): Condition[] {
  return list.filter(isSimpleCondition).map((c) => conditionFromRaw(c as Obj))
}

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
    sourceDefinition: {},
  }
}

/** Process → данные формы редактора (actions раскладываются по панелям) */
export function formFromProcess(p: Process): ProcessFormData {
  const def: Partial<ProcessDefinition> = p.definition ?? {}
  const modifyActions: ModifyRow[] = []
  const startActions: StartProcessRow[] = []
  let lastModifyKey: string | null = null

  const pushRow = (row: ModifyRow): void => {
    modifyActions.push(row)
    lastModifyKey = row._key
  }
  const passthrough = (action: ProcessAction): void => {
    pushRow({ _key: rowKey(), type: 'passthrough', raw: clone(action) })
  }

  for (const action of def.actions ?? []) {
    if (!action || typeof action !== 'object') continue
    if (action.type === 'start_process') {
      const sp = action as StartProcessAction
      // a list of processes has no table row: kept as is, in place
      if (sp.process_ids !== undefined || sp.process_id === undefined || sp.process_id === null) {
        passthrough(action)
        continue
      }
      startActions.push({
        _key: rowKey(),
        process_id: Number(sp.process_id),
        _raw: clone(action as unknown as Obj),
        _afterKey: lastModifyKey,
      })
    } else if (action.type === 'modify') {
      const m = action as ModifyAction
      if (!isScalar(m.value) || !FORM_CHANGE_TYPES.includes(m.change_type ?? 'set_value')) {
        // date shift, lists, lookup table, aggregate: not editable in the form
        passthrough(action)
        continue
      }
      pushRow({
        _key: rowKey(),
        type: 'modify',
        target: m.target ?? '',
        change_type: m.change_type ?? 'set_value',
        value: toText(m.value),
        save_to_crm: !!m.save_to_crm,
        _raw: clone(action as unknown as Obj),
      })
    } else if (action.type === 'save_object') {
      const s = action as SaveAction
      pushRow({
        _key: rowKey(),
        type: 'save_object',
        target: s.target ?? '',
        module: s.module ?? '',
        _raw: clone(action as unknown as Obj),
      })
    } else {
      // неизвестные/нередактируемые типы (например preload внутри actions) — сохраняем без изменений
      passthrough(action)
    }
  }

  const rawConditions = def.conditions as unknown
  const tableConditions = Array.isArray(rawConditions) && rawConditions.every(isSimpleCondition)

  return {
    id: p.id,
    name: p.name,
    object_type: p.object_type,
    is_child: !!p.is_child,
    is_active: !!p.is_active,
    priority: p.priority ?? 0,
    run_once: !!p.run_once,
    conditions: tableConditions ? (rawConditions as Obj[]).map(conditionFromRaw) : [],
    conditionsRaw:
      !tableConditions && rawConditions ? clone(rawConditions as ConditionGroup | unknown[]) : null,
    preload: (def.preload ?? []).map((pr) => ({
      var: pr.var ?? '',
      source: pr.source ?? 'object_field',
      module: pr.module ?? '',
      by_field: pr.by_field || 'id',
      value: toText(pr.value),
      _raw: clone(pr as unknown as Obj),
    })),
    modifyActions,
    startActions,
    sourceDefinition: clone((p.definition ?? {}) as Obj),
  }
}

/** Original keys to keep: all of them while the row kind is unchanged, otherwise only the `keep` list */
function base(raw: Obj | undefined, kindKey: string, kind: unknown, keep: string[]): Obj {
  if (!raw) return {}
  if (raw[kindKey] === kind) return { ...raw }
  const out: Obj = {}
  for (const k of keep) if (k in raw) out[k] = raw[k]
  return out
}

/** Keeps the original typed value (number, bool, null) while its text was not edited */
function restoreValue(text: string, raw: Obj | undefined): unknown {
  if (raw && 'value' in raw && isScalar(raw.value) && toText(raw.value) === text) return raw.value
  return text
}

/** Does not add a key the original row did not have when the form only holds its default */
function dropAddedDefaults(out: Obj, raw: Obj | undefined, defaults: Obj): Obj {
  if (!raw) return out
  for (const [k, d] of Object.entries(defaults)) {
    if (!(k in raw) && out[k] === d) delete out[k]
  }
  return out
}

function buildCondition(c: Condition): Obj {
  const out: Obj = {
    ...base(c._raw, 'operator', c.operator, KEEP_CONDITION),
    field: c.field.trim(),
    operator: c.operator,
    value_type: c.value_type,
    value: restoreValue(c.value, c._raw),
  }
  if (c._noValue) {
    // the server rejects value / value_type for is_empty, is_null, changed …
    delete out.value
    delete out.value_type
  }
  return dropAddedDefaults(out, c._raw, { value_type: 'static', value: '' })
}

function buildPreload(p: PreloadAction): Obj {
  const out: Obj = {
    ...base(p._raw, 'source', p.source, KEEP_PRELOAD),
    var: p.var.trim(),
    source: p.source,
    module: p.module.trim(),
    by_field: p.by_field.trim() || 'id',
    value: restoreValue(p.value, p._raw),
  }
  return dropAddedDefaults(out, p._raw, { by_field: 'id' })
}

function buildModifyRow(row: ModifyRow): ProcessAction {
  if (row.type === 'modify') {
    const out: Obj = {
      ...base(row._raw, 'change_type', row.change_type, KEEP_ACTION),
      type: 'modify',
      target: row.target.trim(),
      change_type: row.change_type,
      value: restoreValue(row.value, row._raw),
      save_to_crm: row.save_to_crm,
    }
    // an empty step means the default step 1: the server rejects "" but accepts a missing value
    if (STEP_CHANGE_TYPES.includes(row.change_type) && out.value === '') delete out.value
    return dropAddedDefaults(out, row._raw, { save_to_crm: false, value: '' }) as ProcessAction
  }
  if (row.type === 'save_object') {
    const out: Obj = { ...(row._raw ?? {}), type: 'save_object', target: row.target.trim() }
    if (row.module && row.module.trim()) out.module = row.module.trim()
    else delete out.module
    return out as ProcessAction
  }
  return row.raw
}

function buildStart(s: StartProcessRow): ProcessAction {
  const rawId = s._raw?.process_id
  const processId = rawId !== undefined && Number(rawId) === s.process_id ? rawId : s.process_id
  return { ...(s._raw ?? {}), type: 'start_process', process_id: processId } as ProcessAction
}

/** Данные формы → definition */
export function definitionFromForm(form: ProcessFormData): ProcessDefinition {
  // start_process rows go back to where they were loaded; new ones and orphans go last
  const starts = form.startActions.filter((s) => s.process_id !== null && s.process_id !== undefined)
  const modifyKeys = new Set(form.modifyActions.map((r) => r._key))
  const startsAfter = (anchor: string | null): StartProcessRow[] => starts.filter((s) => s._afterKey === anchor)

  const actions: ProcessAction[] = startsAfter(null).map(buildStart)
  for (const row of form.modifyActions) {
    actions.push(buildModifyRow(row))
    actions.push(...startsAfter(row._key).map(buildStart))
  }
  actions.push(
    ...starts
      .filter((s) => s._afterKey === undefined || (s._afterKey !== null && !modifyKeys.has(s._afterKey)))
      .map(buildStart),
  )

  return {
    ...form.sourceDefinition,
    conditions: form.conditionsRaw ?? (form.conditions.map(buildCondition) as unknown as Condition[]),
    preload: form.preload.map(buildPreload) as unknown as PreloadAction[],
    actions,
  } as ProcessDefinition
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
