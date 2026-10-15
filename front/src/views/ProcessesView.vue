<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
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

const { t } = useI18n()
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
    ElMessage.success(t('processes.cloned', { name: copy.name }))
    router.push(`/processes/${copy.id}/edit`)
  } catch {
    /* сообщение уже показано */
  }
}

async function remove(row: Process): Promise<void> {
  try {
    await ElMessageBox.confirm(t('processes.deleteConfirm', { name: row.name }), t('processes.deleteTitle'), {
      confirmButtonText: t('common.delete'),
      cancelButtonText: t('common.cancel'),
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  try {
    await processesApi.remove(row.id)
    ElMessage.success(t('processes.deleted'))
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
      <h1>{{ t('processes.title') }}</h1>
      <div class="actions">
        <el-button :icon="Refresh" @click="load">{{ t('common.refresh') }}</el-button>
        <el-button v-if="auth.canEdit" type="primary" :icon="Plus" @click="router.push('/processes/create')">
          {{ t('processes.create') }}
        </el-button>
      </div>
    </div>

    <el-card shadow="never">
      <div class="filters">
        <el-input
          v-model="filters.search"
          :prefix-icon="Search"
          :placeholder="t('processes.searchPlaceholder')"
          clearable
          style="width: 260px"
          @input="onSearchInput"
          @clear="applyFilters"
        />
        <el-select
          v-model="filters.object_type"
          :placeholder="t('processes.objectType')"
          clearable
          filterable
          allow-create
          default-first-option
          style="width: 200px"
          @change="applyFilters"
        >
          <el-option v-for="m in modules" :key="m.value" :label="m.label" :value="m.value" />
        </el-select>
        <el-select v-model="filters.is_child" :placeholder="t('processes.processType')" clearable style="width: 200px" @change="applyFilters">
          <el-option :label="t('processes.topLevel')" value="false" />
          <el-option :label="t('processes.child')" value="true" />
        </el-select>
        <el-select v-model="filters.is_active" :placeholder="t('processes.activity')" clearable style="width: 160px" @change="applyFilters">
          <el-option :label="t('processes.activeFilter')" value="true" />
          <el-option :label="t('processes.inactiveFilter')" value="false" />
        </el-select>
        <el-button link type="primary" @click="resetFilters">{{ t('common.reset') }}</el-button>
      </div>

      <el-table
        v-loading="loading"
        :data="processes"
        stripe
        class="clickable-rows"
        :empty-text="t('processes.empty')"
        @row-dblclick="open"
      >
        <el-table-column prop="id" :label="t('common.id')" width="70" />
        <el-table-column :label="t('common.name')" min-width="240">
          <template #default="{ row }">
            <router-link :to="`/processes/${row.id}`">{{ row.name }}</router-link>
            <el-tooltip v-if="row.run_once" :content="t('processes.runOnceHint')">
              <el-tag size="small" type="warning" effect="dark" class="run-once-tag">1×</el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column prop="object_type" :label="t('processes.objectType')" min-width="130" />
        <el-table-column :label="t('processes.processType')" width="150">
          <template #default="{ row }">
            <el-tag :type="row.is_child ? 'warning' : 'primary'" effect="plain">
              {{ row.is_child ? t('processes.child') : t('processes.topLevel') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('processes.activity')" width="120">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="priority" :label="t('processes.priority')" width="100" align="center" />
        <el-table-column :label="t('processes.updatedAt')" width="170">
          <template #default="{ row }">{{ formatDate(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column :label="t('common.actions')" width="170" fixed="right">
          <template #default="{ row }">
            <el-tooltip :content="t('common.view')">
              <el-button :icon="View" circle size="small" @click.stop="open(row as Process)" />
            </el-tooltip>
            <template v-if="auth.canEdit">
              <el-tooltip :content="t('common.edit')">
                <el-button :icon="Edit" circle size="small" @click.stop="router.push(`/processes/${row.id}/edit`)" />
              </el-tooltip>
              <el-tooltip :content="t('common.clone')">
                <el-button :icon="CopyDocument" circle size="small" @click.stop="clone(row as Process)" />
              </el-tooltip>
            </template>
            <el-tooltip v-if="auth.isAdmin" :content="t('common.delete')">
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
