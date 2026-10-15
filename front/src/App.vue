<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { elementLocale } from '@/i18n/element-plus'

const route = useRoute()
const isPublic = computed(() => !!route.meta.public)
/** До завершения первой навигации маршрут пуст — ничего не рендерим */
const ready = computed(() => route.matched.length > 0)
</script>

<template>
  <el-config-provider :locale="elementLocale">
    <template v-if="ready">
      <router-view v-if="isPublic" />
      <AppLayout v-else>
        <router-view :key="route.fullPath" />
      </AppLayout>
    </template>
  </el-config-provider>
</template>
