<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, Delete, Plus, QuestionFilled, Upload } from '@element-plus/icons-vue'
import type { ChangeType, DictionaryItem, ModifyRow } from '@/types'
import { rowKey } from '@/utils/keys'
import PathInput from './PathInput.vue'

const props = withDefaults(
  defineProps<{
    modifyActions: ModifyRow[]
    changeTypes: DictionaryItem[]
    modules: DictionaryItem[]
    /** Имена доступных переменных (для save_object) */
    variableNames?: string[]
    suggestions?: string[]
  }>(),
  { variableNames: () => [], suggestions: () => [] },
)

const emit = defineEmits<{ (e: 'update:modifyActions', value: ModifyRow[]): void }>()

const { t } = useI18n()

const showHelp = ref(false)

function updateRow(index: number, patch: Record<string, unknown>): void {
  emit(
    'update:modifyActions',
    props.modifyActions.map((r, i) => (i === index ? ({ ...r, ...patch } as ModifyRow) : r)),
  )
}

function addModify(): void {
  emit('update:modifyActions', [
    ...props.modifyActions,
    { _key: rowKey(), type: 'modify', target: 'object.', change_type: 'set_value', value: '', save_to_crm: false },
  ])
}

function addSave(): void {
  const firstVar = props.variableNames.find((n) => n !== 'object') ?? 'object'
  emit('update:modifyActions', [...props.modifyActions, { _key: rowKey(), type: 'save_object', target: firstVar, module: '' }])
}

function removeRow(index: number): void {
  emit(
    'update:modifyActions',
    props.modifyActions.filter((_, i) => i !== index),
  )
}

function move(index: number, delta: number): void {
  const target = index + delta
  if (target < 0 || target >= props.modifyActions.length) return
  const copy = [...props.modifyActions]
  ;[copy[index], copy[target]] = [copy[target], copy[index]]
  emit('update:modifyActions', copy)
}

function valuePlaceholder(ct: ChangeType): string {
  if (ct === 'set_from_variable') return 'v2.sale'
  if (ct === 'calculate') return "object.amount * 0.9 + 10"
  return t('modify.valuePlaceholder')
}

function targetInvalid(target: string): boolean {
  const trimmed = target.trim()
  return !trimmed || !trimmed.includes('.') || trimmed.endsWith('.')
}

function passthroughSummary(raw: Record<string, unknown>): string {
  if (raw.type === 'preload') {
    return t('modify.preloadSummary', {
      var: String(raw.var ?? '?'),
      module: String(raw.module ?? '?'),
      field: String(raw.by_field ?? 'id'),
      value: String(raw.value ?? ''),
      source: String(raw.source ?? ''),
    })
  }
  const rest = { ...raw }
  delete rest.type
  return JSON.stringify(rest)
}

const saveTargets = computed(() => {
  const names = new Set(props.variableNames)
  for (const r of props.modifyActions) if (r.type === 'save_object' && r.target) names.add(r.target)
  return [...names]
})

const GRID = '1.2fr 1fr 1.6fr 110px 100px'
</script>

