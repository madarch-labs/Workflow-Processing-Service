import type { ExecutionStatus } from '@/types'

export function formatDate(value?: string | null): string {
  if (!value) return '—'
  const d = new Date(value)
  if (isNaN(d.getTime())) return value
  return d.toLocaleString('ru-RU', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

export function formatDuration(ms?: number | null): string {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1000) return `${ms} мс`
  if (ms < 60000) return `${(ms / 1000).toFixed(2)} с`
  const m = Math.floor(ms / 60000)
  const s = Math.round((ms % 60000) / 1000)
  return `${m} мин ${s} с`
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

export const EXECUTION_STATUS: Record<ExecutionStatus, { label: string; type: TagType }> = {
  running: { label: 'Выполняется', type: 'primary' },
  retrying: { label: 'Повтор', type: 'warning' },
  completed: { label: 'Завершено', type: 'success' },
  failed: { label: 'Ошибка', type: 'danger' },
}

export const STEP_STATUS: Record<string, { label: string; type: TagType }> = {
  success: { label: 'Успех', type: 'success' },
  failed: { label: 'Ошибка', type: 'danger' },
  skipped: { label: 'Пропущен', type: 'info' },
}

export const TRIGGER_LABELS: Record<string, string> = {
  stream: 'Событие CRM',
  manual: 'Ручной запуск',
  child: 'Дочерний',
}

export const ROLE_LABELS: Record<string, string> = {
  admin: 'Администратор',
  editor: 'Редактор',
  viewer: 'Наблюдатель',
}

export const ROLE_TAG: Record<string, TagType> = {
  admin: 'danger',
  editor: 'warning',
  viewer: 'info',
}

export function statusTag(status: string): { label: string; type: TagType } {
  return EXECUTION_STATUS[status as ExecutionStatus] ?? { label: status, type: 'info' }
}

export function stepTag(status: string): { label: string; type: TagType } {
  return STEP_STATUS[status] ?? { label: status, type: 'info' }
}
