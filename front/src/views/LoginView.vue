<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import type { FormInstance, FormRules } from 'element-plus'
import { Lock, Message } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/errors'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInstance>()
const form = reactive({ email: '', password: '' })
const loading = ref(false)
const errorText = ref('')

// computed — чтобы тексты валидации обновлялись при смене языка
const rules = computed<FormRules>(() => ({
  email: [
    { required: true, message: t('auth.emailRequired'), trigger: 'blur' },
    { type: 'email', message: t('auth.emailInvalid'), trigger: 'blur' },
  ],
  password: [{ required: true, message: t('auth.passwordRequired'), trigger: 'blur' }],
}))

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
    <LanguageSwitcher dark class="login-lang" />
    <el-card class="login-card" shadow="always">
      <div class="logo">
        <el-icon :size="36" color="var(--el-color-primary)"><Share /></el-icon>
        <div>
          <div class="title">{{ t('app.name') }}</div>
          <div class="subtitle">{{ t('auth.subtitle') }}</div>
        </div>
      </div>
      <el-alert v-if="errorText" type="error" :title="errorText" show-icon :closable="false" class="login-error" />
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="submit">
        <el-form-item :label="t('auth.email')" prop="email">
          <el-input
            v-model="form.email"
            :prefix-icon="Message"
            :placeholder="t('auth.emailPlaceholder')"
            autocomplete="username"
            size="large"
          />
        </el-form-item>
        <el-form-item :label="t('auth.password')" prop="password">
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
          {{ t('auth.submit') }}
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
  position: relative;
}
.login-lang {
  position: absolute;
  top: 16px;
  right: 20px;
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
