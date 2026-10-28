<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Delete, Plus } from '@element-plus/icons-vue'
import type { Condition, DictionaryItem } from '@/types'
import PathInput from './PathInput.vue'
import { isNoValueOperator, operatorOptions } from '@/utils/processForm'

const props = withDefaults(
  defineProps<{
    conditions: Condition[]
    operators: DictionaryItem[]
    suggestions?: string[]
  }>(),
  { suggestions: () => [] },
)

const emit = defineEmits<{ (e: 'update:conditions', value: Condition[]): void }>()

const { t } = useI18n()

const VALUE_TYPES = computed(() => [
  { value: 'static', label: t('conditions.valueTypeStatic') },
  { value: 'from_object_field', label: t('conditions.valueTypeField') },
])

function updateRow(index: number, patch: Partial<Condition>): void {
  emit(
    'update:conditions',
    props.conditions.map((c, i) => (i === index ? { ...c, ...patch } : c)),
  )
}

function addRow(): void {
  const first = operatorOptions(props.operators)[0]
  emit('update:conditions', [
    ...props.conditions,
    { field: 'object.', operator: first?.value ?? 'equals', value_type: 'static', value: '', _noValue: isNoValueOperator(first) },
  ])
}

/** The operator of the row takes no value: value inputs are hidden and not sent */
function noValue(c: Condition): boolean {
  return isNoValueOperator(props.operators.find((o) => o.value === c.operator))
}

function setOperator(index: number, operator: string): void {
  updateRow(index, { operator, _noValue: isNoValueOperator(props.operators.find((o) => o.value === operator)) })
}

function removeRow(index: number): void {
  emit(
    'update:conditions',
    props.conditions.filter((_, i) => i !== index),
  )
}

function isFieldInvalid(c: Condition): boolean {
  const f = c.field.trim()
  return !f || !f.includes('.') || f.endsWith('.')
}

const GRID = '1.3fr 1fr 1.2fr 1.3fr 40px'
</script>

<template>
  <div class="conditions-panel">
    <div v-if="conditions.length === 0" class="empty-row">
      {{ t('conditions.empty') }}
    </div>
    <template v-else>
      <div class="row-grid head" :style="{ gridTemplateColumns: GRID }">
        <span>{{ t('conditions.colField') }}</span>
        <span>{{ t('conditions.colOperator') }}</span>
        <span>{{ t('conditions.colValueType') }}</span>
        <span>{{ t('conditions.colValue') }}</span>
        <span></span>
      </div>
      <template v-for="(c, index) in conditions" :key="index">
        <div v-if="index > 0" class="and-sep">
          <span class="and-line"></span><el-tag size="small" type="info" effect="plain">{{ t('conditions.and') }}</el-tag
          ><span class="and-line"></span>
        </div>
        <div class="row-grid" :style="{ gridTemplateColumns: GRID }">
            <PathInput
              :model-value="c.field"
              :suggestions="suggestions"
              :invalid="isFieldInvalid(c)"
              @update:model-value="(v) => updateRow(index, { field: v })"
            />
            <el-select :model-value="c.operator" @update:model-value="(v: string) => setOperator(index, v)">
              <el-option v-for="o in operatorOptions(operators, c.operator)" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <template v-if="noValue(c)">
              <span class="muted no-value">—</span>
              <span class="muted no-value">—</span>
            </template>
            <el-select
              v-else
              :model-value="c.value_type"
              @update:model-value="(v) => updateRow(index, { value_type: v as Condition['value_type'] })"
            >
              <el-option v-for="o in VALUE_TYPES" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <PathInput
              v-if="!noValue(c) && c.value_type === 'from_object_field'"
              :model-value="c.value"
              :suggestions="suggestions"
              :placeholder="t('conditions.fieldPlaceholder')"
              @update:model-value="(v) => updateRow(index, { value: v })"
            />
            <el-input
              v-else-if="!noValue(c)"
              :model-value="c.value"
              :placeholder="t('conditions.valuePlaceholder')"
              @update:model-value="(v: string) => updateRow(index, { value: v })"
            />
            <div class="row-actions">
              <el-tooltip :content="t('conditions.removeRow')">
                <el-button :icon="Delete" circle size="small" type="danger" plain @click="removeRow(index)" />
              </el-tooltip>
            </div>
        </div>
      </template>
    </template>
    <div class="add-buttons">
      <el-button :icon="Plus" size="small" @click="addRow">{{ t('conditions.addRow') }}</el-button>
      <span v-if="conditions.length > 1" class="muted hint">{{ t('conditions.andHint') }}</span>
    </div>
  </div>
</template>

<style scoped>
.and-sep {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 4px 0;
}
.and-line {
  flex: 1;
  height: 1px;
  background: var(--el-border-color-lighter);
}
.no-value {
  align-self: center;
  padding-left: 12px;
}
.hint {
  font-size: 12px;
  align-self: center;
}
</style>
