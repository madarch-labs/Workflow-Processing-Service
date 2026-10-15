<script setup lang="ts">
import { Delete, Plus } from '@element-plus/icons-vue'
import type { Condition, DictionaryItem } from '@/types'
import PathInput from './PathInput.vue'

const props = withDefaults(
  defineProps<{
    conditions: Condition[]
    operators: DictionaryItem[]
    suggestions?: string[]
  }>(),
  { suggestions: () => [] },
)

const emit = defineEmits<{ (e: 'update:conditions', value: Condition[]): void }>()

const VALUE_TYPES = [
  { value: 'static', label: 'Значение' },
  { value: 'from_object_field', label: 'Из поля объекта/переменной' },
]

function updateRow(index: number, patch: Partial<Condition>): void {
  emit(
    'update:conditions',
    props.conditions.map((c, i) => (i === index ? { ...c, ...patch } : c)),
  )
}

function addRow(): void {
  emit('update:conditions', [
    ...props.conditions,
    { field: 'object.', operator: props.operators[0]?.value ?? 'equals', value_type: 'static', value: '' },
  ])
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
      Условий нет — процесс запускается для любого события своего типа объекта.
    </div>
    <template v-else>
      <div class="row-grid head" :style="{ gridTemplateColumns: GRID }">
        <span>Поле (object.поле / v1.поле)</span>
        <span>Тип сравнения</span>
        <span>Зона сравнения</span>
        <span>Сравниваемое значение</span>
        <span></span>
      </div>
      <template v-for="(c, index) in conditions" :key="index">
        <div v-if="index > 0" class="and-sep">
          <span class="and-line"></span><el-tag size="small" type="info" effect="plain">И</el-tag
          ><span class="and-line"></span>
        </div>
        <div class="row-grid" :style="{ gridTemplateColumns: GRID }">
            <PathInput
              :model-value="c.field"
              :suggestions="suggestions"
              :invalid="isFieldInvalid(c)"
              @update:model-value="(v) => updateRow(index, { field: v })"
            />
            <el-select :model-value="c.operator" @update:model-value="(v: string) => updateRow(index, { operator: v })">
              <el-option v-for="o in operators" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <el-select
              :model-value="c.value_type"
              @update:model-value="(v) => updateRow(index, { value_type: v as Condition['value_type'] })"
            >
              <el-option v-for="o in VALUE_TYPES" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <PathInput
              v-if="c.value_type === 'from_object_field'"
              :model-value="c.value"
              :suggestions="suggestions"
              placeholder="object.поле"
              @update:model-value="(v) => updateRow(index, { value: v })"
            />
            <el-input
              v-else
              :model-value="c.value"
              placeholder="Значение"
              @update:model-value="(v: string) => updateRow(index, { value: v })"
            />
            <div class="row-actions">
              <el-tooltip content="Удалить строку">
                <el-button :icon="Delete" circle size="small" type="danger" plain @click="removeRow(index)" />
              </el-tooltip>
            </div>
        </div>
      </template>
    </template>
    <div class="add-buttons">
      <el-button :icon="Plus" size="small" @click="addRow">Добавить строку</el-button>
      <span v-if="conditions.length > 1" class="muted hint">Все строки объединяются по «И» (AND)</span>
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
.hint {
  font-size: 12px;
  align-self: center;
}
</style>
