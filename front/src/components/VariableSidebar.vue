<script setup lang="ts">
import { computed } from 'vue'
import type { DictionaryItem } from '@/types'
import type { VariableInfo } from '@/utils/variables'

const props = withDefaults(
  defineProps<{
    variables: VariableInfo[]
    sources?: DictionaryItem[]
  }>(),
  { sources: () => [] },
)

function sourceLabel(value?: string): string {
  if (!value) return ''
  return props.sources.find((s) => s.value === value)?.label ?? value
}

const KIND: Record<VariableInfo['kind'], { label: string; type: 'primary' | 'success' | 'warning' | 'info' }> = {
  object: { label: 'исходный объект', type: 'primary' },
  preload: { label: 'загружена из CRM', type: 'success' },
  actions_preload: { label: 'загружена в действиях', type: 'success' },
  created: { label: 'создана в процессе', type: 'warning' },
}

const items = computed(() => props.variables)
</script>

<template>
  <el-card class="variable-sidebar" shadow="never">
    <template #header>
      <div class="panel-title">
        <span>Переменные</span>
        <el-tag size="small" round>{{ items.length }}</el-tag>
      </div>
    </template>
    <el-timeline>
      <el-timeline-item
        v-for="v in items"
        :key="v.name"
        :type="KIND[v.kind].type"
        :hollow="v.kind === 'created'"
        placement="top"
      >
        <div class="var-head">
          <span class="var-name mono">{{ v.name }}</span>
          <el-tag size="small" :type="KIND[v.kind].type" effect="plain">{{ KIND[v.kind].label }}</el-tag>
        </div>
        <div class="var-line">
          <span class="muted">Тип:</span>
          <b v-if="v.module">{{ v.module }}</b>
          <span v-else class="muted">не указан</span>
        </div>
        <div v-if="v.source" class="var-line">
          <span class="muted">{{ sourceLabel(v.source) }}:</span>
          <span class="mono small">{{ v.value || '—' }}</span>
        </div>
        <div v-if="v.fields.length" class="fields">
          <el-tag v-for="f in v.fields" :key="f" size="small" type="info" class="field-tag mono">{{ f }}</el-tag>
        </div>
      </el-timeline-item>
    </el-timeline>
    <div class="muted small">
      Поля указываются через точку: <span class="mono">object.phone</span>, <span class="mono">v1.contact_id</span>.
    </div>
  </el-card>
</template>

<style scoped>
.variable-sidebar :deep(.el-card__header) {
  padding: 10px 16px;
}
.variable-sidebar :deep(.el-timeline) {
  padding-left: 2px;
}
.var-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 2px;
}
.var-name {
  font-weight: 600;
  font-size: 14px;
}
.var-line {
  font-size: 12px;
  margin-top: 2px;
  word-break: break-all;
}
.small {
  font-size: 12px;
}
.fields {
  margin-top: 4px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.field-tag {
  font-size: 11px;
}
</style>
