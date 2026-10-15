import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'

import App from './App.vue'
import router from './router'
import { i18n, detectLocale, setLocale } from './i18n'
import { setSessionExpiredHandler } from './api/client'
import './styles/main.css'

async function bootstrap(): Promise<void> {
  const app = createApp(App)

  app.use(createPinia())
  app.use(i18n)
  app.use(router)
  // локаль Element Plus задаётся через el-config-provider в App.vue
  app.use(ElementPlus)

  for (const [name, component] of Object.entries(ElementPlusIconsVue)) {
    app.component(name, component)
  }

  setSessionExpiredHandler(() => {
    const current = router.currentRoute.value
    if (current.name !== 'login') {
      router.push({ path: '/login', query: { redirect: current.fullPath } })
    }
  })

  // язык загружаем до первого рендера, чтобы не было «мигания» текстов
  await setLocale(detectLocale())
  app.mount('#app')
}

bootstrap()
