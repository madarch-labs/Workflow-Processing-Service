<script setup lang="ts">
import { computed, ref } from 'vue'
import { prettyJson } from '@/utils/format'

const props = withDefaults(defineProps<{ value: unknown; collapsible?: boolean; label?: string }>(), {
  collapsible: false,
  label: 'JSON',
})

const open = ref(false)
const text = computed(() => prettyJson(props.value))
const isEmpty = computed(
  () =>
    props.value === null ||
    props.value === undefined ||
    (typeof props.value === 'object' && Object.keys(props.value as object).length === 0),
)
const preview = computed(() => {
  const s = JSON.stringify(props.value) ?? ''
  return s.length > 60 ? s.slice(0, 60) + '…' : s
})
</script>

<template>
  <span v-if="isEmpty" class="muted">—</span>
  <div v-else-if="collapsible">
    <el-button link type="primary" size="small" @click="open = !open">
      <el-icon><component :is="open ? 'ArrowDown' : 'ArrowRight'" /></el-icon>
      {{ open ? 'Скрыть' : label }}
    </el-button>
    <span v-if="!open" class="mono preview">{{ preview }}</span>
    <pre v-if="open" class="json-block">{{ text }}</pre>
  </div>
  <pre v-else class="json-block">{{ text }}</pre>
</template>

<style scoped>
.preview {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  margin-left: 4px;
  word-break: break-all;
}
</style>
