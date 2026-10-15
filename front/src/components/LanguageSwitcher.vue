<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { LOCALE_META, SUPPORTED_LOCALES, setLocale, type AppLocale } from '@/i18n'

withDefaults(defineProps<{ dark?: boolean }>(), { dark: false })

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const currentLabel = computed(() => LOCALE_META[locale.value as AppLocale]?.label ?? locale.value)

async function select(code: AppLocale): Promise<void> {
  if (code === locale.value) return
  await setLocale(code)
  // ?lang= имеет приоритет при загрузке — синхронизируем его, чтобы выбор не сбрасывался при перезагрузке
  if (route.query.lang) {
    router.replace({ query: { ...route.query, lang: code } })
  }
}
</script>

<template>
  <el-dropdown trigger="click" @command="select">
    <el-button text :class="{ 'lang-dark': dark }" :aria-label="t('language.label')">
      <el-icon><Location /></el-icon>
      <span class="lang-label">{{ currentLabel }}</span>
    </el-button>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item
          v-for="code in SUPPORTED_LOCALES"
          :key="code"
          :command="code"
          :disabled="code === locale"
          :data-test="`lang-${code}`"
        >
          {{ LOCALE_META[code].label }}
        </el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
</template>

<style scoped>
.lang-label {
  margin-left: 4px;
}
.lang-dark {
  color: #fff;
}
</style>
