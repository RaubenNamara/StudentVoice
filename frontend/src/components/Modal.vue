<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const props = withDefaults(defineProps<{ title: string; wide?: boolean }>(), { wide: false })
const emit = defineEmits<{ close: [] }>()

const onKey = (e: KeyboardEvent) => e.key === 'Escape' && emit('close')
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
void props
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-indigo-950/40 p-0 backdrop-blur-sm sm:items-center sm:p-4" @click.self="emit('close')">
      <div role="dialog" aria-modal="true" class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-surface shadow-lift sm:rounded-2xl" :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-md'">
        <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
          <h2 class="text-base font-semibold">{{ title }}</h2>
          <button class="btn-ghost -mr-2 px-2" aria-label="Close" @click="emit('close')"><XMarkIcon class="h-5 w-5" /></button>
        </div>
        <div class="p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
