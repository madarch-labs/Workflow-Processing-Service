<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(
  defineProps<{
    modelValue: string
    rows?: number
    readonly?: boolean
    placeholder?: string
  }>(),
  { rows: 12, readonly: false, placeholder: '{ }' },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
}>()

const { t } = useI18n()

const parseError = computed<string | null>(() => {
  if (!props.modelValue.trim()) return t('json.empty')
  try {
    JSON.parse(props.modelValue)
    return null
  } catch (e) {
    return (e as Error).message
  }
})

function onInput(value: string): void {
  emit('update:modelValue', value)
}

function formatJson(): void {
  try {
    emit('update:modelValue', JSON.stringify(JSON.parse(props.modelValue), null, 2))
  } catch {
    /* невалидный JSON — форматирование невозможно */
  }
}

defineExpose({ parseError })
</script>

<template>
  <div class="json-editor">
    <el-input
      type="textarea"
      :model-value="modelValue"
      :rows="rows"
      :readonly="readonly"
      :placeholder="placeholder"
      spellcheck="false"
      class="json-input"
      :class="{ invalid: !!parseError }"
      @update:model-value="onInput"
    />
    <div class="json-footer">
      <span v-if="parseError" class="json-error">
        <el-icon><WarningFilled /></el-icon> {{ t('json.invalid', { error: parseError }) }}
      </span>
      <span v-else class="json-ok"><el-icon><CircleCheckFilled /></el-icon> {{ t('json.valid') }}</span>
      <el-button v-if="!readonly" link type="primary" size="small" :disabled="!!parseError" @click="formatJson">
        {{ t('json.format') }}
      </el-button>
    </div>
  </div>
</template>

<style scoped>
.json-input :deep(textarea) {
  font-family: var(--wps-mono);
  font-size: 13px;
  line-height: 1.45;
}
.json-input.invalid :deep(textarea) {
  box-shadow: 0 0 0 1px var(--el-color-danger) inset;
}
.json-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 4px;
  font-size: 12px;
}
.json-error {
  color: var(--el-color-danger);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.json-ok {
  color: var(--el-color-success);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
</style>
