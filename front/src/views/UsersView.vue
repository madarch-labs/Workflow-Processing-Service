<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Delete, Edit, Plus } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { usersApi } from '@/api/users'
import type { PaginationMeta, Role, User, UserPayload } from '@/types'
import { formatDate, ROLE_LABELS, ROLE_TAG } from '@/utils/format'

const auth = useAuthStore()

const users = ref<User[]>([])
const meta = ref<PaginationMeta>({ page: 1, per_page: 20, total: 0 })
const loading = ref(false)
const page = ref(1)
const perPage = ref(20)

const ROLES: Role[] = ['admin', 'editor', 'viewer']
const ROLE_HINTS: Record<Role, string> = {
  admin: 'полный доступ, управление пользователями',
  editor: 'создание и редактирование процессов, ручной запуск',
  viewer: 'только просмотр процессов и аудита',
}

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
    { required: true, message: 'Введите email', trigger: 'blur' },
    { type: 'email', message: 'Некорректный email', trigger: 'blur' },
  ],
  password: isEdit.value
    ? [{ min: 6, message: 'Не менее 6 символов', trigger: 'blur' }]
    : [
        { required: true, message: 'Введите пароль', trigger: 'blur' },
        { min: 6, message: 'Не менее 6 символов', trigger: 'blur' },
      ],
  role: [{ required: true, message: 'Выберите роль', trigger: 'change' }],
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
      ElMessage.success('Пользователь обновлён')
    } else {
      await usersApi.create(payload)
      ElMessage.success('Пользователь создан')
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
    await ElMessageBox.confirm(`Удалить пользователя ${user.email}?`, 'Удаление пользователя', {
      confirmButtonText: 'Удалить',
      cancelButtonText: 'Отмена',
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  try {
    await usersApi.remove(user.id)
    ElMessage.success('Пользователь удалён')
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
      <h1>Пользователи</h1>
      <div class="actions">
        <el-button type="primary" :icon="Plus" @click="openCreate">Добавить пользователя</el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="users" stripe empty-text="Пользователей нет">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="Email" min-width="240">
          <template #default="{ row }">
            {{ row.email }}
            <el-tag v-if="row.id === auth.user?.id" size="small" type="info" class="me-tag">это вы</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Роль" width="170">
          <template #default="{ row }">
            <el-tag :type="ROLE_TAG[row.role]">{{ ROLE_LABELS[row.role] ?? row.role }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Создан" width="170">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="Изменён" width="170">
          <template #default="{ row }">{{ formatDate(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column label="Действия" width="120" fixed="right">
          <template #default="{ row }">
            <el-tooltip content="Редактировать">
              <el-button :icon="Edit" circle size="small" @click="openEdit(row as User)" />
            </el-tooltip>
            <el-tooltip :content="row.id === auth.user?.id ? 'Нельзя удалить самого себя' : 'Удалить'">
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
      :title="isEdit ? 'Редактирование пользователя' : 'Новый пользователь'"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="submit">
        <el-form-item label="Email" prop="email">
          <el-input v-model="form.email" autocomplete="off" />
        </el-form-item>
        <el-form-item label="Пароль" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="isEdit ? 'Оставьте пустым, чтобы не менять' : 'Не менее 6 символов'"
          />
        </el-form-item>
        <el-form-item label="Роль" prop="role">
          <el-radio-group v-model="form.role" class="roles">
            <el-radio v-for="r in ROLES" :key="r" :value="r">
              <b>{{ ROLE_LABELS[r] }}</b> <span class="muted">— {{ ROLE_HINTS[r] }}</span>
            </el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">Отмена</el-button>
        <el-button type="primary" :loading="saving" @click="submit">{{ isEdit ? 'Сохранить' : 'Создать' }}</el-button>
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
