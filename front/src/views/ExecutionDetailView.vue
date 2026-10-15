<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Back, Refresh } from '@element-plus/icons-vue'
import { executionsApi } from '@/api/executions'
import { ApiRequestError } from '@/api/client'
import type { ExecutionDetail } from '@/types'
import { formatDate, formatDuration, shortId, statusTag, stepTag, triggerLabel } from '@/utils/format'
import { apiErrorTitle } from '@/utils/errors'
import JsonView from '@/components/JsonView.vue'

const POLL_MS = 2000

const { t, locale } = useI18n()
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
      loadError.value = e instanceof ApiRequestError ? apiErrorTitle(e) : t('executionDetail.loadFailed')
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
        {{ t('executionDetail.title') }} <span class="mono id">{{ shortId(id) }}</span>
        <el-tag v-if="execution" :type="statusTag(execution.status).type" class="h-tag" effect="dark">
          {{ statusTag(execution.status).label }}
        </el-tag>
        <span v-if="isLive" class="live">
          <span class="dot"></span> {{ t('executionDetail.live', { time: lastUpdated?.toLocaleTimeString(locale) ?? '' }) }}
        </span>
      </h1>
      <div class="actions">
        <el-button
          v-if="execution"
          :icon="Back"
          @click="router.push(`/processes/${execution.process_id}`)"
        >
          {{ t('executionDetail.backToProcess') }}
        </el-button>
        <el-button :icon="Refresh" @click="load()">{{ t('common.refresh') }}</el-button>
      </div>
    </div>

    <el-result v-if="loadError && !execution" icon="error" :title="t('executionDetail.loadFailed')" :sub-title="loadError">
      <template #extra>
        <el-button @click="router.push('/processes')">{{ t('common.backToProcesses') }}</el-button>
      </template>
    </el-result>

    <template v-if="execution">
      <el-alert
        v-if="execution.error_message"
        type="error"
        show-icon
        :closable="false"
        :title="t('executionDetail.errorTitle')"
        :description="execution.error_message"
        class="panel-card"
      />

      <el-card shadow="never" class="panel-card">
        <el-descriptions :column="3" border>
          <el-descriptions-item :label="t('executionDetail.executionId')">
            <span class="mono">{{ execution.id }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.process')">
            <router-link :to="`/processes/${execution.process_id}`">
              {{ execution.process_name || `#${execution.process_id}` }}
            </router-link>
            <span class="muted"> {{ t('executionDetail.version', { version: execution.process_version }) }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.status')">
            <el-tag :type="statusTag(execution.status).type">{{ statusTag(execution.status).label }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.trigger')">
            {{ triggerLabel(execution.trigger) }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.parentExecution')">
            <router-link v-if="execution.parent_execution_id" :to="`/executions/${execution.parent_execution_id}`">
              <span class="mono">{{ shortId(execution.parent_execution_id) }}</span>
            </router-link>
            <span v-else class="muted">—</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.retryCount')">{{ execution.retry_count }}</el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.eventId')"><span class="mono">{{ execution.event_id || '—' }}</span></el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.object')">
            {{ execution.object_type }} <span class="mono">{{ execution.object_id ?? '' }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.duration')">{{ formatDuration(execution.duration_ms) }}</el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.startedAt')">{{ formatDate(execution.started_at) }}</el-descriptions-item>
          <el-descriptions-item :label="t('executionDetail.finishedAt')">{{ formatDate(execution.finished_at) }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title">
            <span>{{ t('executionDetail.steps.title') }} <small>{{ t('executionDetail.steps.subtitle') }}</small></span>
            <el-tag size="small" round>{{ steps.length }}</el-tag>
          </div>
        </template>
        <el-table :data="steps" size="small" row-key="id" :empty-text="isLive ? t('executionDetail.steps.pending') : t('executionDetail.steps.empty')">
          <el-table-column type="expand">
            <template #default="{ row }">
              <div class="expand">
                <div>
                  <div class="expand-title">{{ t('executionDetail.steps.requestData') }}</div>
                  <JsonView :value="row.request_data" />
                </div>
                <div>
                  <div class="expand-title">{{ t('executionDetail.steps.resultData') }}</div>
                  <JsonView :value="row.result_data" />
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="step_index" label="#" width="50" />
          <el-table-column :label="t('executionDetail.steps.actionType')" width="150">
            <template #default="{ row }"><span class="mono">{{ row.action_type }}</span></template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.status')" width="110">
            <template #default="{ row }">
              <el-tag size="small" :type="stepTag(row.status).type">{{ stepTag(row.status).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="attempts" :label="t('executionDetail.steps.attempts')" width="85" align="center" />
          <el-table-column :label="t('executionDetail.started')" width="160">
            <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.duration')" width="110">
            <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.steps.request')" min-width="160">
            <template #default="{ row }"><JsonView :value="row.request_data" collapsible label="request" /></template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.steps.result')" min-width="160">
            <template #default="{ row }"><JsonView :value="row.result_data" collapsible label="result" /></template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.error')" min-width="180">
            <template #default="{ row }">
              <span v-if="row.error_message" class="error-text">{{ row.error_message }}</span>
              <span v-else class="muted">—</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card v-if="children.length" shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title"><span>{{ t('executionDetail.children') }}</span></div>
        </template>
        <el-table
          :data="children"
          size="small"
          class="clickable-rows"
          @row-click="openChild"
        >
          <el-table-column :label="t('common.id')" width="110">
            <template #default="{ row }">
              <router-link :to="`/executions/${row.id}`" class="mono">{{ shortId(row.id) }}</router-link>
            </template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.process')" min-width="200">
            <template #default="{ row }">{{ row.process_name || `#${row.process_id}` }}</template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.status')" width="120">
            <template #default="{ row }">
              <el-tag size="small" :type="statusTag(row.status).type">{{ statusTag(row.status).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.started')" width="160">
            <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.duration')" width="110">
            <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
          </el-table-column>
          <el-table-column :label="t('executionDetail.error')" min-width="160">
            <template #default="{ row }">
              <span v-if="row.error_message" class="error-text">{{ row.error_message }}</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title">
            <span>{{ t('executionDetail.context.title') }} <small v-if="isLive">{{ t('executionDetail.context.live') }}</small></span>
          </div>
        </template>
        <el-tabs v-model="contextTab">
          <el-tab-pane :label="t('executionDetail.context.event')" name="event">
            <JsonView :value="context?.event" />
          </el-tab-pane>
          <el-tab-pane :label="t('executionDetail.context.variables')" name="variables">
            <JsonView :value="context?.variables" />
          </el-tab-pane>
          <el-tab-pane :label="t('executionDetail.context.conditions')" name="conditions">
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
