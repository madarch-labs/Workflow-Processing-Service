<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, Delete, Plus } from '@element-plus/icons-vue'
import type { DictionaryItem, PreloadAction, PreloadSource } from '@/types'
import { nextFreeVar, pathVar, VAR_NAME_RE } from '@/utils/variables'
import PathInput from './PathInput.vue'

const props = withDefaults(
  defineProps<{
    preload: PreloadAction[]
    sources: DictionaryItem[]
    modules: DictionaryItem[]
    /** Имена, уже занятые вне блока (например, переменные, созданные в modify) */
    reservedNames?: string[]
    suggestions?: string[]
  }>(),
  { reservedNames: () => [], suggestions: () => [] },
)

const emit = defineEmits<{ (e: 'update:preload', value: PreloadAction[]): void }>()

const { t } = useI18n()

function updateRow(index: number, patch: Partial<PreloadAction>): void {
  emit(
    'update:preload',
    props.preload.map((p, i) => (i === index ? { ...p, ...patch } : p)),
  )
}

function addRow(): void {
  const taken = [...props.preload.map((p) => p.var), ...props.reservedNames]
  emit('update:preload', [
    ...props.preload,
    { var: nextFreeVar(taken), source: 'object_field', module: '', by_field: 'id', value: 'object.' },
  ])
}

function removeRow(index: number): void {
  emit(
    'update:preload',
    props.preload.filter((_, i) => i !== index),
  )
}

function move(index: number, delta: number): void {
  const target = index + delta
  if (target < 0 || target >= props.preload.length) return
  const copy = [...props.preload]
  ;[copy[index], copy[target]] = [copy[target], copy[index]]
  emit('update:preload', copy)
}

function onSourceChange(index: number, source: PreloadSource): void {
  const row = props.preload[index]
  let value = row.value
  if (source === 'object_field' && !value.startsWith('object.')) value = 'object.'
  if (source === 'variable_field' && (value.startsWith('object.') || !value.includes('.'))) {
    const prev = index > 0 ? props.preload[index - 1].var : 'v1'
    value = `${prev}.`
  }
  if (source === 'manual' && value.endsWith('.')) value = ''
  updateRow(index, { source, value })
}

/** Текст ошибки строки (или null) */
function varError(index: number): string | null {
  const name = props.preload[index].var.trim()
  if (!name) return t('preload.errors.varRequired')
  if (!VAR_NAME_RE.test(name)) return t('preload.errors.varFormat')
  if (name === 'object') return t('preload.errors.varReserved')
  if (props.preload.findIndex((p) => p.var.trim() === name) !== index) return t('preload.errors.varUnique')
  return null
}

function valueError(index: number): string | null {
  const row = props.preload[index]
  const value = row.value.trim()
  if (row.source === 'manual') return value ? null : t('preload.errors.valueRequired')
  if (!value || !value.includes('.') || value.endsWith('.')) return t('preload.errors.pathFormat')
  if (row.source === 'object_field' && !value.startsWith('object.')) {
    const source = props.sources.find((s) => s.value === 'object_field')?.label ?? t('dict.preloadSource.object_field')
    return t('preload.errors.objectPath', { source })
  }
  if (row.source === 'variable_field') {
    const v = pathVar(value)
    const earlier = props.preload.slice(0, index).map((p) => p.var.trim())
    if (!earlier.includes(v)) return t('preload.errors.varDeclaredAbove', { name: v })
  }
  return null
}

function placeholder(source: PreloadSource): string {
  if (source === 'object_field') return 'object.account_id'
  if (source === 'variable_field') return 'v1.contact_id'
  return t('preload.manualPlaceholder')
}

const GRID = '90px 1.1fr 1.3fr 1.2fr 0.8fr 100px'
</script>

<template>
  <div class="preload-panel">
    <div v-if="preload.length === 0" class="empty-row">
      {{ t('preload.empty') }}
    </div>
    <template v-else>
      <div class="row-grid head" :style="{ gridTemplateColumns: GRID }">
        <span>{{ t('preload.colVar') }}</span>
        <span>{{ t('preload.colSource') }}</span>
        <span>{{ t('preload.colValue') }}</span>
        <span>{{ t('preload.colModule') }}</span>
        <span>{{ t('preload.colByField') }}</span>
        <span></span>
      </div>
      <div v-for="(p, index) in preload" :key="index" class="row-grid row" :style="{ gridTemplateColumns: GRID }">
        <el-tooltip :content="varError(index) ?? ''" :disabled="!varError(index)" placement="top">
          <el-input
            :model-value="p.var"
            class="mono-input"
            :class="{ 'is-invalid': !!varError(index) }"
            @update:model-value="(v: string) => updateRow(index, { var: v })"
          />
        </el-tooltip>
        <el-select
          :model-value="p.source"
          @update:model-value="(v) => onSourceChange(index, v as PreloadSource)"
        >
          <el-option v-for="s in sources" :key="s.value" :label="s.label" :value="s.value" />
        </el-select>
        <el-tooltip :content="valueError(index) ?? ''" :disabled="!valueError(index)" placement="top">
          <div>
            <el-input
              v-if="p.source === 'manual'"
              :model-value="p.value"
              :placeholder="placeholder(p.source)"
              :class="{ 'is-invalid': !!valueError(index) }"
              @update:model-value="(v: string) => updateRow(index, { value: v })"
            />
            <PathInput
              v-else
              :model-value="p.value"
              :suggestions="suggestions"
              :placeholder="placeholder(p.source)"
              :invalid="!!valueError(index)"
              @update:model-value="(v) => updateRow(index, { value: v })"
            />
          </div>
        </el-tooltip>
        <el-select
          :model-value="p.module"
          filterable
          allow-create
          default-first-option
          :placeholder="t('preload.modulePlaceholder')"
          :class="{ 'is-invalid': !p.module.trim() }"
          @update:model-value="(v: string) => updateRow(index, { module: v ?? '' })"
        >
          <el-option v-for="m in modules" :key="m.value" :label="m.label" :value="m.value" />
        </el-select>
        <el-input
          :model-value="p.by_field"
          placeholder="id"
          class="mono-input"
          @update:model-value="(v: string) => updateRow(index, { by_field: v })"
        />
        <div class="row-actions">
          <el-button :icon="ArrowUp" circle size="small" :disabled="index === 0" @click="move(index, -1)" />
          <el-button
            :icon="ArrowDown"
            circle
            size="small"
            :disabled="index === preload.length - 1"
            @click="move(index, 1)"
          />
          <el-button :icon="Delete" circle size="small" type="danger" plain @click="removeRow(index)" />
        </div>
      </div>
    </template>
    <div class="add-buttons">
      <el-button :icon="Plus" size="small" @click="addRow">{{ t('preload.addRow') }}</el-button>
      <span class="muted hint">
        {{ t('preload.byFieldHint') }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.row {
  margin-bottom: 8px;
}
.mono-input :deep(.el-input__inner) {
  font-family: var(--wps-mono);
}
.is-invalid :deep(.el-input__wrapper),
.is-invalid :deep(.el-select__wrapper) {
  box-shadow: 0 0 0 1px var(--el-color-danger) inset;
}
.hint {
  font-size: 12px;
  align-self: center;
}
</style>
