<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { CopyDocument, Delete, Edit, Refresh, VideoPlay } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { processesApi } from '@/api/processes'
import { executionsApi } from '@/api/executions'
import { dictionariesApi, FALLBACK } from '@/api/dictionaries'
import { ApiRequestError } from '@/api/client'
import type {
  Condition,
  ConditionGroup,
  ConditionTrace,
  DictionaryItem,
  Execution,
  ExecutionStatus,
  PaginationMeta,
  Process,
  ProcessAction,
  ProcessObjectRun,
} from '@/types'
import { EXECUTION_STATUS, formatDate, formatDuration, shortId, statusTag, TRIGGER_LABELS } from '@/utils/format'
import JsonEditor from '@/components/JsonEditor.vue'
import JsonView from '@/components/JsonView.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const id = computed(() => Number(route.params.id))
const process = ref<Process | null>(null)
const loading = ref(false)
const loadError = ref('')

const operators = ref<DictionaryItem[]>(FALLBACK.operators)
const changeTypes = ref<DictionaryItem[]>(FALLBACK.changeTypes)
const preloadSources = ref<DictionaryItem[]>(FALLBACK.preloadSources)
const processNames = ref<Record<number, string>>({})

function dictLabel(list: DictionaryItem[], value: string): string {
  return list.find((d) => d.value === value)?.label ?? value
}

// ---------- процесс ----------
const conditionsList = computed<Condition[]>(() => {
  const c = process.value?.definition?.conditions
  return Array.isArray(c) ? c : []
})
const conditionsGroup = computed<ConditionGroup | null>(() => {
  const c = process.value?.definition?.conditions
  return c && !Array.isArray(c) ? (c as ConditionGroup) : null
})
const preload = computed(() => process.value?.definition?.preload ?? [])
const actions = computed<ProcessAction[]>(() => process.value?.definition?.actions ?? [])

function actionTypeLabel(type: string): string {
  return (
    {
      modify: 'Изменение',
      save_object: 'Сохранить в CRM',
      start_process: 'Запуск процесса',
      preload: 'Предзагрузка',
    }[type] ?? type
  )
}

function startIds(a: ProcessAction): number[] {
  const r = a as { process_id?: number; process_ids?: number[] }
  const ids: number[] = []
  if (r.process_id !== undefined && r.process_id !== null) ids.push(Number(r.process_id))
  if (Array.isArray(r.process_ids)) ids.push(...r.process_ids.map(Number))
  return ids
}

function asRecord(a: unknown): Record<string, unknown> {
  return (a ?? {}) as Record<string, unknown>
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const p = await processesApi.get(id.value)
    process.value = p
    const names: Record<number, string> = {}
    for (const r of [...(p.references?.children ?? []), ...(p.references?.parents ?? [])]) names[r.id] = r.name
    processNames.value = names
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : 'Не удалось загрузить процесс'
  } finally {
    loading.value = false
  }
}

// ---------- действия над процессом ----------
async function clone(): Promise<void> {
  if (!process.value) return
  try {
    const copy = await processesApi.clone(process.value.id)
    ElMessage.success(`Создана копия «${copy.name}»`)
    router.push(`/processes/${copy.id}/edit`)
  } catch {
    /* сообщение уже показано */
  }
}

