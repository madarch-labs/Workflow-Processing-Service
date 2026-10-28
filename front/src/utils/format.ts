import type { DictionaryItem, ExecutionStatus } from '@/types'
import { d, t, te } from '@/i18n'

export function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (isNaN(date.getTime())) return value
  return d(date, 'long')
}

export function formatDuration(ms?: number | null): string {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1000) return t('units.ms', { n: ms })
  if (ms < 60000) return t('units.s', { n: (ms / 1000).toFixed(2) })
  const m = Math.floor(ms / 60000)
  const s = Math.round((ms % 60000) / 1000)
  return t('units.minSec', { m, s })
}

export function shortId(id?: string | null): string {
  if (!id) return '—'
  return id.length > 8 ? id.slice(0, 8) : id
}

export function prettyJson(value: unknown): string {
  if (value === undefined) return ''
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

type TagType = 'primary' | 'success' | 'warning' | 'danger' | 'info'

/*
 * Словари хранят только тип тега; подписи переводятся в момент вызова,
 * чтобы при смене языка шаблоны перерисовывались с новым текстом.
 */
const EXECUTION_STATUS_TAG: Record<ExecutionStatus, TagType> = {
  running: 'primary',
  retrying: 'warning',
  waiting: 'info',
  completed: 'success',
  failed: 'danger',
  cancelled: 'info',
}

const STEP_STATUS_TAG: Record<string, TagType> = {
  success: 'success',
  failed: 'danger',
  skipped: 'info',
  waiting: 'warning',
}

export const EXECUTION_STATUSES = Object.keys(EXECUTION_STATUS_TAG) as ExecutionStatus[]

export const ROLE_TAG: Record<string, TagType> = {
  admin: 'danger',
  editor: 'warning',
  viewer: 'info',
}

/** Перевод по ключу `prefix.value`, если он есть; иначе — fallback (обычно исходное значение) */
function labelOr(key: string, fallback: string): string {
  return te(key) ? t(key) : fallback
}

export function statusTag(status: string): { label: string; type: TagType } {
  return {
    label: labelOr(`execution.status.${status}`, status),
    type: EXECUTION_STATUS_TAG[status as ExecutionStatus] ?? 'info',
  }
}

export function stepTag(status: string): { label: string; type: TagType } {
  return { label: labelOr(`step.status.${status}`, status), type: STEP_STATUS_TAG[status] ?? 'info' }
}

export function triggerLabel(trigger: string): string {
  return labelOr(`trigger.${trigger}`, trigger)
}

export function roleLabel(role: string): string {
  return labelOr(`role.${role}`, role)
}

export type DictKind = 'operator' | 'changeType' | 'preloadSource'

/** Подпись элемента справочника: известные значения переводятся на клиенте, остальные — как пришли с сервера */
export function dictItemLabel(kind: DictKind, item: DictionaryItem): string {
  return labelOr(`dict.${kind}.${item.value}`, item.label)
}

/** Справочник с переведёнными подписями (использовать внутри computed) */
export function localizeDict(kind: DictKind, items: DictionaryItem[]): DictionaryItem[] {
  return items.map((item) => ({ ...item, label: dictItemLabel(kind, item) }))
}
