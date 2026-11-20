<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, ChevronUpDownIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import StatusChip from '@/components/StatusChip.vue'
import { api } from '@/api'
import { percent } from '@/feedback'
import { downloadCsv, fmt, scoreCell } from '@/scores'
import type { Poll } from '@/types'

interface Row {
  teacher_id: number
  teacher: string
  subject_id: number
  subject: string
  ratings: number
  expected: number
  not_my_teacher: number
  enough: boolean
  overall: number | null
  sections: (number | null)[]
  change: number | null
}
interface Ranking {
  poll: Poll
  sections: string[]
  school: { overall: number | null; sections: (number | null)[] }
  previous: { id: number; title: string } | null
  min_group: number
  has_scale: boolean
  rows: Row[]
}

const route = useRoute()
const data = ref<Ranking | null>(null)
const q = ref('')
const subject = ref('')
const groupBy = ref<'none' | 'subject'>('none')
const sortKey = ref<string>('overall')
const sortDir = ref<1 | -1>(-1)

onMounted(async () => (data.value = await api.get<Ranking>(`/admin/polls/${route.params.id}/teachers`)))

const subjects = computed(() => [...new Set(data.value?.rows.map((r) => r.subject) ?? [])].sort())
const value = (r: Row, key: string): number | string => {
  if (key === 'teacher') return r.teacher
  if (key === 'subject') return r.subject
  if (key === 'ratings') return r.ratings
  if (key === 'skips') return r.not_my_teacher
  if (key === 'change') return r.change ?? -99
  if (key.startsWith('s')) return r.sections[Number(key.slice(1))] ?? -1
  return r.overall ?? -1
}
const rows = computed(() => {
  const term = q.value.trim().toLowerCase()
  const list = (data.value?.rows ?? []).filter((r) => (!subject.value || r.subject === subject.value) && (!term || r.teacher.toLowerCase().includes(term)))
  return [...list].sort((a, b) => {
    const x = value(a, sortKey.value)
    const y = value(b, sortKey.value)
    return (x < y ? -1 : x > y ? 1 : 0) * sortDir.value || a.teacher.localeCompare(b.teacher)
  })
})
/** Subject groups with a ratings-weighted average of their teachers' overall scores */
const groups = computed(() => {
  if (groupBy.value !== 'subject') return [{ name: '', rows: rows.value, average: null as number | null }]
  const map = new Map<string, Row[]>()
  for (const r of rows.value) map.set(r.subject, [...(map.get(r.subject) ?? []), r])
  return [...map.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([name, list]) => {
      const scored = list.filter((r) => r.overall != null)
      const w = scored.reduce((s, r) => s + r.ratings, 0)
      return { name, rows: list, average: w ? Math.round((scored.reduce((s, r) => s + r.overall! * r.ratings, 0) / w) * 100) / 100 : null }
    })
})

function sortBy(key: string) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 1 ? -1 : 1
  else {
    sortKey.value = key
    sortDir.value = key === 'teacher' || key === 'subject' ? 1 : -1
  }
}
/** Many "not my teacher" answers: the Teaching list is probably wrong for this teacher */
const doubtful = (r: Row) => r.not_my_teacher >= 3 && r.not_my_teacher > 0.2 * (r.ratings + r.not_my_teacher)
const totals = computed(() => ({
  ratings: data.value?.rows.reduce((s, r) => s + r.ratings, 0) ?? 0,
  scored: data.value?.rows.filter((r) => r.enough).length ?? 0,
}))

function exportCsv() {
  if (!data.value) return
  const d = data.value
  downloadCsv(`${d.poll.title} - teachers`, [
    ['Teacher', 'Subject', 'Ratings', 'Students expected', 'Not my teacher', 'Overall', ...d.sections.map((s) => s || 'Questions'), d.previous ? `Change since ${d.previous.title}` : 'Change'],
    ...rows.value.map((r) => [r.teacher, r.subject, r.ratings, r.expected, r.not_my_teacher, r.overall, ...d.sections.map((_, i) => r.sections[i] ?? null), r.change]),
    ['School', '', totals.value.ratings, '', '', d.school.overall, ...d.school.sections],
  ])
}
const print = () => window.print()
</script>

