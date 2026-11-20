<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue'
import { useSession } from '@/stores/session'

/**
 * The school's letterhead on printed pages (slips lists, results, teacher reports): the crest,
 * the school's name and motto, and what the page is. Shows only when printing unless `always`.
 */
withDefaults(defineProps<{ title: string; subtitle?: string; always?: boolean }>(), { always: false })
const session = useSession()
</script>

<template>
  <header class="force-light mb-5 items-center gap-4 border-b-4 border-double border-indigo-800 pb-3 text-[rgb(24,24,38)]" :class="always ? 'flex' : 'hidden print:flex'">
    <BrandMark :size="64" />
    <div class="min-w-0 flex-1">
      <p class="text-xl font-semibold uppercase tracking-wide text-indigo-900">{{ session.school || "St. Mark's College Namagoma" }}</p>
      <p class="text-xs font-semibold uppercase tracking-[0.3em] text-gold-700">To be, not to seem</p>
      <p class="mt-1 text-sm text-gray-700">Student Voice · {{ title }}<template v-if="subtitle"> · {{ subtitle }}</template></p>
    </div>
  </header>
</template>
