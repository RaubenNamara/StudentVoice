<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ArrowTrendingDownIcon, ArrowTrendingUpIcon } from '@heroicons/vue/24/outline'
import LikertBar from '@/components/LikertBar.vue'
import { api } from '@/api'
import { SCALE } from '@/types'

/**
 * Best and lowest performers, for all statements or one statement:
 * - top 5: the largest share of "Strongly agree" (ties: more agree overall, then higher score)
 * - bottom 5: the largest share who disagree (strongly disagree + disagree; ties: lower score)
 * A teacher counts for a statement only with at least min_group answers to it.
 */
interface Leaders {
  questions: { id: number; prompt: string; section: string | null }[]
  teachers: { teacher_id: number; teacher: string; subject_id: number; subject: string; ratings: number; counts: Record<string, number[]> }[]
  min_group: number
}
interface Row {
  key: string
  teacher_id: number
  subject_id: number
  teacher: string
  subject: string
  counts: number[]
  n: number
  sa: number
  agree: number
  disagree: number
  score: number
}

const props = defineProps<{ pollId: number }>()
const data = ref<Leaders | null>(null)
const question = ref<'all' | number>('all')
onMounted(async () => (data.value = await api.get<Leaders>(`/admin/polls/${props.pollId}/teachers/leaders`)))

const rows = computed<Row[]>(() => {
  if (!data.value) return []
  const min = data.value.min_group
  return data.value.teachers
    .map((t) => {
      const counts = question.value === 'all'
        ? Object.values(t.counts).reduce((acc, c) => acc.map((v, i) => v + (c[i] ?? 0)), [0, 0, 0, 0, 0])
        : (t.counts[String(question.value)] ?? [0, 0, 0, 0, 0])
      const n = counts.reduce((s, v) => s + v, 0)
      return {
        key: `${t.teacher_id}-${t.subject_id}`,
        teacher_id: t.teacher_id,
        subject_id: t.subject_id,
        teacher: t.teacher,
        subject: t.subject,
        counts,
        n,
        sa: n ? (counts[4] / n) * 100 : 0,
        agree: n ? ((counts[3] + counts[4]) / n) * 100 : 0,
        disagree: n ? ((counts[0] + counts[1]) / n) * 100 : 0,
        score: n ? counts.reduce((s, v, i) => s + v * (i + 1), 0) / n : 0,
      }
    })
    .filter((r) => r.n >= min)
})
// top: only teachers some students strongly agree about
const top = computed(() => rows.value.filter((r) => r.sa > 0).sort((a, b) => b.sa - a.sa || b.agree - a.agree || b.score - a.score).slice(0, 5))
// bottom: never someone already in the top; those students disagree about, else the lowest scores
const rest = computed(() => rows.value.filter((r) => !top.value.some((t) => t.key === r.key)))
const noDisagreement = computed(() => rest.value.length > 0 && !rest.value.some((r) => r.disagree > 0))
const bottom = computed(() =>
  noDisagreement.value
    ? [...rest.value].sort((a, b) => a.score - b.score).slice(0, 5)
    : rest.value.filter((r) => r.disagree > 0).sort((a, b) => b.disagree - a.disagree || a.score - b.score).slice(0, 5),
)
const prompt = computed(() => (question.value === 'all' ? 'All statements together' : data.value?.questions.find((q) => q.id === question.value)?.prompt ?? ''))
const tip = (r: Row) => `${r.teacher} (${r.subject}) · ${r.n} answers · ` + SCALE.map((s, i) => `${s.label} ${Math.round((r.counts[i] / (r.n || 1)) * 100)}%`).join(', ') + ` · score ${r.score.toFixed(2)}`
const r0 = (v: number) => Math.round(v)
</script>