<template>
  <div v-if="!data" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-64 animate-pulse" />
  </div>

  <template v-else>
    <PageHeader :title="data.poll.title" subtitle="Teacher results · for the administration only">
      <template #crumb>
        <RouterLink :to="{ name: 'poll', params: { id: data.poll.id } }" class="no-print text-sm text-gray-500 hover:text-ink">← Poll</RouterLink>
        <div class="mb-1 mt-2"><StatusChip :status="data.poll.status" :accepting="data.poll.accepting" /></div>
      </template>
      <RouterLink :to="{ name: 'results', params: { id: data.poll.id } }" class="btn-secondary no-print">Whole school</RouterLink>
      <button class="btn-secondary no-print" @click="exportCsv"><ArrowDownTrayIcon class="h-4 w-4" /> Spreadsheet</button>
      <button class="btn-secondary no-print" @click="print"><PrinterIcon class="h-4 w-4" /> Print</button>
    </PageHeader>

    <!-- School line -->
    <section class="card mb-6 grid gap-4 p-5 sm:grid-cols-[auto_1fr]">
      <div class="sm:border-r sm:border-line sm:pr-6">
        <p class="eyebrow">School average</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums">{{ fmt(data.school.overall) }}<span class="text-base font-normal text-gray-500"> / 5</span></p>
        <p class="mt-1 text-xs text-gray-500">{{ totals.ratings.toLocaleString() }} ratings · {{ totals.scored }} of {{ data.rows.length }} teachers with enough to score</p>
      </div>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="(s, i) in data.sections" :key="s" class="rounded-xl p-3" :class="scoreCell(data.school.sections[i])">
          <p class="text-xs font-medium opacity-80">{{ s || 'Questions' }}</p>
          <p class="mt-1 text-xl font-semibold tabular-nums">{{ fmt(data.school.sections[i]) }}</p>
        </div>
      </div>
    </section>

    <p v-if="!data.has_scale" class="card mb-6 p-4 text-sm text-gray-600">This poll has no agree/disagree (1-5) questions, so there are no scores; open a teacher to see their answers.</p>

    <!-- Controls -->
    <div class="no-print mb-3 flex flex-wrap items-end gap-2">
      <div class="relative min-w-[13rem] flex-1">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
        <input v-model="q" class="input pl-9" placeholder="Find a teacher" />
      </div>
      <select v-model="subject" class="input w-40"><option value="">All subjects</option><option v-for="s in subjects" :key="s">{{ s }}</option></select>
      <div class="flex rounded-lg border border-line bg-surface p-0.5 text-sm" role="radiogroup" aria-label="Group">
        <button role="radio" :aria-checked="groupBy === 'none'" class="rounded-md px-3 py-1.5" :class="groupBy === 'none' ? 'bg-indigo-700 text-white' : 'text-gray-600'" @click="groupBy = 'none'">Ranking</button>
        <button role="radio" :aria-checked="groupBy === 'subject'" class="rounded-md px-3 py-1.5" :class="groupBy === 'subject' ? 'bg-indigo-700 text-white' : 'text-gray-600'" @click="groupBy = 'subject'">By subject</button>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-max text-sm">
          <thead class="table-head">
            <tr>
              <th class="w-8 px-3 py-2.5">#</th>
              <th v-for="h in [{ k: 'teacher', t: 'Teacher' }, { k: 'subject', t: 'Subject' }, { k: 'ratings', t: 'Ratings' }, { k: 'overall', t: 'Overall' }]" :key="h.k" class="px-3 py-2.5" :class="h.k === 'ratings' || h.k === 'overall' ? 'text-center' : ''">
                <button class="inline-flex items-center gap-1 uppercase hover:text-ink" @click="sortBy(h.k)">{{ h.t }}<ChevronUpDownIcon class="h-3.5 w-3.5" :class="sortKey === h.k ? 'text-indigo-700' : ''" /></button>
              </th>
              <th v-for="(s, i) in data.sections" :key="s" class="max-w-[8rem] px-2 py-2.5 text-center">
                <button class="inline-flex items-center gap-1 whitespace-normal text-left uppercase leading-tight hover:text-ink" :title="s" @click="sortBy(`s${i}`)">{{ s ? s.split(' ')[0].replace(/&|,/g, '') : 'Questions' }}<ChevronUpDownIcon class="h-3.5 w-3.5 shrink-0" :class="sortKey === `s${i}` ? 'text-indigo-700' : ''" /></button>
              </th>
              <th class="px-3 py-2.5 text-center"><button class="inline-flex items-center gap-1 uppercase hover:text-ink" :title="data.previous ? `Since ${data.previous.title}` : 'No earlier teacher poll'" @click="sortBy('change')">Change<ChevronUpDownIcon class="h-3.5 w-3.5" /></button></th>
              <th class="px-3 py-2.5 text-center"><button class="inline-flex items-center gap-1 uppercase hover:text-ink" title="Students who said this is not their teacher" @click="sortBy('skips')">Not mine<ChevronUpDownIcon class="h-3.5 w-3.5" /></button></th>
            </tr>
          </thead>
          <tbody v-for="g in groups" :key="g.name" class="divide-y divide-line border-b border-line">
            <tr v-if="g.name" class="bg-gray-50">
              <td colspan="3" class="px-3 py-2 font-semibold">{{ g.name }} <span class="font-normal text-gray-500">· {{ g.rows.length }} teacher{{ g.rows.length === 1 ? '' : 's' }}</span></td>
              <td />
              <td class="px-3 py-2 text-center text-xs text-gray-500">average</td>
              <td colspan="99" class="px-3 py-2"><span class="rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(g.average)">{{ fmt(g.average) }}</span></td>
            </tr>
            <tr v-for="(r, i) in g.rows" :key="`${r.teacher_id}-${r.subject_id}`" class="hover:bg-gray-50">
              <td class="px-3 py-2 text-gray-400">{{ i + 1 }}</td>
              <td class="px-3 py-2">
                <RouterLink :to="{ name: 'teacher-report', params: { id: data.poll.id, teacher: r.teacher_id, subject: r.subject_id } }" class="font-medium text-indigo-800 hover:underline dark:text-indigo-300">{{ r.teacher }}</RouterLink>
              </td>
              <td class="px-3 py-2 text-gray-600">{{ r.subject }}</td>
              <td class="px-3 py-2 text-center tabular-nums">
                {{ r.ratings }}<span class="text-gray-400"> / {{ r.expected }}</span>
                <div class="text-[0.68rem] text-gray-400">{{ percent(r.ratings, r.expected) }}%</div>
              </td>
              <template v-if="r.enough">
                <td class="px-2 py-1.5 text-center"><span class="inline-block min-w-[3.2rem] rounded-md px-2 py-1 font-semibold tabular-nums" :class="scoreCell(r.overall)">{{ fmt(r.overall) }}</span></td>
                <td v-for="(s, k) in data.sections" :key="s" class="px-2 py-1.5 text-center"><span class="inline-block min-w-[3rem] rounded-md px-1.5 py-1 tabular-nums" :class="scoreCell(r.sections[k])">{{ fmt(r.sections[k]) }}</span></td>
                <td class="px-3 py-2 text-center tabular-nums" :class="r.change == null ? 'text-gray-400' : r.change > 0 ? 'text-indigo-700 dark:text-indigo-300' : r.change < 0 ? 'text-red-700 dark:text-red-400' : ''">
                  {{ r.change == null ? '–' : (r.change > 0 ? '▲ +' : r.change < 0 ? '▼ ' : '') + r.change.toFixed(2) }}
                </td>
              </template>
              <td v-else :colspan="data.sections.length + 2" class="px-3 py-2 text-center text-xs text-gray-500">Fewer than {{ data.min_group }} ratings - not scored</td>
              <td class="px-3 py-2 text-center tabular-nums">
                <span v-if="doubtful(r)" class="inline-flex items-center gap-1 font-semibold text-gold-700" title="Many students say this is not their teacher: check the Teaching list"><ExclamationTriangleIcon class="h-4 w-4" />{{ r.not_my_teacher }}</span>
                <span v-else :class="r.not_my_teacher ? '' : 'text-gray-400'">{{ r.not_my_teacher }}</span>
              </td>
            </tr>
          </tbody>
          <tbody v-if="!rows.length"><tr><td colspan="99" class="px-4 py-10 text-center text-gray-500">No teachers match.</td></tr></tbody>
        </table>
      </div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
      Scores are averages of the agree/disagree questions (5 = strongly agree). Colours: red below 3, amber 3-3.5, blue 3.5 and above.
      Ratings / expected compares answers with students listed for that teacher today. Teachers have no access to these results.
    </p>
  </template>
</template>
