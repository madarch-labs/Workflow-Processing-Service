import type ru from '@/locales/ru.json'

type MessageSchema = typeof ru

declare module 'vue-i18n' {
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  export interface DefineLocaleMessage extends MessageSchema {}
}
