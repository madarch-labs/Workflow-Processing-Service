let counter = 0
/** Уникальный ключ строки для v-for */
export function rowKey(): string {
  counter += 1
  return `r${Date.now().toString(36)}_${counter}`
}
