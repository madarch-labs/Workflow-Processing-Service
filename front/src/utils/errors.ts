import type { ApiErrorDetail } from '@/types'

interface ErrorLike {
  message?: string
  code?: string
  details?: unknown
}

const CODE_MESSAGES: Record<string, string> = {
  INVALID_CREDENTIALS: 'Неверный email или пароль',
  FORBIDDEN: 'Недостаточно прав для выполнения операции',
  PROCESS_IN_USE: 'Процесс используется другими процессами (start_process) и не может быть удалён',
  CANNOT_DELETE_SELF: 'Нельзя удалить самого себя',
  LAST_ADMIN: 'Нельзя удалить или понизить последнего администратора',
  EMAIL_EXISTS: 'Пользователь с таким email уже существует',
}

/** Детали ошибки валидации в виде массива строк */
export function errorDetailLines(details: unknown): string[] {
  if (!details) return []
  if (Array.isArray(details)) {
    return (details as Partial<ApiErrorDetail>[]).map((d) =>
      typeof d === 'object' && d
        ? d.field
          ? `${d.field}: ${d.message ?? ''}`
          : String(d.message ?? JSON.stringify(d))
        : String(d),
    )
  }
  if (typeof details === 'object') {
    const obj = details as Record<string, unknown>
    if (Array.isArray(obj.path)) {
      const chain = (obj.path as Array<{ id?: number; name?: string }>)
        .map((p) => (p.name ? `«${p.name}» (#${p.id})` : `#${p.id}`))
        .join(' → ')
      return [`Цепочка: ${chain}`]
    }
    return Object.entries(obj).map(([k, v]) => `${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`)
  }
  return [String(details)]
}

/** Человекочитаемый текст ошибки API */
export function formatApiError(err: unknown): string {
  const e = (err ?? {}) as ErrorLike
  let head = (e.code && CODE_MESSAGES[e.code]) || e.message || 'Неизвестная ошибка'
  if (e.code === 'CYCLE_DETECTED') head = 'Обнаружен цикл запуска процессов'
  if (e.code === 'VALIDATION_ERROR' && (!e.message || e.message === 'Validation failed')) {
    head = 'Ошибка валидации'
  }
  const lines = errorDetailLines(e.details)
  return lines.length ? `${head}:\n${lines.map((l) => '• ' + l).join('\n')}` : head
}
