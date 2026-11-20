<script setup lang="ts">
import { computed } from 'vue'
import type { PollStatus } from '@/types'

const props = defineProps<{ status: PollStatus; accepting?: boolean }>()

const look = computed(() => {
  if (props.status === 'open' && props.accepting === false) return { text: 'Closing time passed', cls: 'bg-gold-100 text-gold-700' }
  return {
    open: { text: 'Open', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
    draft: { text: 'Draft', cls: 'bg-gray-100 text-gray-700' },
    closed: { text: 'Closed', cls: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300' },
  }[props.status]
})
</script>

<template>
  <span class="chip" :class="look.cls">
    <span v-if="status === 'open' && accepting !== false" class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" />
    {{ look.text }}
  </span>
</template>
