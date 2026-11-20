<script setup lang="ts">
import { computed } from 'vue'
import { CLASSES } from '@/types'

/** Which classes may vote; an empty list means every class */
const model = defineModel<string[]>({ required: true })
const everyone = computed(() => model.value.length === 0 || model.value.length === CLASSES.length)

/** From "every class", a click picks just that class; after that, clicks add and remove */
function toggle(c: string) {
  if (everyone.value) {
    model.value = [c]
    return
  }
  const next = model.value.includes(c) ? model.value.filter((x) => x !== c) : [...model.value, c]
  model.value = next.length === CLASSES.length ? [] : CLASSES.filter((x) => next.includes(x))
}
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="chip border px-3 py-1.5 text-sm"
        :class="everyone ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-line bg-surface text-gray-700 hover:border-indigo-300'"
        @click="model = []"
      >
        Every class
      </button>
      <button
        v-for="c in CLASSES"
        :key="c"
        type="button"
        class="chip border px-3 py-1.5 text-sm"
        :class="!everyone && model.includes(c) ? 'border-indigo-700 bg-indigo-50 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200' : 'border-line bg-surface text-gray-700 hover:border-indigo-300'"
        :aria-pressed="!everyone && model.includes(c)"
        @click="toggle(c)"
      >
        {{ c }}
      </button>
    </div>
    <p class="mt-1.5 text-xs text-gray-500">{{ everyone ? 'All students can vote.' : `Only ${model.join(', ')} can vote.` }}</p>
  </div>
</template>
