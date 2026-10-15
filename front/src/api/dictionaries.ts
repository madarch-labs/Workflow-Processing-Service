import { apiGet, apiPost } from './client'
import type { DictionaryItem } from '@/types'

const cache = new Map<string, Promise<DictionaryItem[]>>()

function cached(url: string): Promise<DictionaryItem[]> {
  let p = cache.get(url)
  if (!p) {
    p = apiGet<DictionaryItem[]>(url, undefined, { silent: true })
      .then((items) => (Array.isArray(items) ? items : []))
      .catch((e) => {
        cache.delete(url)
        throw e
      })
    cache.set(url, p)
  }
  return p
}

/** Значения по умолчанию, если справочник недоступен */
export const FALLBACK = {
  operators: [
    { value: 'equals', label: 'Равен' },
    { value: 'not_equals', label: 'Не равен' },
    { value: 'contains', label: 'Включает' },
    { value: 'not_contains', label: 'Не включает' },
  ],
  changeTypes: [
    { value: 'set_value', label: 'Равен значению' },
    { value: 'set_from_variable', label: 'Равен переменной' },
    { value: 'calculate', label: 'Расчёт' },
  ],
  preloadSources: [
    { value: 'object_field', label: 'Из поля объекта' },
    { value: 'variable_field', label: 'Из поля переменной' },
    { value: 'manual', label: 'Ручной ввод' },
  ],
} satisfies Record<string, DictionaryItem[]>

async function withFallback(url: string, fallback: DictionaryItem[]): Promise<DictionaryItem[]> {
  try {
    const items = await cached(url)
    return items.length ? items : fallback
  } catch {
    return fallback
  }
}

export const dictionariesApi = {
  async modules(): Promise<DictionaryItem[]> {
    try {
      return await cached('/dictionaries/modules')
    } catch {
      return []
    }
  },
  async refreshModules(): Promise<DictionaryItem[]> {
    cache.delete('/dictionaries/modules')
    const items = await apiPost<DictionaryItem[]>('/dictionaries/modules/refresh')
    if (Array.isArray(items)) {
      cache.set('/dictionaries/modules', Promise.resolve(items))
      return items
    }
    return dictionariesApi.modules()
  },
  operators: () => withFallback('/dictionaries/operators', FALLBACK.operators),
  changeTypes: () => withFallback('/dictionaries/change-types', FALLBACK.changeTypes),
  preloadSources: () => withFallback('/dictionaries/preload-sources', FALLBACK.preloadSources),
  actionTypes: () => withFallback('/dictionaries/action-types', []),
}