<template>
  <div class="modify-panel">
    <div v-if="modifyActions.length === 0" class="empty-row">
      {{ t('modify.empty') }}
    </div>
    <template v-else>
      <div class="row-grid head" :style="{ gridTemplateColumns: '28px ' + GRID }">
        <span>#</span>
        <span>{{ t('modify.columns.target') }}</span>
        <span>{{ t('modify.columns.changeType') }}</span>
        <span>{{ t('modify.columns.value') }}</span>
        <span>{{ t('modify.saveToCrm') }}</span>
        <span></span>
      </div>
      <div
        v-for="(row, index) in modifyActions"
        :key="row._key"
        class="row-grid row"
        :class="'row-' + row.type"
        :style="{ gridTemplateColumns: '28px ' + GRID }"
      >
        <span class="num">{{ index + 1 }}</span>

        <!-- modify -->
        <template v-if="row.type === 'modify'">
          <PathInput
            :model-value="row.target"
            :suggestions="suggestions"
            :invalid="targetInvalid(row.target)"
            placeholder="object.phone / v1.total"
            @update:model-value="(v) => updateRow(index, { target: v })"
          />
          <el-select
            :model-value="row.change_type"
            @update:model-value="(v: string) => updateRow(index, { change_type: v })"
          >
            <el-option v-for="c in changeTypes" :key="c.value" :label="c.label" :value="c.value" />
          </el-select>
          <PathInput
            v-if="row.change_type === 'set_from_variable'"
            :model-value="row.value"
            :suggestions="suggestions"
            :placeholder="valuePlaceholder(row.change_type)"
            @update:model-value="(v) => updateRow(index, { value: v })"
          />
          <div v-else-if="row.change_type === 'calculate'" class="calc-cell">
            <el-input
              :model-value="row.value"
              class="mono-input"
              :placeholder="valuePlaceholder(row.change_type)"
              @update:model-value="(v: string) => updateRow(index, { value: v })"
            />
            <el-button link :icon="QuestionFilled" @click="showHelp = !showHelp" />
          </div>
          <el-input
            v-else
            :model-value="row.value"
            :placeholder="valuePlaceholder(row.change_type)"
            @update:model-value="(v: string) => updateRow(index, { value: v })"
          />
          <el-checkbox
            :model-value="row.save_to_crm"
            @update:model-value="(v) => updateRow(index, { save_to_crm: !!v })"
          >
            {{ t('common.yes') }}
          </el-checkbox>
        </template>

        <!-- save_object -->
        <template v-else-if="row.type === 'save_object'">
          <el-select
            :model-value="row.target"
            filterable
            allow-create
            default-first-option
            :placeholder="t('modify.variablePlaceholder')"
            @update:model-value="(v: string) => updateRow(index, { target: v ?? '' })"
          >
            <el-option v-for="n in saveTargets" :key="n" :label="n" :value="n" />
          </el-select>
          <div class="save-label">
            <el-tag type="success" effect="plain"><el-icon><Upload /></el-icon> {{ t('modify.saveToCrm') }}</el-tag>
          </div>
          <el-select
            :model-value="row.module ?? ''"
            filterable
            allow-create
            default-first-option
            clearable
            :placeholder="t('modify.modulePlaceholder')"
            @update:model-value="(v: string) => updateRow(index, { module: v ?? '' })"
          >
            <el-option v-for="m in modules" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
          <span></span>
        </template>

        <!-- прочие действия (например, preload внутри actions) — без редактирования -->
        <template v-else>
          <div class="passthrough">
            <el-tag type="info">{{ (row.raw as Record<string, unknown>).type }}</el-tag>
            <span class="mono">{{ passthroughSummary(row.raw as Record<string, unknown>) }}</span>
            <el-tooltip :content="t('modify.passthroughHint')">
              <el-icon class="muted"><InfoFilled /></el-icon>
            </el-tooltip>
          </div>
        </template>

        <div class="row-actions">
          <el-button :icon="ArrowUp" circle size="small" :disabled="index === 0" @click="move(index, -1)" />
          <el-button
            :icon="ArrowDown"
            circle
            size="small"
            :disabled="index === modifyActions.length - 1"
            @click="move(index, 1)"
          />
          <el-button :icon="Delete" circle size="small" type="danger" plain @click="removeRow(index)" />
        </div>
      </div>
    </template>

    <div class="add-buttons">
      <el-button :icon="Plus" size="small" @click="addModify">{{ t('modify.addModify') }}</el-button>
      <el-button :icon="Upload" size="small" @click="addSave">{{ t('modify.saveToCrm') }}</el-button>
      <el-button link size="small" type="primary" :icon="QuestionFilled" @click="showHelp = !showHelp">
        {{ t('modify.helpToggle') }}
      </el-button>
    </div>

    <el-collapse-transition>
      <el-alert v-show="showHelp" type="info" :closable="false" class="calc-help">
        <template #title>{{ t('modify.help.title') }}</template>
        <ul>
          <i18n-t keypath="modify.help.literals" tag="li" scope="global">
            <template #num1><code>10</code></template>
            <template #num2><code>0.9</code></template>
            <template #str1><code>'abc'</code></template>
            <template #str2><code>"abc"</code></template>
          </i18n-t>
          <i18n-t keypath="modify.help.paths" tag="li" scope="global">
            <template #path1><code>object.amount</code></template>
            <template #path2><code>v1.price</code></template>
          </i18n-t>
          <i18n-t keypath="modify.help.operators" tag="li" scope="global">
            <template #ops><code>+ - * / %</code></template>
            <template #plus><code>+</code></template>
          </i18n-t>
          <i18n-t keypath="modify.help.functions" tag="li" scope="global">
            <template #list>
              <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
              <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
              <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>
            </template>
          </i18n-t>
          <i18n-t keypath="modify.help.examples" tag="li" scope="global">
            <template #list>
              <code>object.amount * 0.9 + 10</code>, <code>concat(upper(v1.name), ' — ', object.id)</code>,
              <code>round(v2.price / 3, 2)</code>
            </template>
          </i18n-t>
        </ul>
      </el-alert>
    </el-collapse-transition>
  </div>
</template>

<style scoped>
.row {
  margin-bottom: 8px;
}
.num {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  text-align: center;
}
.row-save_object {
  background: var(--el-color-success-light-9);
  border-radius: 4px;
  padding: 4px 0;
}
.row-passthrough {
  background: var(--el-fill-color-light);
  border-radius: 4px;
  padding: 4px 0;
}
.passthrough {
  grid-column: span 4;
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 12px;
  overflow: hidden;
}
.calc-cell {
  display: flex;
  align-items: center;
  gap: 4px;
}
.mono-input :deep(.el-input__inner) {
  font-family: var(--wps-mono);
}
.save-label {
  display: flex;
  align-items: center;
}
.calc-help {
  margin-top: 12px;
}
.calc-help ul {
  margin: 4px 0 0;
  padding-left: 18px;
  line-height: 1.7;
}
.calc-help code {
  font-family: var(--wps-mono);
  background: rgba(0, 0, 0, 0.05);
  padding: 0 4px;
  border-radius: 3px;
}
</style>
