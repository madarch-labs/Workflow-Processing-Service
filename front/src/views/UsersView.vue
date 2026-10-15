<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Delete, Edit, Plus } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { usersApi } from '@/api/users'
import type { PaginationMeta, Role, User, UserPayload } from '@/types'
import { formatDate, ROLE_TAG, roleLabel } from '@/utils/format'

const { t } = useI18n()
const auth = useAuthStore()

const users = ref<User[]>([])
const meta = ref<PaginationMeta>({ page: 1, per_page: 20, total: 0 })
const loading = ref(false)
const page = ref(1)
const perPage = ref(20)

const ROLES: Role[] = ['admin', 'editor', 'viewer']

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await usersApi.list(page.value, perPage.value)
    users.value = res.items
    meta.value = res.meta
  } catch {
    /* сообщение уже показано */
  } finally {
    loading.value = false
  }
}

// ---------- диалог ----------
const dialogVisible = ref(false)
const editing = ref<User | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ email: '', password: '', role: 'viewer' as Role })
const isEdit = computed(() => editing.value !== null)

const rules = computed<FormRules>(() => ({
  email: [
    { required: true, message: t('users.emailRequired'), trigger: 'blur' },
    { type: 'email', message: t('users.emailInvalid'), trigger: 'blur' },
  ],
  password: isEdit.value
    ? [{ min: 6, message: t('users.passwordMin'), trigger: 'blur' }]
    : [
        { required: true, message: t('users.passwordRequired'), trigger: 'blur' },
        { min: 6, message: t('users.passwordMin'), trigger: 'blur' },
      ],
  role: [{ required: true, message: t('users.roleRequired'), trigger: 'change' }],
}))

function openCreate(): void {
  editing.value = null
  form.email = ''
  form.password = ''
  form.role = 'viewer'
  dialogVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(user: User): void {
  editing.value = user
  form.email = user.email
  form.password = ''
  form.role = user.role
  dialogVisible.value = true
  formRef.value?.clearValidate()
}

async function submit(): Promise<void> {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  saving.value = true
  try {
    const payload: UserPayload = { email: form.email.trim(), role: form.role }
    if (form.password) payload.password = form.password
    if (editing.value) {
      const updated = await usersApi.update(editing.value.id, payload)
      if (updated?.id === auth.user?.id) await auth.fetchMe()
      ElMessage.success(t('users.updated'))
    } else {
      await usersApi.create(payload)
      ElMessage.success(t('users.created'))
    }
    dialogVisible.value = false
    load()
  } catch {
    /* сообщение уже показано (EMAIL_EXISTS, VALIDATION_ERROR и т.п.) */
  } finally {
    saving.value = false
  }
}

async function remove(user: User): Promise<void> {
  try {
    await ElMessageBox.confirm(t('users.deleteConfirm', { email: user.email }), t('users.deleteTitle'), {
      confirmButtonText: t('common.delete'),
      cancelButtonText: t('common.cancel'),
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  try {
    await usersApi.remove(user.id)
    ElMessage.success(t('users.deleted'))
    if (users.value.length === 1 && page.value > 1) page.value -= 1
    load()
  } catch {
    /* CANNOT_DELETE_SELF / LAST_ADMIN — сообщение уже показано */
  }
}

function onPage(p: number): void {
  page.value = p
  load()
}
function onSize(s: number): void {
  perPage.value = s
  page.value = 1
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('users.title') }}</h1>
      <div class="actions">
        <el-button type="primary" :icon="Plus" @click="openCreate">{{ t('users.add') }}</el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="users" stripe :empty-text="t('users.empty')">
        <el-table-column prop="id" :label="t('common.id')" width="70" />
        <el-table-column :label="t('users.email')" min-width="240">
          <template #default="{ row }">
            {{ row.email }}
            <el-tag v-if="row.id === auth.user?.id" size="small" type="info" class="me-tag">{{ t('users.you') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('users.role')" width="170">
          <template #default="{ row }">
            <el-tag :type="ROLE_TAG[row.role]">{{ roleLabel(row.role) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('users.createdAt')" width="170">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column :label="t('users.updatedAt')" width="170">
          <template #default="{ row }">{{ formatDate(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column :label="t('common.actions')" width="120" fixed="right">
          <template #default="{ row }">
            <el-tooltip :content="t('common.edit')">
              <el-button :icon="Edit" circle size="small" @click="openEdit(row as User)" />
            </el-tooltip>
            <el-tooltip :content="row.id === auth.user?.id ? t('users.cannotDeleteSelf') : t('common.delete')">
              <el-button
                :icon="Delete"
                circle
                size="small"
                type="danger"
                plain
                :disabled="row.id === auth.user?.id"
                @click="remove(row as User)"
              />
            </el-tooltip>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next"
          :total="meta.total"
          :current-page="page"
          :page-size="perPage"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="onPage"
          @size-change="onSize"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? t('users.editTitle') : t('users.createTitle')"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="submit">
        <el-form-item :label="t('users.email')" prop="email">
          <el-input v-model="form.email" autocomplete="off" />
        </el-form-item>
        <el-form-item :label="t('users.password')" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="isEdit ? t('users.passwordKeep') : t('users.passwordMin')"
          />
        </el-form-item>
        <el-form-item :label="t('users.role')" prop="role">
          <el-radio-group v-model="form.role" class="roles">
            <el-radio v-for="r in ROLES" :key="r" :value="r">
              <b>{{ roleLabel(r) }}</b> <span class="muted">— {{ t(`users.roleHint.${r}`) }}</span>
            </el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submit">{{ isEdit ? t('common.save') : t('users.create') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.me-tag {
  margin-left: 6px;
}
.roles {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}
</style>
