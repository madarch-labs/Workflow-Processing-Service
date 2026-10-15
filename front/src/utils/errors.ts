import type { ApiErrorDetail } from '@/types'
import { t, te } from '@/i18n'

interface ErrorLike {
  message?: string
  code?: string
  details?: unknown
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
      return [t('errors.chain', { chain })]
    }
    return Object.entries(obj).map(([k, v]) => `${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`)
  }
  return [String(details)]
}

/** Текст ошибки по коду: перевод errors.<CODE>, иначе сообщение сервера */
export function apiErrorTitle(e: ErrorLike & { status?: number }): string {
  const code = e.code ?? ''
  if (code === 'VALIDATION_ERROR' && e.message && e.message !== 'Validation failed') return e.message
  if (code && te(`errors.${code}`)) return t(`errors.${code}`)
  if (code.startsWith('HTTP_')) return t('errors.HTTP', { status: code.slice(5) })
  return e.message || t('errors.unknown')
}

/** Человекочитаемый текст ошибки API */
export function formatApiError(err: unknown): string {
  const e = (err ?? {}) as ErrorLike
  const head = apiErrorTitle(e)
  const lines = errorDetailLines(e.details)
  return lines.length ? `${head}:\n${lines.map((l) => '• ' + l).join('\n')}` : head
}
