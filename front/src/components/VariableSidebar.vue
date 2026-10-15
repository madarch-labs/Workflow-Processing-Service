<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DictionaryItem } from '@/types'
import type { VariableInfo } from '@/utils/variables'

const props = withDefaults(
  defineProps<{
    variables: VariableInfo[]
    sources?: DictionaryItem[]
  }>(),
  { sources: () => [] },
)

const { t } = useI18n()

function sourceLabel(value?: string): string {
  if (!value) return ''
  return props.sources.find((s) => s.value === value)?.label ?? value
}

// Подписи видов переменных переводятся при рендере: variables.kind.<kind>
const KIND: Record<VariableInfo['kind'], { type: 'primary' | 'success' | 'warning' | 'info' }> = {
  object: { type: 'primary' },
  preload: { type: 'success' },
  actions_preload: { type: 'success' },
  created: { type: 'warning' },
}

const items = computed(() => props.variables)
</script>

<template>
  <el-card class="variable-sidebar" shadow="never">
    <template #header>
      <div class="panel-title">
        <span>{{ t('variables.title') }}</span>
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
          <el-tag size="small" :type="KIND[v.kind].type" effect="plain">{{ t(`variables.kind.${v.kind}`) }}</el-tag>
        </div>
        <div class="var-line">
          <span class="muted">{{ t('variables.type') }}</span>
          <b v-if="v.module">{{ v.module }}</b>
          <span v-else class="muted">{{ t('variables.typeNotSet') }}</span>
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
    <i18n-t keypath="variables.hint" tag="div" class="muted small" scope="global">
      <template #example1><span class="mono">object.phone</span></template>
      <template #example2><span class="mono">v1.contact_id</span></template>
    </i18n-t>
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
