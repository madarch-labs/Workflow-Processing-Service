import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import ru from 'element-plus/es/locale/lang/ru'
import 'element-plus/dist/index.css'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'

import App from './App.vue'
import router from './router'
import { setSessionExpiredHandler } from './api/client'
import './styles/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: ru })

for (const [name, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(name, component)
}

setSessionExpiredHandler(() => {
  const current = router.currentRoute.value
  if (current.name !== 'login') {
    router.push({ path: '/login', query: { redirect: current.fullPath } })
  }
})

app.mount('#app')