async function remove(): Promise<void> {
  if (!process.value) return
  try {
    await ElMessageBox.confirm(`Удалить процесс «${process.value.name}»? Действие необратимо.`, 'Удаление процесса', {
      confirmButtonText: 'Удалить',
      cancelButtonText: 'Отмена',
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  try {
    await processesApi.remove(process.value.id)
    ElMessage.success('Процесс удалён')
    router.push('/processes')
  } catch {
    /* сообщение уже показано */
  }
}

// ---------- ручной запуск ----------
const runVisible = ref(false)
const runJson = ref('')
const runForce = ref(false)
const running = ref(false)
const runTrace = ref<ConditionTrace[] | null>(null)
/** Ручной запуск заблокирован однократным запуском: id выполнения, которое уже было для этой записи */
const runBlockedBy = ref<string | null | undefined>(undefined)

function sampleObject(): Record<string, unknown> {
  const p = process.value
  const obj: Record<string, unknown> = { id: '1', module: p?.object_type ?? '' }
  for (const c of conditionsList.value) {
    if (c.field?.startsWith('object.')) {
      const f = c.field.slice(7)
      if (f && !(f in obj)) obj[f] = c.value_type === 'static' && c.operator === 'equals' ? c.value : ''
    }
  }
  for (const pr of preload.value) {
    if (pr.source === 'object_field' && pr.value?.startsWith('object.')) {
      const f = pr.value.slice(7)
      if (f && !(f in obj)) obj[f] = ''
    }
  }
  return obj
}

function openRun(): void {
  runJson.value = JSON.stringify(sampleObject(), null, 2)
  runForce.value = false
  runTrace.value = null
  runBlockedBy.value = undefined
  runVisible.value = true
}

const runJsonError = computed(() => {
  try {
    const v = JSON.parse(runJson.value)
    if (v === null || typeof v !== 'object' || Array.isArray(v)) return 'Ожидается JSON-объект { … }'
    return null
  } catch (e) {
    return (e as Error).message
  }
})

async function doRun(): Promise<void> {
  if (!process.value) return
  if (runJsonError.value) {
    ElMessage.error('Исправьте JSON объекта: ' + runJsonError.value)
    return
  }
  running.value = true
  runTrace.value = null
  runBlockedBy.value = undefined
  try {
    const { status, data } = await processesApi.run(process.value.id, JSON.parse(runJson.value), runForce.value)
    if (data.run_once_blocked) {
      runBlockedBy.value = data.previous_execution_id ?? null
      ElMessage.warning('Процесс уже выполнялся для этой записи — новый запуск не создан')
      loadRuns()
    } else if (data.execution_id && (status === 201 || data.conditions_met)) {
      if (process.value.run_once && data.run_once_applied === false) {
        ElMessage.warning('Однократный запуск не применён: у объекта нет id')
      }
      ElMessage.success('Процесс запущен')
      runVisible.value = false
      router.push(`/executions/${data.execution_id}`)
    } else {
      runTrace.value = data.conditions ?? []
      ElMessage.warning('Условия запуска не выполнены — процесс не запущен')
    }
  } catch {
    /* сообщение уже показано */
  } finally {
    running.value = false
  }
}

function traceValue(v: unknown): string {
  if (v === undefined) return '—'
  return typeof v === 'string' ? v : JSON.stringify(v)
}

// ---------- журнал выполнения ----------
const executions = ref<Execution[]>([])
const execMeta = ref<PaginationMeta>({ page: 1, per_page: 20, total: 0 })
const execLoading = ref(false)
const execFilters = reactive({
  status: '' as ExecutionStatus | '',
  range: null as [string, string] | null,
  page: 1,
  per_page: 20,
})

async function loadExecutions(): Promise<void> {
  execLoading.value = true
  try {
    const page = await executionsApi.listByProcess(id.value, {
      status: execFilters.status,
      date_from: execFilters.range?.[0],
      date_to: execFilters.range?.[1],
      page: execFilters.page,
      per_page: execFilters.per_page,
    })
    executions.value = page.items
    execMeta.value = page.meta
  } catch {
    executions.value = []
  } finally {
    execLoading.value = false
  }
}

function applyExecFilters(): void {
  execFilters.page = 1
  loadExecutions()
}

function onExecPage(page: number): void {
  execFilters.page = page
  loadExecutions()
}
function onExecSize(size: number): void {
  execFilters.per_page = size
  execFilters.page = 1
  loadExecutions()
}

function openExecution(row: Execution): void {
  router.push(`/executions/${row.id}`)
}

// ---------- однократные запуски (run_once) ----------
const activeTab = ref<'executions' | 'runs'>('executions')
const runs = ref<ProcessObjectRun[]>([])
const runsMeta = ref<PaginationMeta>({ page: 1, per_page: 20, total: 0 })
const runsLoading = ref(false)
const runsFilters = reactive({ object_id: '', page: 1, per_page: 20 })
/** Вкладка видна, если флаг включён или отметки остались после его выключения */
const showRunsTab = computed(() => !!process.value?.run_once || runsMeta.value.total > 0)

async function loadRuns(): Promise<void> {
  runsLoading.value = true
  try {
    const page = await processesApi.listRuns(id.value, {
      object_id: runsFilters.object_id.trim() || undefined,
      page: runsFilters.page,
      per_page: runsFilters.per_page,
    })
    runs.value = page.items
    runsMeta.value = page.meta
  } catch {
    runs.value = []
  } finally {
    runsLoading.value = false
  }
}

function applyRunsFilters(): void {
  runsFilters.page = 1
  loadRuns()
}
function onRunsPage(page: number): void {
  runsFilters.page = page
  loadRuns()
}
function onRunsSize(size: number): void {
  runsFilters.per_page = size
  runsFilters.page = 1
  loadRuns()
}

async function resetRun(row: ProcessObjectRun): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `Снять отметку для записи ${row.object_type} «${row.object_id}»? Следующее сохранение этой записи снова запустит процесс.`,
      'Сброс однократного запуска',
      { confirmButtonText: 'Сбросить', cancelButtonText: 'Отмена', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await processesApi.resetRun(id.value, row.object_type, row.object_id)
    ElMessage.success('Отметка снята')
    loadRuns()
  } catch {
    /* сообщение уже показано */
  }
}

onMounted(() => {
  load()
  loadExecutions()
  loadRuns()
  dictionariesApi.operators().then((v) => (operators.value = v))
  dictionariesApi.changeTypes().then((v) => (changeTypes.value = v))
  dictionariesApi.preloadSources().then((v) => (preloadSources.value = v))
})
</script>

<template>
  <div v-loading="loading" class="process-detail">
    <el-result v-if="loadError" icon="error" title="Не удалось загрузить процесс" :sub-title="loadError">
      <template #extra>
        <el-button @click="router.push('/processes')">К списку процессов</el-button>
      </template>
    </el-result>

    <template v-else-if="process">
      <div class="page-header">
        <h1>
          {{ process.name }}
          <el-tag :type="process.is_active ? 'success' : 'info'" class="h-tag">
            {{ process.is_active ? 'Активен' : 'Неактивен' }}
          </el-tag>
          <el-tooltip v-if="process.run_once" content="Однократный запуск: не более одного раза на запись CRM">
            <el-tag type="warning" effect="dark" class="h-tag">1×</el-tag>
          </el-tooltip>
        </h1>
        <div class="actions">
          <template v-if="auth.canEdit">
            <el-button :icon="Edit" @click="router.push(`/processes/${process.id}/edit`)">Редактировать</el-button>
            <el-button :icon="CopyDocument" @click="clone">Клонировать</el-button>
            <el-button type="primary" :icon="VideoPlay" @click="openRun">Выполнить (Run)</el-button>
          </template>
          <el-button v-if="auth.isAdmin" type="danger" plain :icon="Delete" @click="remove">Удалить</el-button>
        </div>
      </div>

      <el-card shadow="never" class="panel-card">
        <el-descriptions :column="4" border>
          <el-descriptions-item label="ID">{{ process.id }}</el-descriptions-item>
          <el-descriptions-item label="Тип объекта">{{ process.object_type }}</el-descriptions-item>
          <el-descriptions-item label="Тип процесса">
            {{ process.is_child ? 'Ведомый процесс' : 'Процесс верхнего уровня' }}
          </el-descriptions-item>
          <el-descriptions-item label="Приоритет">{{ process.priority }}</el-descriptions-item>
          <el-descriptions-item label="Однократный запуск">
            {{ process.run_once ? 'Да — не более одного запуска на запись' : 'Нет' }}
          </el-descriptions-item>
          <el-descriptions-item label="Версия">{{ process.version }}</el-descriptions-item>
          <el-descriptions-item label="Создан">{{ formatDate(process.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="Изменён">{{ formatDate(process.updated_at) }}</el-descriptions-item>
          <el-descriptions-item label="Запускается из">
            <template v-if="process.references?.parents?.length">
              <router-link
                v-for="p in process.references.parents"
                :key="p.id"
                :to="`/processes/${p.id}`"
                class="ref-link"
              >
                {{ p.name }}
              </router-link>
            </template>
            <span v-else class="muted">—</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title"><span>Блок 1. Условия запуска <small>все строки по «И»</small></span></div>
        </template>
        <template v-if="conditionsGroup">
          <el-alert type="info" :closable="false" title="Условия в расширенной форме (группы / OR)" class="mb8" />
          <JsonView :value="conditionsGroup" />
        </template>
        <el-table v-else :data="conditionsList" size="small" empty-text="Условий нет — запуск для любого события">
          <el-table-column type="index" label="#" width="50" />
          <el-table-column label="Поле" min-width="180">
            <template #default="{ row }"><span class="mono">{{ row.field }}</span></template>
          </el-table-column>
          <el-table-column label="Тип сравнения" width="160">
            <template #default="{ row }">{{ dictLabel(operators, row.operator) }}</template>
          </el-table-column>
          <el-table-column label="Зона сравнения" width="200">
            <template #default="{ row }">
              {{ row.value_type === 'from_object_field' ? 'Из поля объекта/переменной' : 'Значение' }}
            </template>
          </el-table-column>
          <el-table-column label="Значение" min-width="180">
            <template #default="{ row }"><span class="mono">{{ row.value }}</span></template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title"><span>Блок 2. Предзагрузка</span></div>
        </template>
        <el-table :data="preload" size="small" empty-text="Предзагрузка не настроена">
          <el-table-column label="Переменная" width="120">
            <template #default="{ row }"><b class="mono">{{ row.var }}</b></template>
          </el-table-column>
          <el-table-column label="Тип инициации" width="190">
            <template #default="{ row }">{{ dictLabel(preloadSources, row.source) }}</template>
          </el-table-column>
          <el-table-column label="Поле / значение" min-width="180">
            <template #default="{ row }"><span class="mono">{{ row.value }}</span></template>
          </el-table-column>
          <el-table-column prop="module" label="Модуль" width="160" />
          <el-table-column label="Поле поиска" width="140">
            <template #default="{ row }"><span class="mono">{{ row.by_field || 'id' }}</span></template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <template #header>
          <div class="panel-title"><span>Блок 3. Действия <small>в порядке выполнения</small></span></div>
        </template>
        <el-table :data="actions" size="small" empty-text="Действий нет">
          <el-table-column type="index" label="#" width="50" />
          <el-table-column label="Тип" width="170">
            <template #default="{ row }">
              <el-tag
                size="small"
                :type="row.type === 'start_process' ? 'warning' : row.type === 'save_object' ? 'success' : 'primary'"
                effect="plain"
              >
                {{ actionTypeLabel(row.type) }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="Описание" min-width="360">
            <template #default="{ row }">
              <template v-if="row.type === 'modify'">
                <span class="mono">{{ row.target }}</span>
                ← <span class="muted">{{ dictLabel(changeTypes, row.change_type) }}:</span>
                <span class="mono">{{ row.value }}</span>
                <el-tag v-if="row.save_to_crm" size="small" type="success" class="ml8">+ сохранить в CRM</el-tag>
              </template>
              <template v-else-if="row.type === 'save_object'">
                Сохранить переменную <b class="mono">{{ row.target }}</b> в CRM
                <span v-if="row.module" class="muted">(модуль {{ row.module }})</span>
              </template>
              <template v-else-if="row.type === 'start_process'">
                <span v-for="pid in startIds(row as ProcessAction)" :key="pid" class="ref-link">
                  <router-link :to="`/processes/${pid}`">{{ processNames[pid] ?? `Процесс #${pid}` }}</router-link>
                </span>
              </template>
              <template v-else-if="row.type === 'preload'">
                <b class="mono">{{ row.var }}</b> ← {{ row.module }} по «{{ row.by_field || 'id' }}» =
                <span class="mono">{{ row.value }}</span>
              </template>
              <span v-else class="mono small">{{ JSON.stringify(asRecord(row)) }}</span>
            </template>
          </el-table-column>
        </el-table>
        <div v-if="process.references?.children?.length" class="children-refs">
          <span class="muted">Ведомые процессы:</span>
          <span v-for="c in process.references.children" :key="c.id" class="ref-link">
            <router-link :to="`/processes/${c.id}`">{{ c.name }}</router-link>
            <el-tag v-if="c.is_active === false" size="small" type="info">неактивен</el-tag>
          </span>
        </div>
      </el-card>

      <el-card shadow="never" class="panel-card">
        <el-tabs v-model="activeTab">
          <el-tab-pane label="Журнал выполнения" name="executions">
            <div class="tab-toolbar">
              <el-button :icon="Refresh" size="small" @click="loadExecutions">Обновить</el-button>
            </div>
            <div class="filters">
              <el-select v-model="execFilters.status" placeholder="Статус" clearable style="width: 180px" @change="applyExecFilters">
                <el-option v-for="(s, key) in EXECUTION_STATUS" :key="key" :label="s.label" :value="key" />
              </el-select>
              <el-date-picker
                v-model="execFilters.range"
                type="datetimerange"
                start-placeholder="Начало периода"
                end-placeholder="Конец периода"
                range-separator="—"
                value-format="YYYY-MM-DDTHH:mm:ssZ"
                :default-time="[new Date(2000, 0, 1, 0, 0, 0), new Date(2000, 0, 1, 23, 59, 59)]"
                @change="applyExecFilters"
              />
            </div>
            <el-table
              v-loading="execLoading"
              :data="executions"
              size="small"
              stripe
              class="clickable-rows"
              empty-text="Запусков нет"
              @row-click="openExecution"
            >
              <el-table-column label="ID" width="110">
                <template #default="{ row }">
                  <el-tooltip :content="row.id"><span class="mono">{{ shortId(row.id) }}</span></el-tooltip>
                </template>
              </el-table-column>
              <el-table-column label="ID события" min-width="150">
                <template #default="{ row }"><span class="mono">{{ row.event_id || '—' }}</span></template>
              </el-table-column>
              <el-table-column label="Объект" width="110">
                <template #default="{ row }"><span class="mono">{{ row.object_id || '—' }}</span></template>
              </el-table-column>
              <el-table-column label="Источник" width="130">
                <template #default="{ row }">{{ TRIGGER_LABELS[row.trigger] ?? row.trigger }}</template>
              </el-table-column>
              <el-table-column label="Статус" width="120">
                <template #default="{ row }">
                  <el-tag size="small" :type="statusTag(row.status).type">{{ statusTag(row.status).label }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="Начат" width="160">
                <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
              </el-table-column>
              <el-table-column label="Завершён" width="160">
                <template #default="{ row }">{{ formatDate(row.finished_at) }}</template>
              </el-table-column>
              <el-table-column label="Длительность" width="110">
                <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
              </el-table-column>
              <el-table-column prop="retry_count" label="Retry" width="70" align="center" />
            </el-table>
            <div class="pagination">
              <el-pagination
                background
                small
                layout="total, sizes, prev, pager, next"
                :total="execMeta.total"
                :current-page="execFilters.page"
                :page-size="execFilters.per_page"
                :page-sizes="[10, 20, 50, 100]"
                @current-change="onExecPage"
                @size-change="onExecSize"
              />
            </div>
          </el-tab-pane>

          <el-tab-pane v-if="showRunsTab" name="runs">
            <template #label>
              Однократные запуски <el-badge v-if="runsMeta.total" :value="runsMeta.total" type="info" class="tab-badge" />
            </template>
            <el-alert
              v-if="!process.run_once"
              type="info"
              :closable="false"
              show-icon
              class="mb8"
              title="Однократный запуск выключен — отметки сохранены, но не проверяются"
            />
            <p class="muted run-hint">
              Записи CRM, для которых процесс уже запускался. Повторные события этих записей процесс не запускают.
              «Сбросить» снимает отметку: следующее сохранение записи снова запустит процесс.
            </p>
            <div class="filters">
              <el-input
                v-model="runsFilters.object_id"
                placeholder="ID записи"
                clearable
                style="width: 320px"
                @keyup.enter="applyRunsFilters"
                @clear="applyRunsFilters"
              />
              <el-button @click="applyRunsFilters">Найти</el-button>
              <el-button :icon="Refresh" @click="loadRuns">Обновить</el-button>
            </div>
            <el-table v-loading="runsLoading" :data="runs" size="small" stripe empty-text="Отметок нет">
              <el-table-column prop="object_type" label="Тип объекта" width="130" />
              <el-table-column label="ID записи" min-width="260">
                <template #default="{ row }"><span class="mono">{{ row.object_id }}</span></template>
              </el-table-column>
              <el-table-column label="Выполнение" width="110">
                <template #default="{ row }">
                  <router-link :to="`/executions/${row.execution_id}`" class="mono">{{ shortId(row.execution_id) }}</router-link>
                </template>
              </el-table-column>
              <el-table-column label="Источник" width="120">
                <template #default="{ row }">{{ TRIGGER_LABELS[row.trigger] ?? row.trigger }}</template>
              </el-table-column>
              <el-table-column label="Первый запуск" width="160">
                <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
              </el-table-column>
              <el-table-column prop="blocked_count" label="Заблокировано" width="120" align="center" />
              <el-table-column label="Последняя блокировка" width="170">
                <template #default="{ row }">{{ formatDate(row.last_blocked_at) }}</template>
              </el-table-column>
              <el-table-column v-if="auth.canEdit" label="" width="110" align="right">
                <template #default="{ row }">
                  <el-button size="small" type="warning" plain @click="resetRun(row as ProcessObjectRun)">Сбросить</el-button>
                </template>
              </el-table-column>
            </el-table>
            <div class="pagination">
              <el-pagination
                background
                small
                layout="total, sizes, prev, pager, next"
                :total="runsMeta.total"
                :current-page="runsFilters.page"
                :page-size="runsFilters.per_page"
                :page-sizes="[10, 20, 50, 100]"
                @current-change="onRunsPage"
                @size-change="onRunsSize"
              />
            </div>
          </el-tab-pane>
        </el-tabs>
      </el-card>
    </template>

    <el-dialog v-model="runVisible" title="Ручной запуск процесса" width="760px" :close-on-click-modal="false">
      <p class="muted run-hint">
        Укажите JSON исходного объекта (как он пришёл бы из CRM). Процесс выполнится с этим объектом, прогресс будет
        виден на странице выполнения.
      </p>
      <JsonEditor v-model="runJson" :rows="14" />
      <el-checkbox v-model="runForce" class="force-cb">Без проверки условий (force)</el-checkbox>
      <div v-if="process?.run_once" class="muted small">
        У процесса включён однократный запуск: «force» не обходит его. Чтобы запустить повторно для той же записи,
        снимите отметку во вкладке «Однократные запуски».
      </div>

      <el-alert v-if="runBlockedBy !== undefined" type="warning" show-icon :closable="false" class="trace">
        <template #title>Процесс уже выполнялся для этой записи — новый запуск не создан</template>
        <template v-if="runBlockedBy">
          Выполнение:
          <router-link :to="`/executions/${runBlockedBy}`" class="mono" @click="runVisible = false">{{ runBlockedBy }}</router-link>
        </template>
      </el-alert>

      <div v-if="runTrace" class="trace">
        <el-alert
          type="warning"
          show-icon
          :closable="false"
          title="Условия запуска не выполнены — процесс не запущен"
          description="Ниже — результат проверки каждого условия. Исправьте объект или включите «Без проверки условий»."
        />
        <el-table :data="runTrace" size="small" class="trace-table">
          <el-table-column label="Поле" min-width="140">
            <template #default="{ row }"><span class="mono">{{ row.field }}</span></template>
          </el-table-column>
          <el-table-column label="Оператор" width="120">
            <template #default="{ row }">{{ dictLabel(operators, row.operator) }}</template>
          </el-table-column>
          <el-table-column label="Ожидалось" min-width="120">
            <template #default="{ row }"><span class="mono">{{ traceValue(row.expected) }}</span></template>
          </el-table-column>
          <el-table-column label="Фактически" min-width="120">
            <template #default="{ row }"><span class="mono">{{ traceValue(row.actual) }}</span></template>
          </el-table-column>
          <el-table-column label="Результат" width="110">
            <template #default="{ row }">
              <el-tag v-if="row.result === true" type="success" size="small">OK</el-tag>
              <el-tag v-else-if="row.result === false" type="danger" size="small">FAIL</el-tag>
              <el-tag v-else type="info" size="small">не проверено</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <template #footer>
        <el-button @click="runVisible = false">Отмена</el-button>
        <el-button type="primary" :icon="VideoPlay" :loading="running" :disabled="!!runJsonError" @click="doRun">
          Запустить
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.h-tag {
  margin-left: 8px;
  vertical-align: middle;
}
.ref-link {
  margin-right: 12px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.children-refs {
  margin-top: 10px;
  font-size: 13px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.mb8 {
  margin-bottom: 8px;
}
.ml8 {
  margin-left: 8px;
}
.small {
  font-size: 12px;
}
.run-hint {
  margin-top: 0;
}
.force-cb {
  margin-top: 8px;
}
.trace {
  margin-top: 12px;
}
.trace-table {
  margin-top: 8px;
}
.tab-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}
.tab-badge {
  margin-left: 4px;
}
</style>
