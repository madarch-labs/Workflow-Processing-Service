<script setup lang="ts">
import { reactive, ref } from 'vue'
import type { FormInstance, FormRules } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import type { DictionaryItem, ProcessFormData } from '@/types'

const props = withDefaults(
  defineProps<{
    modelValue: ProcessFormData
    modules: DictionaryItem[]
    modulesLoading?: boolean
    canRefreshModules?: boolean
  }>(),
  { modulesLoading: false, canRefreshModules: false },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: ProcessFormData): void
  (e: 'refresh-modules'): void
}>()

const formRef = ref<FormInstance>()

function update<K extends keyof ProcessFormData>(key: K, value: ProcessFormData[K]): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

const rules = reactive<FormRules>({
  name: [
    { required: true, message: 'Укажите наименование процесса', trigger: 'blur' },
    { max: 255, message: 'Не более 255 символов', trigger: 'blur' },
  ],
  object_type: [{ required: true, message: 'Выберите тип объекта', trigger: 'change' }],
  priority: [{ required: true, message: 'Укажите приоритет', trigger: 'blur' }],
})

async function validate(): Promise<boolean> {
  if (!formRef.value) return true
  try {
    await formRef.value.validate()
    return true
  } catch {
    return false
  }
}

defineExpose({ validate })
</script>

<template>
  <el-form
    ref="formRef"
    :model="modelValue"
    :rules="rules"
    label-position="top"
    class="process-header"
    @submit.prevent
  >
    <el-row :gutter="16">
      <el-col :span="10">
        <el-form-item label="Наименование процесса" prop="name">
          <el-input
            :model-value="modelValue.name"
            placeholder="Например: VIP-контрагенты"
            maxlength="255"
            @update:model-value="(v: string) => update('name', v)"
          />
        </el-form-item>
      </el-col>
      <el-col :span="8">
        <el-form-item label="Тип процесса">
          <el-radio-group
            :model-value="modelValue.is_child"
            @update:model-value="(v) => update('is_child', !!v)"
          >
            <el-radio :value="false">Процесс верхнего уровня</el-radio>
            <el-radio :value="true">Ведомый процесс</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-col>
      <el-col :span="6">
        <el-form-item label="Статус">
          <el-switch
            :model-value="modelValue.is_active"
            active-text="Активен"
            inactive-text="Неактивен"
            @update:model-value="(v) => update('is_active', !!v)"
          />
        </el-form-item>
      </el-col>
    </el-row>
    <el-row :gutter="16">
      <el-col :span="10">
        <el-form-item label="Тип объекта (object.type)" prop="object_type">
          <div class="module-select">
            <el-select
              :model-value="modelValue.object_type"
              filterable
              allow-create
              default-first-option
              clearable
              :loading="modulesLoading"
              placeholder="Модуль CRM, например Accounts"
              @update:model-value="(v: string) => update('object_type', v ?? '')"
            >
              <el-option v-for="m in modules" :key="m.value" :label="m.label" :value="m.value">
                <span>{{ m.label }}</span>
                <span v-if="m.label !== m.value" class="opt-value">{{ m.value }}</span>
              </el-option>
            </el-select>
            <el-tooltip v-if="canRefreshModules" content="Обновить список модулей из CRM">
              <el-button :icon="Refresh" :loading="modulesLoading" @click="emit('refresh-modules')" />
            </el-tooltip>
          </div>
        </el-form-item>
      </el-col>
      <el-col :span="4">
        <el-form-item label="Приоритет" prop="priority">
          <el-input-number
            :model-value="modelValue.priority"
            :step="1"
            controls-position="right"
            @update:model-value="(v) => update('priority', Number(v ?? 0))"
          />
          <div class="hint">Меньше — выполняется раньше</div>
        </el-form-item>
      </el-col>
      <el-col :span="10">
        <el-form-item label="Повторные запуски">
          <el-checkbox
            :model-value="modelValue.run_once"
            data-test="run-once"
            @update:model-value="(v) => update('run_once', !!v)"
          >
            Однократный запуск для записи
          </el-checkbox>
          <div class="hint">
            Процесс выполнится для каждой записи CRM не более одного раза. Повторные сохранения записи
            игнорируются. Снять ограничение для записи можно во вкладке «Однократные запуски».
            Работает только для событий, у которых есть id записи.
          </div>
        </el-form-item>
      </el-col>
    </el-row>
  </el-form>
</template>

<style scoped>
.process-header :deep(.el-form-item) {
  margin-bottom: 14px;
}
.module-select {
  display: flex;
  gap: 6px;
  width: 100%;
}
.module-select .el-select {
  flex: 1;
}
.opt-value {
  float: right;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  margin-left: 12px;
}
.hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  line-height: 1.4;
  width: 100%;
}
</style>
