import { shallowRef } from 'vue'
import type { Language } from 'element-plus/es/locale'
import ru from 'element-plus/es/locale/lang/ru'
import type { AppLocale } from './locales'

const loaders: Record<AppLocale, () => Promise<Language>> = {
  ru: async () => ru,
  en: () => import('element-plus/es/locale/lang/en').then((m) => m.default),
}

/** Текущая локаль Element Plus (для el-config-provider) */
export const elementLocale = shallowRef<Language>(ru)

export async function loadElementLocale(locale: AppLocale): Promise<void> {
  elementLocale.value = await loaders[locale]()
}
