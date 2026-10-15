<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FormInstance, FormRules } from 'element-plus'
import { Lock, Message } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/errors'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInstance>()
const form = reactive({ email: '', password: '' })
const loading = ref(false)
const errorText = ref('')

const rules: FormRules = {
  email: [
    { required: true, message: 'Введите email', trigger: 'blur' },
    { type: 'email', message: 'Некорректный email', trigger: 'blur' },
  ],
  password: [{ required: true, message: 'Введите пароль', trigger: 'blur' }],
}

async function submit(): Promise<void> {
  errorText.value = ''
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  loading.value = true
  try {
    await auth.login(form.email.trim(), form.password)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/processes'
    router.replace(redirect.startsWith('/') && !redirect.startsWith('/login') ? redirect : '/processes')
  } catch (e) {
    errorText.value = formatApiError(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <el-card class="login-card" shadow="always">
      <div class="logo">
        <el-icon :size="36" color="var(--el-color-primary)"><Share /></el-icon>
        <div>
          <div class="title">Workflow Processing Service</div>
          <div class="subtitle">Настройка и аудит бизнес-процессов CRM</div>
        </div>
      </div>
      <el-alert v-if="errorText" type="error" :title="errorText" show-icon :closable="false" class="login-error" />
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="submit">
        <el-form-item label="Email" prop="email">
          <el-input
            v-model="form.email"
            :prefix-icon="Message"
            placeholder="user@company.ru"
            autocomplete="username"
            size="large"
          />
        </el-form-item>
        <el-form-item label="Пароль" prop="password">
          <el-input
            v-model="form.password"
            :prefix-icon="Lock"
            type="password"
            show-password
            autocomplete="current-password"
            size="large"
          />
        </el-form-item>
        <el-button type="primary" size="large" :loading="loading" class="submit" native-type="submit">
          Войти
        </el-button>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #1f2d3d 0%, #34495e 100%);
}
.login-card {
  width: 420px;
}
.logo {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}
.title {
  font-size: 18px;
  font-weight: 600;
}
.subtitle {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.login-error {
  margin-bottom: 16px;
}
.submit {
  width: 100%;
  margin-top: 8px;
}
</style>
