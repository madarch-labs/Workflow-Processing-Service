<script setup lang="ts">
/** Поле ввода пути (object.field / v1.field) с подсказками */
const props = withDefaults(
  defineProps<{
    modelValue: string
    suggestions?: string[]
    placeholder?: string
    invalid?: boolean
  }>(),
  { suggestions: () => [], placeholder: 'object.поле', invalid: false },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

function fetch(query: string, cb: (items: { value: string }[]) => void): void {
  const q = (query ?? '').toLowerCase()
  cb(
    props.suggestions
      .filter((s) => !q || s.toLowerCase().includes(q))
      .slice(0, 50)
      .map((value) => ({ value })),
  )
}
</script>

<template>
  <el-autocomplete
    :model-value="modelValue"
    :fetch-suggestions="fetch"
    :placeholder="placeholder"
    :trigger-on-focus="true"
    clearable
    class="path-input"
    :class="{ 'is-invalid': invalid }"
    @update:model-value="(v: string | number) => emit('update:modelValue', String(v ?? ''))"
  />
</template>

<style scoped>
.path-input {
  width: 100%;
}
.path-input :deep(.el-input__inner) {
  font-family: var(--wps-mono);
  font-size: 13px;
}
.path-input.is-invalid :deep(.el-input__wrapper) {
  box-shadow: 0 0 0 1px var(--el-color-danger) inset;
}
</style>
