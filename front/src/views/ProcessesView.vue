<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { CopyDocument, Delete, Edit, Plus, Refresh, Search, View } from '@element-plus/icons-vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useProcessesStore } from '@/stores/processes'
import { processesApi } from '@/api/processes'
import { dictionariesApi } from '@/api/dictionaries'
import type { DictionaryItem, Process } from '@/types'
import { formatDate } from '@/utils/format'

const auth = useAuthStore()
const store = useProcessesStore()
const { processes, meta, loading } = storeToRefs(store)
const router = useRouter()

const modules = ref<DictionaryItem[]>([])

const filters = reactive({
  search: '',
  object_type: '',
  is_child: '' as string | undefined,
  is_active: '' as string | undefined,
  page: 1,
  per_page: 20,
})

let searchTimer: ReturnType<typeof setTimeout> | undefined

function boolFilter(v: string | undefined): boolean | '' {
  return v === 'true' ? true : v === 'false' ? false : ''
}

async function load(): Promise<void> {
  try {
    await store.fetchList({
      search: filters.search.trim() || undefined,
      object_type: filters.object_type || undefined,
      is_child: boolFilter(filters.is_child),
      is_active: boolFilter(filters.is_active),
      page: filters.page,
      per_page: filters.per_page,
    })
  } catch {
    /* сообщение уже показано */
  }
}

function applyFilters(): void {
  filters.page = 1
  load()
}

function onSearchInput(): void {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 400)
}

function resetFilters(): void {
  filters.search = ''
  filters.object_type = ''
  filters.is_child = ''
  filters.is_active = ''
  applyFilters()
}

function onPageChange(page: number): void {
  filters.page = page
  load()
}
function onSizeChange(size: number): void {
  filters.per_page = size
  filters.page = 1
  load()
}

function open(row: Process): void {
  router.push(`/processes/${row.id}`)
}

async function clone(row: Process): Promise<void> {
  try {
    const copy = await processesApi.clone(row.id)
    ElMessage.success(`Создана копия «${copy.name}»`)
    router.push(`/processes/${copy.id}/edit`)
  } catch {
    /* сообщение уже показано */
  }
}

async function remove(row: Process): Promise<void> {
  try {
    await ElMessageBox.confirm(`Удалить процесс «${row.name}»? Действие необратимо.`, 'Удаление процесса', {
      confirmButtonText: 'Удалить',
      cancelButtonText: 'Отмена',
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  try {
    await processesApi.remove(row.id)
    ElMessage.success('Процесс удалён')
    if (processes.value.length === 1 && filters.page > 1) filters.page -= 1
    load()
  } catch {
    /* сообщение уже показано (в т.ч. PROCESS_IN_USE) */
  }
}

onMounted(async () => {
  load()
  modules.value = await dictionariesApi.modules()
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Процессы</h1>
      <div class="actions">
        <el-button :icon="Refresh" @click="load">Обновить</el-button>
        <el-button v-if="auth.canEdit" type="primary" :icon="Plus" @click="router.push('/processes/create')">
          Создать процесс
        </el-button>
      </div>
    </div>

    <el-card shadow="never">
      <div class="filters">
        <el-input
          v-model="filters.search"
          :prefix-icon="Search"
          placeholder="Поиск по названию"
          clearable
          style="width: 260px"
          @input="onSearchInput"
          @clear="applyFilters"
        />
        <el-select
          v-model="filters.object_type"
          placeholder="Тип объекта"
          clearable
          filterable
          allow-create
          default-first-option
          style="width: 200px"
          @change="applyFilters"
        >
          <el-option v-for="m in modules" :key="m.value" :label="m.label" :value="m.value" />
        </el-select>
        <el-select v-model="filters.is_child" placeholder="Тип процесса" clearable style="width: 200px" @change="applyFilters">
          <el-option label="Верхнего уровня" value="false" />
          <el-option label="Ведомый" value="true" />
        </el-select>
        <el-select v-model="filters.is_active" placeholder="Активность" clearable style="width: 160px" @change="applyFilters">
          <el-option label="Активные" value="true" />
          <el-option label="Неактивные" value="false" />
        </el-select>
        <el-button link type="primary" @click="resetFilters">Сбросить</el-button>
      </div>

      <el-table
        v-loading="loading"
        :data="processes"
        stripe
        class="clickable-rows"
        empty-text="Процессы не найдены"
        @row-dblclick="open"
      >
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="Название" min-width="240">
          <template #default="{ row }">
            <router-link :to="`/processes/${row.id}`">{{ row.name }}</router-link>
            <el-tooltip v-if="row.run_once" content="Однократный запуск: не более одного раза на запись CRM">
              <el-tag size="small" type="warning" effect="dark" class="run-once-tag">1×</el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column prop="object_type" label="Тип объекта" min-width="130" />
        <el-table-column label="Тип процесса" width="150">
          <template #default="{ row }">
            <el-tag :type="row.is_child ? 'warning' : 'primary'" effect="plain">
              {{ row.is_child ? 'Ведомый' : 'Верхнего уровня' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Активность" width="120">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? 'Активен' : 'Неактивен' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="priority" label="Приоритет" width="100" align="center" />
        <el-table-column label="Изменён" width="170">
          <template #default="{ row }">{{ formatDate(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column label="Действия" width="170" fixed="right">
          <template #default="{ row }">
            <el-tooltip content="Просмотр">
              <el-button :icon="View" circle size="small" @click.stop="open(row as Process)" />
            </el-tooltip>
            <template v-if="auth.canEdit">
              <el-tooltip content="Редактировать">
                <el-button :icon="Edit" circle size="small" @click.stop="router.push(`/processes/${row.id}/edit`)" />
              </el-tooltip>
              <el-tooltip content="Клонировать">
                <el-button :icon="CopyDocument" circle size="small" @click.stop="clone(row as Process)" />
              </el-tooltip>
            </template>
            <el-tooltip v-if="auth.isAdmin" content="Удалить">
              <el-button :icon="Delete" circle size="small" type="danger" plain @click.stop="remove(row as Process)" />
            </el-tooltip>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next"
          :total="meta.total"
          :current-page="filters.page"
          :page-size="filters.per_page"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.run-once-tag {
  margin-left: 8px;
}
</style>
