<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Back, Refresh } from '@element-plus/icons-vue'
import { executionsApi } from '@/api/executions'
import { ApiRequestError } from '@/api/client'
import type { ExecutionDetail } from '@/types'
import { formatDate, formatDuration, shortId, statusTag, stepTag, TRIGGER_LABELS } from '@/utils/format'
import JsonView from '@/components/JsonView.vue'

const POLL_MS = 2000

const route = useRoute()
const router = useRouter()

const id = computed(() => String(route.params.id))
const execution = ref<ExecutionDetail | null>(null)
const loading = ref(false)
const loadError = ref('')
const lastUpdated = ref<Date | null>(null)
const contextTab = ref('event')

let timer: ReturnType<typeof setTimeout> | null = null
let disposed = false

const isLive = computed(() => execution.value?.status === 'running' || execution.value?.status === 'retrying')

async function load(silent = false): Promise<void> {
  if (!silent) loading.value = true
  try {
    execution.value = await executionsApi.get(id.value, silent)
    lastUpdated.value = new Date()
    loadError.value = ''
  } catch (e) {
    if (!silent || !execution.value) {
      loadError.value = e instanceof ApiRequestError ? e.message : 'Не удалось загрузить выполнение'
    }
  } finally {
    loading.value = false
  }
  schedule()
}

function schedule(): void {
  if (timer) clearTimeout(timer)
  timer = null
  if (!disposed && isLive.value) {
    timer = setTimeout(() => load(true), POLL_MS)
  }
}

function openChild(row: { id: string }): void {
  router.push(`/executions/${row.id}`)
}

const steps = computed(() => [...(execution.value?.actions ?? [])].sort((a, b) => a.step_index - b.step_index))
const children = computed(() => execution.value?.children ?? [])
const context = computed(() => execution.value?.context ?? null)

onMounted(() => load())
onBeforeUnmount(() => {
  disposed = true
  if (timer) clearTimeout(timer)
})
</script>