<template>
  <section class="card mb-6 p-5 break-inside-avoid">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="font-semibold">Best and lowest performers</h2>
        <p class="text-sm text-gray-500">Top 5 by strongly agree, bottom 5 by disagree · teachers with at least {{ data?.min_group ?? 5 }} answers</p>
      </div>
      <label class="no-print w-full sm:w-96">
        <span class="mb-1 block text-xs font-semibold text-gray-500">Question</span>
        <select v-model="question" class="input">
          <option value="all">All statements together</option>
          <template v-for="(q, i) in data?.questions ?? []" :key="q.id">
            <option :value="q.id">{{ i + 1 }}. {{ q.prompt }}</option>
          </template>
        </select>
      </label>
    </div>
    <p class="mt-2 hidden text-sm font-medium print:block">{{ prompt }}</p>

    <div v-if="!data" class="mt-4 grid gap-4 md:grid-cols-2"><div v-for="i in 2" :key="i" class="h-56 animate-pulse rounded-xl bg-gray-100" /></div>
    <p v-else-if="!rows.length" class="mt-6 rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-500">No teacher has {{ data.min_group }} or more answers to this question yet.</p>

    <div v-else class="mt-5 grid gap-6 md:grid-cols-2 print:grid-cols-2">
      <!-- Top 5 -->
      <div>
        <h3 class="flex items-center gap-2 text-sm font-semibold text-indigo-800 dark:text-indigo-300"><ArrowTrendingUpIcon class="h-5 w-5" /> Top 5 · most "Strongly agree"</h3>
        <p v-if="!top.length" class="mt-3 rounded-lg bg-gray-50 p-4 text-sm text-gray-500">No teacher got "Strongly agree" here yet.</p>
        <ol class="mt-3 space-y-3">
          <li v-for="(r, i) in top" :key="r.key" :title="tip(r)">
            <RouterLink :to="{ name: 'teacher-report', params: { id: pollId, teacher: r.teacher_id, subject: r.subject_id } }" class="group block">
              <div class="flex items-baseline justify-between gap-2 text-sm">
                <span class="min-w-0 truncate"><span class="mr-1.5 inline-block w-4 text-right font-semibold text-gray-400">{{ i + 1 }}</span><span class="font-medium group-hover:underline">{{ r.teacher }}</span> <span class="text-xs text-gray-500">{{ r.subject }}</span></span>
                <span class="shrink-0 font-semibold tabular-nums">{{ r0(r.sa) }}%</span>
              </div>
              <div class="mt-1 ml-[1.375rem] h-3 rounded-full bg-gray-100">
                <div class="h-3 rounded-full bg-lk-5" :style="{ width: `${r.sa}%` }" />
              </div>
              <div class="mt-1 ml-[1.375rem]"><LikertBar :counts="r.counts" thin /></div>
            </RouterLink>
          </li>
        </ol>
      </div>

      <!-- Bottom 5 -->
      <div>
        <h3 class="flex items-center gap-2 text-sm font-semibold text-red-700 dark:text-red-400"><ArrowTrendingDownIcon class="h-5 w-5" /> Bottom 5 · most disagree</h3>
        <p v-if="noDisagreement" class="mt-1 text-xs text-gray-500">Nobody disagreed on this; these are the lowest scores.</p>
        <p v-else-if="!bottom.length" class="mt-3 rounded-lg bg-gray-50 p-4 text-sm text-gray-500">No other teacher to show.</p>
        <ol class="mt-3 space-y-3">
          <li v-for="(r, i) in bottom" :key="r.key" :title="tip(r)">
            <RouterLink :to="{ name: 'teacher-report', params: { id: pollId, teacher: r.teacher_id, subject: r.subject_id } }" class="group block">
              <div class="flex items-baseline justify-between gap-2 text-sm">
                <span class="min-w-0 truncate"><span class="mr-1.5 inline-block w-4 text-right font-semibold text-gray-400">{{ i + 1 }}</span><span class="font-medium group-hover:underline">{{ r.teacher }}</span> <span class="text-xs text-gray-500">{{ r.subject }}</span></span>
                <span class="shrink-0 font-semibold tabular-nums">{{ noDisagreement ? r.score.toFixed(2) : `${r0(r.disagree)}%` }}</span>
              </div>
              <div class="mt-1 ml-[1.375rem] h-3 rounded-full bg-gray-100">
                <div class="h-3 rounded-full bg-lk-1" :style="{ width: `${noDisagreement ? (r.score / 5) * 100 : r.disagree}%` }" />
              </div>
              <div class="mt-1 ml-[1.375rem]"><LikertBar :counts="r.counts" thin /></div>
            </RouterLink>
          </li>
        </ol>
      </div>
    </div>
    <p v-if="rows.length" class="mt-4 text-xs text-gray-500">The thin strip under each bar is the teacher's full spread of answers, strongly disagree (red) to strongly agree (navy). Hover for the numbers; click to open the report.</p>
  </section>
</template>
