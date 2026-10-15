/** Реестр поддерживаемых языков. Новый язык: JSON в src/locales + запись здесь + локаль Element Plus в element-plus.ts */
export const SUPPORTED_LOCALES = ['ru', 'en'] as const
export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'ru'

/** Название языка — всегда на самом языке, не переводится */
export const LOCALE_META: Record<AppLocale, { label: string; bcp47: string }> = {
  ru: { label: 'Русский', bcp47: 'ru-RU' },
  en: { label: 'English', bcp47: 'en-US' },
}

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

/** 'en-GB' → 'en', если такой язык поддерживается */
export function matchLocale(value: unknown): AppLocale | null {
  if (typeof value !== 'string' || !value) return null
  const short = value.toLowerCase().split(/[-_]/)[0]
  return isSupportedLocale(short) ? short : null
}
