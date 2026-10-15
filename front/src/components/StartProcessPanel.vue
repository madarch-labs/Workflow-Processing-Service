<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Delete, Plus, Refresh, Right } from '@element-plus/icons-vue'
import { processesApi } from '@/api/processes'
import type { Process, StartProcessRow } from '@/types'
import { rowKey } from '@/utils/keys'

const props = withDefaults(
  defineProps<{
    startActions: StartProcessRow[]
    /** id редактируемого процесса — исключается из списка */
    currentProcessId?: number | null
    currentProcessName?: string
  }>(),
  { currentProcessId: null, currentProcessName: '' },
)

const emit = defineEmits<{ (e: 'update:startActions', value: StartProcessRow[]): void }>()

const { t } = useI18n()

const children = ref<Process[]>([])
const loading = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    children.value = await processesApi.listChildren()
  } catch {
    children.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)

const available = computed(() => children.value.filter((p) => p.id !== props.currentProcessId))

function processById(id: number | null): Process | undefined {
  return id === null ? undefined : children.value.find((p) => p.id === id)
}

function isSelectedElsewhere(id: number, index: number): boolean {
  return props.startActions.some((r, i) => i !== index && r.process_id === id)
}

function updateRow(index: number, processId: number | null): void {
  emit(
    'update:startActions',
    props.startActions.map((r, i) => (i === index ? { ...r, process_id: processId } : r)),
  )
}

function addRow(): void {
  emit('update:startActions', [...props.startActions, { _key: rowKey(), process_id: null }])
}

function removeRow(index: number): void {
  emit(
    'update:startActions',
    props.startActions.filter((_, i) => i !== index),
  )
}
</script>

<template>
  <div class="start-panel">
    <div v-if="startActions.length === 0" class="empty-row">
      {{ t('startProcess.empty') }}
    </div>
    <div v-for="(row, index) in startActions" :key="row._key" class="start-row">
      <span class="current">{{ currentProcessName || t('startProcess.currentProcess') }}</span>
      <el-icon class="arrow"><Right /></el-icon>
      <el-select
        :model-value="row.process_id"
        filterable
        clearable
        :loading="loading"
        :placeholder="t('startProcess.selectPlaceholder')"
        class="process-select"
        @update:model-value="(v) => updateRow(index, v === '' || v === undefined ? null : (v as number))"
      >
        <el-option
          v-if="row.process_id !== null && !processById(row.process_id)"
          :value="row.process_id"
          :label="t('startProcess.notFound', { id: row.process_id })"
        />
        <el-option
          v-for="p in available"
          :key="p.id"
          :value="p.id"
          :label="`${p.name} (#${p.id})`"
          :disabled="isSelectedElsewhere(p.id, index)"
        >
          <span>{{ p.name }}</span>
          <span class="opt-meta">
            #{{ p.id }} · {{ p.object_type }}
            <el-tag v-if="!p.is_active" size="small" type="info">{{ t('startProcess.inactive') }}</el-tag>
          </span>
        </el-option>
      </el-select>
      <el-tag v-if="processById(row.process_id) && !processById(row.process_id)!.is_active" type="warning" size="small">
        {{ t('startProcess.inactive') }}
      </el-tag>
      <router-link v-if="row.process_id !== null" :to="`/processes/${row.process_id}`" target="_blank" class="open-link">
        {{ t('startProcess.open') }}
      </router-link>
      <el-button :icon="Delete" circle size="small" type="danger" plain @click="removeRow(index)" />
    </div>
    <div class="add-buttons">
      <el-button :icon="Plus" size="small" @click="addRow">{{ t('startProcess.addProcess') }}</el-button>
      <el-tooltip :content="t('startProcess.refreshHint')">
        <el-button :icon="Refresh" size="small" :loading="loading" @click="load" />
      </el-tooltip>
      <span v-if="!loading && available.length === 0" class="muted hint">
        {{ t('startProcess.noneAvailable') }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.start-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.current {
  font-weight: 500;
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.arrow {
  color: var(--el-color-primary);
}
.process-select {
  width: 420px;
}
.opt-meta {
  float: right;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  margin-left: 12px;
}
.open-link {
  font-size: 12px;
}
.hint {
  font-size: 12px;
  align-self: center;
}
</style>
