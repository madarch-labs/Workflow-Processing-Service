import type { Condition, ModifyRow, PreloadAction } from '@/types'

export const VAR_NAME_RE = /^[A-Za-z_][A-Za-z0-9_]*$/

/** Имя переменной из пути `v1.field` → `v1` */
export function pathVar(path: string): string {
  const s = (path ?? '').trim()
  const dot = s.indexOf('.')
  return dot === -1 ? s : s.slice(0, dot)
}

/** Поле из пути `v1.field.sub` → `field.sub` */
export function pathField(path: string): string {
  const s = (path ?? '').trim()
  const dot = s.indexOf('.')
  return dot === -1 ? '' : s.slice(dot + 1)
}

/** Следующее свободное имя вида v1, v2, … */
export function nextFreeVar(taken: Iterable<string>): string {
  const set = new Set(taken)
  let i = 1
  while (set.has(`v${i}`)) i++
  return `v${i}`
}

/** Пути вида `name.field` внутри выражения calculate */
export function extractPaths(expression: string): string[] {
  const withoutStrings = (expression ?? '').replace(/'[^']*'|"[^"]*"/g, ' ')
  const matches = withoutStrings.match(/[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+/g)
  return matches ?? []
}

export interface VariableInfo {
  name: string
  kind: 'object' | 'preload' | 'actions_preload' | 'created'
  module: string
  source?: string
  value?: string
  fields: string[]
}

interface CollectInput {
  objectType: string
  conditions: Condition[]
  preload: PreloadAction[]
  modifyActions: ModifyRow[]
}

/** Все переменные процесса с модулем и используемыми полями */
export function collectVariables(input: CollectInput): VariableInfo[] {
  const map = new Map<string, VariableInfo>()
  map.set('object', { name: 'object', kind: 'object', module: input.objectType, fields: [] })

  for (const p of input.preload) {
    const name = p.var.trim()
    if (!name || map.has(name)) continue
    map.set(name, { name, kind: 'preload', module: p.module, source: p.source, value: p.value, fields: [] })
  }

  for (const row of input.modifyActions) {
    if (row.type === 'passthrough') {
      const raw = row.raw as Record<string, unknown>
      if (raw.type === 'preload' && typeof raw.var === 'string' && raw.var && !map.has(raw.var)) {
        map.set(raw.var, {
          name: raw.var,
          kind: 'actions_preload',
          module: String(raw.module ?? ''),
          source: String(raw.source ?? ''),
          value: String(raw.value ?? ''),
          fields: [],
        })
      }
      continue
    }
    const v = pathVar(row.target)
    if (!v) continue
    if (!map.has(v)) {
      map.set(v, {
        name: v,
        kind: 'created',
        module: row.type === 'save_object' ? row.module ?? '' : '',
        fields: [],
      })
    } else if (row.type === 'save_object' && row.module) {
      const info = map.get(v)!
      if (info.kind === 'created' && !info.module) info.module = row.module
    }
  }

  // используемые поля
  const paths: string[] = []
  for (const c of input.conditions) {
    paths.push(c.field)
    if (c.value_type === 'from_object_field') paths.push(c.value)
  }
  for (const p of input.preload) {
    if (p.source !== 'manual') paths.push(p.value)
  }
  for (const row of input.modifyActions) {
    if (row.type === 'modify') {
      paths.push(row.target)
      if (row.change_type === 'set_from_variable') paths.push(row.value)
      if (row.change_type === 'calculate') paths.push(...extractPaths(row.value))
    }
  }
  for (const path of paths) {
    const v = pathVar(path)
    const f = pathField(path)
    const info = map.get(v)
    if (info && f && !info.fields.includes(f)) info.fields.push(f)
  }
  for (const info of map.values()) info.fields.sort()
  return [...map.values()]
}

/** Подсказки путей для автокомплита: object.x, v1.y + префиксы переменных */
export function pathSuggestions(vars: VariableInfo[]): string[] {
  const result: string[] = []
  for (const v of vars) {
    for (const f of v.fields) result.push(`${v.name}.${f}`)
    result.push(`${v.name}.`)
  }
  return [...new Set(result)]
}
