<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, Close, Select } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { emptyForm, formFromProcess, payloadFromForm, useProcessesStore } from '@/stores/processes'
import { dictionariesApi, FALLBACK } from '@/api/dictionaries'
import { ApiRequestError, showApiError } from '@/api/client'
import type { Condition, DictionaryItem, ModifyRow, PreloadAction, ProcessFormData, StartProcessRow } from '@/types'
import { errorDetailLines } from '@/utils/errors'
import { collectVariables, pathSuggestions, pathVar, VAR_NAME_RE } from '@/utils/variables'
import ProcessHeader from '@/components/ProcessHeader.vue'
import ConditionsPanel from '@/components/ConditionsPanel.vue'
import PreloadPanel from '@/components/PreloadPanel.vue'
import ModifyPanel from '@/components/ModifyPanel.vue'
import StartProcessPanel from '@/components/StartProcessPanel.vue'
import VariableSidebar from '@/components/VariableSidebar.vue'
import JsonView from '@/components/JsonView.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useProcessesStore()

const processId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEdit = computed(() => processId.value !== null)

const form = ref<ProcessFormData>(emptyForm())
const version = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const snapshot = ref('')

const modules = ref<DictionaryItem[]>([])
const modulesLoading = ref(false)
const operators = ref<DictionaryItem[]>(FALLBACK.operators)
const changeTypes = ref<DictionaryItem[]>(FALLBACK.changeTypes)
const preloadSources = ref<DictionaryItem[]>(FALLBACK.preloadSources)

const headerRef = ref<InstanceType<typeof ProcessHeader>>()
const openBlocks = ref(['preload', 'modify', 'start'])

/** Ошибки для отображения (клиентские или серверные) */
const errorTitle = ref('')
const errorLines = ref<string[]>([])

// ---------- производные данные ----------
const variables = computed(() =>
  collectVariables({
    objectType: form.value.object_type,
    conditions: form.value.conditions,
    preload: form.value.preload,
    modifyActions: form.value.modifyActions,
  }),
)
const suggestions = computed(() => pathSuggestions(variables.value))
const variableNames = computed(() => variables.value.map((v) => v.name))
const createdNames = computed(() => variables.value.filter((v) => v.kind !== 'preload' && v.kind !== 'object').map((v) => v.name))

const modifyCount = computed(() => form.value.modifyActions.length)

// ---------- обновление частей формы ----------
function setConditions(v: Condition[]): void {
  form.value = { ...form.value, conditions: v }
}
function setPreload(v: PreloadAction[]): void {
  form.value = { ...form.value, preload: v }
}
function setModify(v: ModifyRow[]): void {
  form.value = { ...form.value, modifyActions: v }
}
function setStart(v: StartProcessRow[]): void {
  form.value = { ...form.value, startActions: v }
}

function convertRawConditions(): void {
  const raw = form.value.conditionsRaw
  if (!raw) return
  const simple = raw.logical_operator === 'AND' && (!raw.groups || raw.groups.length === 0)
  form.value = {
    ...form.value,
    conditionsRaw: null,
    conditions: simple ? (raw.conditions ?? []).map((c) => ({ ...c, value: String(c.value ?? '') })) : [],
  }
}

