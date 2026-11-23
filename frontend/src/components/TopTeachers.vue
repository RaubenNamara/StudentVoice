<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { TrophyIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { fmt, scoreCell } from '@/scores'

/** The best-rated teachers in a teacher poll (only those with enough ratings to have a score) */
interface Row { teacher_id: number; teacher: string; subject_id: number; subject: string; ratings: number; overall: number | null }
interface Ranking { rows: Row[]; school: { overall: number | null }; min_group: number }

const props = defineProps<{ pollId: number }>()
const data = ref<Ranking | null>(null)
watch(() => props.pollId, async (id) => {
  data.value = null
  data.value = await api.get<Ranking>(`/admin/polls/${id}/teachers`)
}, { immediate: true })

const scored = computed(() => (data.value?.rows ?? []).filter((r) => r.overall != null))
const top = computed(() => [...scored.value].sort((a, b) => b.overall! - a.overall! || b.ratings - a.ratings).slice(0, 5))
const waiting = computed(() => (data.value?.rows ?? []).filter((r) => r.overall == null && r.ratings > 0).length)
</script>

<template>
  <section class="card p-5">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="flex items-center gap-2 font-semibold"><TrophyIcon class="h-5 w-5 text-gold-500" /> Best-rated teachers</h2>
        <p class="text-xs text-gray-500">Average out of 5<template v-if="data?.school.overall != null"> · school {{ fmt(data.school.overall) }}</template></p>
      </div>
      <RouterLink :to="{ name: 'teacher-results', params: { id: pollId } }" class="btn-ghost shrink-0 px-2 py-1 text-xs">Full ranking</RouterLink>
    </div>

    <div v-if="!data" class="mt-4 h-40 animate-pulse" />
    <p v-else-if="!top.length" class="py-10 text-center text-sm text-gray-500">
      No teacher has {{ data.min_group }} ratings yet, so there are no scores to show.
    </p>
    <ol v-else class="mt-4 space-y-2">
      <li v-for="(r, i) in top" :key="`${r.teacher_id}-${r.subject_id}`">
        <RouterLink :to="{ name: 'teacher-report', params: { id: pollId, teacher: r.teacher_id, subject: r.subject_id } }" class="flex items-center gap-3 rounded-xl p-2 hover:bg-gray-50">
          <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold" :class="i === 0 ? 'bg-gold-400 text-indigo-950' : 'bg-gray-100 text-gray-600'">{{ i + 1 }}</span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ r.teacher }}</span>
            <span class="block truncate text-xs text-gray-500">{{ r.subject }} · {{ r.ratings }} ratings</span>
          </span>
          <span class="rounded-lg px-2 py-1 text-sm font-semibold tabular-nums" :class="scoreCell(r.overall)">{{ fmt(r.overall) }}</span>
        </RouterLink>
      </li>
    </ol>
    <p v-if="data && waiting" class="mt-3 text-xs text-gray-400">{{ waiting }} more {{ waiting === 1 ? 'teacher has' : 'teachers have' }} ratings but fewer than {{ data.min_group }}, so no score yet (keeps students anonymous).</p>
  </section>
</template>
