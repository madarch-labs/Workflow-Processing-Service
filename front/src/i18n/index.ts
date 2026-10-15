import { createI18n } from 'vue-i18n'
import ru from '@/locales/ru.json'
import { loadElementLocale } from './element-plus'
import { DEFAULT_LOCALE, isSupportedLocale, matchLocale, SUPPORTED_LOCALES, type AppLocale } from './locales'

export * from './locales'

const STORAGE_KEY = 'wps_locale'

type MessageSchema = typeof ru

/** Русская плюрализация: «нет | 1 элемент | 2 элемента | 5 элементов» (без 0-формы — 3 варианта) */
function ruPluralRule(choice: number, choicesLength: number): number {
  const n = Math.abs(choice)
  const offset = choicesLength === 4 ? 1 : 0
  if (offset && n === 0) return 0
  const mod10 = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return offset
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return offset + 1
  return offset + 2
}

const dateTime = {
  long: {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  },
  short: { year: 'numeric', month: '2-digit', day: '2-digit' },
} as const

export const i18n = createI18n<[MessageSchema], AppLocale, false>({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: DEFAULT_LOCALE,
  messages: { ru } as Record<AppLocale, MessageSchema>,
  pluralRules: { ru: ruPluralRule },
  datetimeFormats: { ru: dateTime, en: dateTime },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: import.meta.env.DEV,
})

/** Для использования вне компонентов (utils, api, router) */
export const t = i18n.global.t
export const te = i18n.global.te
export const d = i18n.global.d

const messageLoaders: Record<AppLocale, () => Promise<{ default: MessageSchema }>> = {
  ru: async () => ({ default: ru }),
  en: () => import('@/locales/en.json'),
}

function readStorage(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function writeStorage(value: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    /* storage недоступен — язык не запоминается */
  }
}

/** Приоритет: ?lang= → сохранённый выбор → язык браузера → по умолчанию */
export function detectLocale(): AppLocale {
  const fromQuery = new URLSearchParams(window.location.search).get('lang')
  if (isSupportedLocale(fromQuery)) return fromQuery
  const stored = readStorage()
  if (isSupportedLocale(stored)) return stored
  for (const lang of navigator.languages ?? [navigator.language]) {
    const matched = matchLocale(lang)
    if (matched) return matched
  }
  return DEFAULT_LOCALE
}

export function currentLocale(): AppLocale {
  return i18n.global.locale.value
}

export async function loadLocaleMessages(locale: AppLocale): Promise<void> {
  if (i18n.global.availableLocales.includes(locale)) return
  const messages = await messageLoaders[locale]()
  i18n.global.setLocaleMessage(locale, messages.default)
}

/** Единая точка смены языка */
export async function setLocale(locale: AppLocale): Promise<void> {
  if (!SUPPORTED_LOCALES.includes(locale)) locale = DEFAULT_LOCALE
  await Promise.all([loadLocaleMessages(locale), loadElementLocale(locale)])
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  writeStorage(locale)
}