// ---------- загрузка ----------
async function loadModules(refresh = false): Promise<void> {
  modulesLoading.value = true
  try {
    modules.value = refresh ? await dictionariesApi.refreshModules() : await dictionariesApi.modules()
    if (refresh) ElMessage.success('Список модулей обновлён')
  } catch {
    /* сообщение уже показано */
  } finally {
    modulesLoading.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  const dicts = Promise.all([
    loadModules(),
    dictionariesApi.operators().then((v) => (operators.value = v)),
    dictionariesApi.changeTypes().then((v) => (changeTypes.value = v)),
    dictionariesApi.preloadSources().then((v) => (preloadSources.value = v)),
  ])
  try {
    if (isEdit.value) {
      const p = await store.fetchOne(processId.value!)
      form.value = formFromProcess(p)
      version.value = p.version
    } else {
      form.value = emptyForm()
    }
    snapshot.value = serialize()
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : 'Не удалось загрузить процесс'
  } finally {
    loading.value = false
  }
  await dicts
}

function serialize(): string {
  return JSON.stringify(payloadFromForm(form.value))
}
const dirty = computed(() => !loading.value && !loadError.value && snapshot.value !== '' && serialize() !== snapshot.value)

// ---------- клиентская валидация ----------
function validateRows(): string[] {
  const errors: string[] = []
  const f = form.value
  f.conditions.forEach((c, i) => {
    const n = `Условие ${i + 1}`
    if (!c.field.trim() || !c.field.includes('.') || c.field.trim().endsWith('.')) errors.push(`${n}: укажите поле (object.поле)`)
    if (c.value_type === 'from_object_field' && (!c.value.trim() || !c.value.includes('.')))
      errors.push(`${n}: укажите путь поля для сравнения`)
  })
  const names: string[] = []
  f.preload.forEach((p, i) => {
    const n = `Предзагрузка ${i + 1} (${p.var || '?'})`
    const name = p.var.trim()
    if (!name || !VAR_NAME_RE.test(name)) errors.push(`${n}: некорректное имя переменной`)
    else if (name === 'object') errors.push(`${n}: имя «object» зарезервировано`)
    else if (names.includes(name)) errors.push(`${n}: имя переменной уже используется`)
    names.push(name)
    if (!p.module.trim()) errors.push(`${n}: укажите модуль`)
    if (!p.value.trim() || (p.source !== 'manual' && p.value.trim().endsWith('.'))) errors.push(`${n}: укажите значение`)
    if (p.source === 'object_field' && !p.value.trim().startsWith('object.'))
      errors.push(`${n}: путь должен начинаться с object.`)
    if (p.source === 'variable_field' && !names.slice(0, -1).includes(pathVar(p.value)))
      errors.push(`${n}: переменная «${pathVar(p.value)}» должна быть объявлена выше`)
  })
  f.modifyActions.forEach((r, i) => {
    const n = `Действие ${i + 1}`
    if (r.type === 'modify') {
      const t = r.target.trim()
      if (!t || !t.includes('.') || t.endsWith('.')) errors.push(`${n}: укажите поле назначения (object.поле или v1.поле)`)
      if (r.change_type !== 'set_value' && !r.value.trim()) errors.push(`${n}: укажите значение`)
    } else if (r.type === 'save_object') {
      if (!r.target.trim()) errors.push(`${n}: укажите сохраняемую переменную`)
    }
  })
  f.startActions.forEach((s, i) => {
    if (s.process_id === null) errors.push(`Запуск процесса ${i + 1}: выберите процесс`)
    if (s.process_id !== null && s.process_id === f.id) errors.push(`Запуск процесса ${i + 1}: процесс не может запускать сам себя`)
  })
  return errors
}

function showErrors(title: string, lines: string[]): void {
  errorTitle.value = title
  errorLines.value = lines
  nextTick(() => document.querySelector('.edit-errors')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
}

function clearErrors(): void {
  errorTitle.value = ''
  errorLines.value = []
}

// ---------- сохранение ----------
async function save(activate: boolean): Promise<void> {
  clearErrors()
  const headerOk = (await headerRef.value?.validate()) ?? true
  const rowErrors = validateRows()
  if (!headerOk || rowErrors.length) {
    const lines = [...(!headerOk ? ['Заполните обязательные поля шапки процесса'] : []), ...rowErrors]
    showErrors('Исправьте ошибки перед сохранением', lines)
    ElMessage.error('Форма содержит ошибки')
    return
  }
  saving.value = true
  try {
    const saved = await store.saveProcess(form.value, activate)
    snapshot.value = serialize()
    ElMessage.success(isEdit.value ? `Процесс сохранён (версия ${saved.version})` : 'Процесс создан')
    router.push(`/processes/${saved.id}`)
  } catch (e) {
    if (e instanceof ApiRequestError && (e.status === 422 || e.code === 'CYCLE_DETECTED')) {
      const title =
        e.code === 'CYCLE_DETECTED'
          ? 'Обнаружен цикл запуска процессов — процесс не может (косвенно) запускать сам себя'
          : e.message && e.message !== 'Validation failed'
            ? e.message
            : 'Ошибка валидации на сервере'
      showErrors(title, errorDetailLines(e.details))
      ElMessage.error('Сервер отклонил сохранение')
    } else {
      showApiError(e)
    }
  } finally {
    saving.value = false
  }
}

function cancel(): void {
  if (isEdit.value) router.push(`/processes/${processId.value}`)
  else router.push('/processes')
}

onBeforeRouteLeave(async () => {
  if (!dirty.value || saving.value) return true
  try {
    await ElMessageBox.confirm('Есть несохранённые изменения. Покинуть страницу?', 'Несохранённые изменения', {
      confirmButtonText: 'Покинуть',
      cancelButtonText: 'Остаться',
      type: 'warning',
    })
    return true
  } catch {
    return false
  }
})

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="process-edit">
    <div class="page-header">
      <h1>
        {{ isEdit ? 'Редактирование процесса' : 'Новый процесс' }}
        <span v-if="isEdit" class="muted id-label">#{{ processId }} · версия {{ version }}</span>
        <el-tag v-if="dirty" type="warning" size="small" class="dirty-tag">не сохранено</el-tag>
      </h1>
      <div class="actions">
        <el-button :icon="Close" @click="cancel">Отмена</el-button>
        <el-button type="primary" plain :icon="Check" :loading="saving" :disabled="!!loadError" @click="save(false)">
          Сохранить
        </el-button>
        <el-button type="success" :icon="Select" :loading="saving" :disabled="!!loadError" @click="save(true)">
          Сохранить и активировать
        </el-button>
      </div>
    </div>

    <el-result v-if="loadError" icon="error" title="Не удалось загрузить процесс" :sub-title="loadError">
      <template #extra>
        <el-button @click="router.push('/processes')">К списку процессов</el-button>
      </template>
    </el-result>

    <template v-else>
      <el-alert
        v-if="errorTitle"
        class="edit-errors"
        type="error"
        :title="errorTitle"
        show-icon
        closable
        @close="clearErrors"
      >
        <ul v-if="errorLines.length" class="error-list">
          <li v-for="(line, i) in errorLines" :key="i">{{ line }}</li>
        </ul>
      </el-alert>

      <el-row :gutter="16">
        <el-col :span="18">
          <el-card shadow="never" class="panel-card">
            <template #header>
              <div class="panel-title"><span>Конфигурация процесса</span></div>
            </template>
            <ProcessHeader
              ref="headerRef"
              v-model="form"
              :modules="modules"
              :modules-loading="modulesLoading"
              :can-refresh-modules="auth.canEdit"
              @refresh-modules="loadModules(true)"
            />
          </el-card>

          <el-card shadow="never" class="panel-card">
            <template #header>
              <div class="panel-title">
                <span>Блок 1. Условия запуска <small>все строки объединяются по «И»</small></span>
                <el-tag size="small" round>{{ form.conditionsRaw ? 'расширенные' : form.conditions.length }}</el-tag>
              </div>
            </template>
            <template v-if="form.conditionsRaw">
              <el-alert type="warning" :closable="false" show-icon class="raw-alert">
                <template #title>
                  Условия заданы в расширенной форме (группы / OR). Табличный редактор их не поддерживает — они будут
                  сохранены без изменений.
                </template>
              </el-alert>
              <JsonView :value="form.conditionsRaw" />
              <el-button size="small" class="convert-btn" @click="convertRawConditions">
                Заменить на табличные условия (И)
              </el-button>
            </template>
            <ConditionsPanel
              v-else
              :conditions="form.conditions"
              :operators="operators"
              :suggestions="suggestions"
              @update:conditions="setConditions"
            />
          </el-card>

          <el-card shadow="never" class="panel-card">
            <template #header>
              <div class="panel-title"><span>Блок 2. Действия <small>выполняются последовательно</small></span></div>
            </template>
            <el-collapse v-model="openBlocks">
              <el-collapse-item name="preload">
                <template #title>
                  <span class="block-title">Предзагрузка</span>
                  <el-tag size="small" round class="count">{{ form.preload.length }}</el-tag>
                </template>
                <PreloadPanel
                  :preload="form.preload"
                  :sources="preloadSources"
                  :modules="modules"
                  :reserved-names="createdNames"
                  :suggestions="suggestions"
                  @update:preload="setPreload"
                />
              </el-collapse-item>
              <el-collapse-item name="modify">
                <template #title>
                  <span class="block-title">Изменить объект / переменную</span>
                  <el-tag size="small" round class="count">{{ modifyCount }}</el-tag>
                </template>
                <ModifyPanel
                  :modify-actions="form.modifyActions"
                  :change-types="changeTypes"
                  :modules="modules"
                  :variable-names="variableNames"
                  :suggestions="suggestions"
                  @update:modify-actions="setModify"
                />
              </el-collapse-item>
              <el-collapse-item name="start">
                <template #title>
                  <span class="block-title">Запустить следующий процесс</span>
                  <el-tag size="small" round class="count">{{ form.startActions.length }}</el-tag>
                </template>
                <StartProcessPanel
                  :start-actions="form.startActions"
                  :current-process-id="form.id"
                  :current-process-name="form.name"
                  @update:start-actions="setStart"
                />
              </el-collapse-item>
            </el-collapse>
          </el-card>
        </el-col>

        <el-col :span="6">
          <div class="sidebar-sticky">
            <VariableSidebar :variables="variables" :sources="preloadSources" />
          </div>
        </el-col>
      </el-row>
    </template>
  </div>
</template>

<style scoped>
.id-label {
  font-size: 14px;
  font-weight: normal;
  margin-left: 8px;
}
.dirty-tag {
  margin-left: 8px;
  vertical-align: middle;
}
.edit-errors {
  margin-bottom: 16px;
}
.error-list {
  margin: 6px 0 0;
  padding-left: 18px;
  line-height: 1.6;
}
.block-title {
  font-weight: 600;
  font-size: 14px;
}
.count {
  margin-left: 8px;
}
.sidebar-sticky {
  position: sticky;
  top: 0;
}
.raw-alert {
  margin-bottom: 8px;
}
.convert-btn {
  margin-top: 8px;
}
</style>
