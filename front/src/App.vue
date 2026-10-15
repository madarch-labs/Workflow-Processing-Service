<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'

const route = useRoute()
const isPublic = computed(() => !!route.meta.public)
/** До завершения первой навигации маршрут пуст — ничего не рендерим */
const ready = computed(() => route.matched.length > 0)
</script>

<template>
  <template v-if="ready">
    <router-view v-if="isPublic" />
    <AppLayout v-else>
      <router-view :key="route.fullPath" />
    </AppLayout>
  </template>
</template>