<template>
  <div v-loading="loading" class="execution-detail">
    <div class="page-header">
      <h1>
        Выполнение <span class="mono id">{{ shortId(id) }}</span>
        <el-tag v-if="execution" :type="statusTag(execution.status).type" class="h-tag" effect="dark">
          {{ statusTag(execution.status).label }}
        </el-tag>
        <span v-if="isLive" class="live">
          <span class="dot"></span> live · обновлено {{ lastUpdated?.toLocaleTimeString('ru-RU') }}
        </span>
      </h1>
      <div class="actions">
        <el-button
          v-if="execution"
          :icon="Back"
          @click="router.push(`/processes/${execution.process_id}`)"
        >
          К процессу
        </el-button>
        <el-button :icon="Refresh" @click="load()">Обновить</el-button>
      </div>
    </div>

    <el-result v-if="loadError && !execution" icon="error" title="Не удалось загрузить выполнение" :sub-title="loadError">
      <template #extra>
        <el-button @click="router.push('/processes')">К списку процессов</el-button>
      </template>
    </el-result>

    <template v-if="execution">
      <el-alert
        v-if="execution.error_message"
        type="error"
        show-icon
        :closable="false"
        title="Ошибка выполнения"
        :description="execution.error_message"
        class="panel-card"
      />

      <el-card shadow="never" class="panel-card">
        <el-descriptions :column="3" border>
          <el-descriptions-item label="ID выполнения">
            <span class="mono">{{ execution.id }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="Процесс">
            <router-link :to="`/processes/${execution.process_id}`">
              {{ execution.process_name || `#${execution.process_id}` }}
            </router-link>
            <span class="muted"> (версия {{ execution.process_version }})</span>
          </el-descriptions-item>
          <el-descriptions-item label="Статус">
            <el-tag :type="statusTag(execution.status).type">{{ statusTag(execution.status).label }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="Источник">
            {{ TRIGGER_LABELS[execution.trigger] ?? execution.trigger }}
          </el-descriptions-item>
          <el-descriptions-item label="Родительское выполнение">
            <router-link v-if="execution.parent_execution_id" :to="`/executions/${execution.parent_execution_id}`">
              <span class="mono">{{ shortId(execution.parent_execution_id) }}</span>
            </router-link>
            <span v-else class="muted">—</span>
          </el-descriptions-item>
          <el-descriptions-item label="Кол-во повторов (retry)">{{ execution.retry_count }}</el-descriptions-item>
          <el-descriptions-item label="ID события"><span class="mono">{{ execution.event_id || '—' }}</span></el-descriptions-item>
          <el-descriptions-item label="Объект">
            {{ execution.object_type }} <span class="mono">{{ execution.object_id ?? '' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="Длительность">{{ formatDuration(execution.duration_ms) }}</el-descriptions-item>
          <el-descriptions-item label="Начато">{{ formatDate(execution.started_at) }}</el-descriptions-item>
          <el-descriptions-item label="Завершено">{{ formatDate(execution.finished_at) }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title">
            <span>Шаги выполнения <small>аудит каждого действия</small></span>
            <el-tag size="small" round>{{ steps.length }}</el-tag>
          </div>
        </template>
        <el-table :data="steps" size="small" row-key="id" :empty-text="isLive ? 'Шаги ещё не выполнены…' : 'Шагов нет'">
          <el-table-column type="expand">
            <template #default="{ row }">
              <div class="expand">
                <div>
                  <div class="expand-title">Запрос (request_data)</div>
                  <JsonView :value="row.request_data" />
                </div>
                <div>
                  <div class="expand-title">Результат (result_data)</div>
                  <JsonView :value="row.result_data" />
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="step_index" label="#" width="50" />
          <el-table-column label="Тип действия" width="150">
            <template #default="{ row }"><span class="mono">{{ row.action_type }}</span></template>
          </el-table-column>
          <el-table-column label="Статус" width="110">
            <template #default="{ row }">
              <el-tag size="small" :type="stepTag(row.status).type">{{ stepTag(row.status).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="attempts" label="Попыток" width="85" align="center" />
          <el-table-column label="Начат" width="160">
            <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
          </el-table-column>
          <el-table-column label="Длительность" width="110">
            <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
          </el-table-column>
          <el-table-column label="Запрос" min-width="160">
            <template #default="{ row }"><JsonView :value="row.request_data" collapsible label="request" /></template>
          </el-table-column>
          <el-table-column label="Результат" min-width="160">
            <template #default="{ row }"><JsonView :value="row.result_data" collapsible label="result" /></template>
          </el-table-column>
          <el-table-column label="Ошибка" min-width="180">
            <template #default="{ row }">
              <span v-if="row.error_message" class="error-text">{{ row.error_message }}</span>
              <span v-else class="muted">—</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card v-if="children.length" shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title"><span>Дочерние выполнения</span></div>
        </template>
        <el-table
          :data="children"
          size="small"
          class="clickable-rows"
          @row-click="openChild"
        >
          <el-table-column label="ID" width="110">
            <template #default="{ row }">
              <router-link :to="`/executions/${row.id}`" class="mono">{{ shortId(row.id) }}</router-link>
            </template>
          </el-table-column>
          <el-table-column label="Процесс" min-width="200">
            <template #default="{ row }">{{ row.process_name || `#${row.process_id}` }}</template>
          </el-table-column>
          <el-table-column label="Статус" width="120">
            <template #default="{ row }">
              <el-tag size="small" :type="statusTag(row.status).type">{{ statusTag(row.status).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="Начат" width="160">
            <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
          </el-table-column>
          <el-table-column label="Длительность" width="110">
            <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
          </el-table-column>
          <el-table-column label="Ошибка" min-width="160">
            <template #default="{ row }">
              <span v-if="row.error_message" class="error-text">{{ row.error_message }}</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title">
            <span>Контекст выполнения <small v-if="isLive">живой контекст из Redis</small></span>
          </div>
        </template>
        <el-tabs v-model="contextTab">
          <el-tab-pane label="Событие (event)" name="event">
            <JsonView :value="context?.event" />
          </el-tab-pane>
          <el-tab-pane label="Переменные (variables)" name="variables">
            <JsonView :value="context?.variables" />
          </el-tab-pane>
          <el-tab-pane label="Условия (conditions)" name="conditions">
            <JsonView :value="context?.conditions" />
          </el-tab-pane>
        </el-tabs>
      </el-card>
    </template>
  </div>
</template>

<style scoped>
.id {
  font-size: 16px;
}
.h-tag {
  margin-left: 8px;
  vertical-align: middle;
}
.live {
  font-size: 12px;
  font-weight: normal;
  color: var(--el-color-primary);
  margin-left: 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--el-color-primary);
  animation: pulse 1.2s infinite;
}
@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.2;
  }
}
.expand {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  padding: 4px 16px 4px 48px;
}
.expand-title {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 4px;
}
.error-text {
  color: var(--el-color-danger);
  font-size: 12px;
}
</style>
