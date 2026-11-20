<script setup lang="ts">
import { toasts } from '@/feedback'
</script>

<template>
  <div class="no-print pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4" aria-live="polite">
    <TransitionGroup
      enter-active-class="transition duration-200"
      enter-from-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-150"
      leave-to-class="opacity-0"
    >
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto max-w-md rounded-xl px-4 py-2.5 text-sm font-medium shadow-lift"
        :class="t.kind === 'error' ? 'bg-red-700 text-white' : 'bg-indigo-950 text-white'"
      >
        {{ t.text }}
      </div>
    </TransitionGroup>
  </div>
</template>
