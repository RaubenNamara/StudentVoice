<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { EyeSlashIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import LikertBar from '@/components/LikertBar.vue'
import Meter from '@/components/Meter.vue'
import PrintHeader from '@/components/PrintHeader.vue'
import { api } from '@/api'
import { formatDate, percent } from '@/feedback'
import { fmt, scoreCell, scoreText } from '@/scores'
import { SCALE, type Poll, type Question } from '@/types'
import { useSession } from '@/stores/session'

interface Summary {
  question: Question & { id: number }
  answered: number
  counts?: number[]
  average?: number | null
  agree_share?: number | null
  school_average?: number | null
  school_counts?: number[]
  texts?: string[]
}
interface Report {
  poll: Poll
  teacher: { id: number; name: string; employee_number: string | null }
  subject: { id: number; name: string }
  ratings: number
  expected: number
  not_my_teacher: number
  min_group: number
  hidden: boolean
  overall?: number | null
  school_overall?: number | null
  sections?: { section: string; average: number | null; school_average: number | null }[]
  scale?: Summary[]
  others?: Summary[]
  groups?: { class: string; stream: string; ratings: number; average: number | null; enough: boolean }[]
  trend?: { poll_id: number; title: string; opened_at: string | null; ratings: number; overall: number }[]
}

const route = useRoute()
const session = useSession()
const r = ref<Report | null>(null)
onMounted(async () => (r.value = await api.get<Report>(`/admin/polls/${route.params.id}/teachers/${route.params.teacher}/${route.params.subject}`)))

const add = (a: number[], b: number[] = []) => a.map((n, i) => n + (b[i] ?? 0))
const zero = () => [0, 0, 0, 0, 0]
const pct = (counts: number[], i: number) => {
  const t = counts.reduce((s, n) => s + n, 0)
  return t ? Math.round((counts[i] / t) * 100) : 0
}
const agree = (counts: number[]) => {
  const t = counts.reduce((s, n) => s + n, 0)
  return t ? Math.round(((counts[3] + counts[4]) / t) * 100) : 0
}

/** All statements together: this teacher and the whole school */
const overallCounts = computed(() => (r.value?.scale ?? []).reduce((acc, s) => add(acc, s.counts), zero()))
const schoolCounts = computed(() => (r.value?.scale ?? []).reduce((acc, s) => add(acc, s.school_counts), zero()))

/** Statements grouped by section, each section with its combined spread */
const sections = computed(() => {
  const out: { name: string; rows: (Summary & { n: number })[]; counts: number[]; school: number[]; average: number | null; schoolAverage: number | null }[] = []
  ;(r.value?.scale ?? []).forEach((s, i) => {
    const name = s.question.section || 'Statements'
    let g = out.find((x) => x.name === name)
    if (!g) {
      const sec = r.value?.sections?.find((x) => (x.section || 'Statements') === name)
      g = { name, rows: [], counts: zero(), school: zero(), average: sec?.average ?? null, schoolAverage: sec?.school_average ?? null }
      out.push(g)
    }
    g.rows.push({ ...s, n: i + 1 })
    g.counts = add(g.counts, s.counts)
    g.school = add(g.school, s.school_counts)
  })
  return out
})

const ranked = computed(() => [...(r.value?.scale ?? [])].filter((s) => s.average != null).sort((a, b) => b.average! - a.average!))
const strongest = computed(() => ranked.value.slice(0, 3))
const weakest = computed(() => ranked.value.slice(-3).reverse())
const diff = (a?: number | null, b?: number | null) => (a == null || b == null ? null : Math.round((a - b) * 100) / 100)
const signed = (v: number | null) => (v == null ? '' : v > 0 ? `+${v.toFixed(2)}` : v.toFixed(2))
const diffTone = (v: number | null) => (v == null ? 'text-gray-400' : v >= 0.1 ? 'text-indigo-700 dark:text-indigo-300' : v <= -0.1 ? 'text-red-700 dark:text-red-400' : 'text-gray-500')
/** Why a statement is weak: the share who disagree, or (if nobody did) who were not sure */
const weakNote = (counts: number[]) => {
  const d = pct(counts, 0) + pct(counts, 1)
  return d ? `${d}% disagree` : `${pct(counts, 2)}% not sure, ${agree(counts)}% agree`
}
const SWATCH = ['bg-lk-1', 'bg-lk-2', 'bg-lk-3', 'bg-lk-4', 'bg-lk-5']
const SHORT = ['SD', 'D', 'NS', 'A', 'SA']
const print = () => window.print()
</script>

<template>
  <div v-if="!r" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-64 animate-pulse" />
  </div>

  <article v-else class="report mx-auto max-w-5xl">
    <div class="no-print mb-4 flex items-center justify-between gap-3">
      <RouterLink :to="{ name: 'teacher-results', params: { id: r.poll.id } }" class="text-sm text-gray-500 hover:text-ink">← All teachers</RouterLink>
      <button class="btn-primary" @click="print"><PrinterIcon class="h-4 w-4" /> Print report</button>
    </div>

    <PrintHeader title="Teacher report · confidential" :subtitle="r.poll.title" />

    <!-- 1. Who, and the headline numbers -->
    <header class="card break-inside-avoid p-6">
      <p class="eyebrow">Teacher report · confidential, for the administration</p>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">{{ r.teacher.name }}</h1>
          <p class="mt-1 text-gray-600">{{ r.subject.name }} · {{ r.poll.title }}<template v-if="r.teacher.employee_number"> · {{ r.teacher.employee_number }}</template></p>
        </div>
      </div>
      <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl p-3" :class="r.hidden ? 'bg-gray-50' : scoreCell(r.overall)">
          <dt class="text-xs font-medium opacity-80">Overall score</dt>
          <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ r.hidden ? '–' : fmt(r.overall) }}<span class="text-sm font-normal opacity-70"> / 5</span></dd>
          <dd v-if="!r.hidden" class="text-xs opacity-80">school {{ fmt(r.school_overall) }} ({{ signed(diff(r.overall, r.school_overall)) }})</dd>
        </div>
        <div class="rounded-xl bg-gray-50 p-3">
          <dt class="text-xs font-medium text-gray-500">Agree or strongly agree</dt>
          <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ r.hidden ? '–' : agree(overallCounts) + '%' }}</dd>
          <dd v-if="!r.hidden" class="text-xs text-gray-500">school {{ agree(schoolCounts) }}%</dd>
        </div>
        <div class="rounded-xl bg-gray-50 p-3">
          <dt class="text-xs font-medium text-gray-500">Students who rated</dt>
          <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ r.ratings }}</dd>
          <dd class="text-xs text-gray-500">{{ r.expected ? `of ${r.expected} listed (${percent(r.ratings, r.expected)}%)` : 'students chose this teacher' }}</dd>
        </div>
        <div class="rounded-xl bg-gray-50 p-3">
          <dt class="text-xs font-medium text-gray-500">"Not my teacher"</dt>
          <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ r.not_my_teacher }}</dd>
          <dd class="text-xs text-gray-500">students who said so</dd>
        </div>
      </dl>
    </header>

    <div v-if="r.hidden" class="card mt-6 flex items-start gap-3 p-6 text-sm text-gray-600">
      <EyeSlashIcon class="h-5 w-5 shrink-0" />
      <p>Only {{ r.ratings }} students have rated this teacher. Results are shown from {{ r.min_group }} ratings, so nobody can work out what a particular student said.</p>
    </div>

    <template v-else>
      <!-- Legend, used by every chart below -->
      <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-gray-600" aria-hidden="true">
        <span class="font-semibold text-ink">How students answered:</span>
        <span v-for="(s, i) in SCALE" :key="s.value" class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" :class="SWATCH[i]" />{{ s.label }}</span>
      </div>

      <!-- 2. The overall picture -->
      <section class="card mt-3 break-inside-avoid p-6">
        <h2 class="font-semibold">Overall picture <span class="font-normal text-gray-500">· all {{ r.scale?.length }} statements together</span></h2>
        <div class="mt-4 space-y-3">
          <div class="grid grid-cols-[7rem_1fr] items-center gap-3">
            <span class="text-sm font-medium">This teacher</span>
            <LikertBar :counts="overallCounts" tall />
          </div>
          <div class="grid grid-cols-[7rem_1fr] items-center gap-3">
            <span class="text-sm text-gray-500">Whole school</span>
            <LikertBar :counts="schoolCounts" tall />
          </div>
        </div>
      </section>

      <!-- 3. By area -->
      <section v-if="sections.length > 1" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">By area</h2>
        <table class="mt-3 w-full text-sm">
          <thead class="text-left text-xs uppercase tracking-wider text-gray-500">
            <tr><th class="py-2 pr-3 font-semibold">Area</th><th class="w-[45%] py-2 pr-3 font-semibold">Answers</th><th class="py-2 pr-2 text-right font-semibold">Agree</th><th class="py-2 pr-2 text-right font-semibold">Score</th><th class="py-2 pr-2 text-right font-semibold">School</th><th class="py-2 text-right font-semibold">+/-</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="s in sections" :key="s.name">
              <td class="py-2.5 pr-3 font-medium">{{ s.name }}</td>
              <td class="py-2.5 pr-3"><LikertBar :counts="s.counts" /></td>
              <td class="py-2.5 pr-2 text-right tabular-nums">{{ agree(s.counts) }}%</td>
              <td class="py-2.5 pr-2 text-right font-semibold tabular-nums" :class="scoreText(s.average)">{{ fmt(s.average) }}</td>
              <td class="py-2.5 pr-2 text-right tabular-nums text-gray-500">{{ fmt(s.schoolAverage) }}</td>
              <td class="py-2.5 text-right font-semibold tabular-nums" :class="diffTone(diff(s.average, s.schoolAverage))">{{ signed(diff(s.average, s.schoolAverage)) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- 4. Strongest / to work on -->
      <div v-if="ranked.length >= 4" class="mt-6 grid gap-6 sm:grid-cols-2 print:grid-cols-2">
        <section class="card break-inside-avoid p-5">
          <h2 class="font-semibold text-indigo-800 dark:text-indigo-300">Strongest</h2>
          <ul class="mt-3 space-y-3">
            <li v-for="s in strongest" :key="s.question.id" class="text-sm">
              <div class="flex items-start justify-between gap-3"><span>{{ s.question.prompt }}</span><span class="shrink-0 rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(s.average)">{{ fmt(s.average) }}</span></div>
              <p class="mt-0.5 text-xs text-gray-500">{{ s.agree_share }}% agree</p>
            </li>
          </ul>
        </section>
        <section class="card break-inside-avoid p-5">
          <h2 class="font-semibold text-red-700 dark:text-red-400">To work on</h2>
          <ul class="mt-3 space-y-3">
            <li v-for="s in weakest" :key="s.question.id" class="text-sm">
              <div class="flex items-start justify-between gap-3"><span>{{ s.question.prompt }}</span><span class="shrink-0 rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(s.average)">{{ fmt(s.average) }}</span></div>
              <p class="mt-0.5 text-xs text-gray-500">{{ weakNote(s.counts ?? []) }}</p>
            </li>
          </ul>
        </section>
      </div>

      <!-- 5. Statement by statement -->
      <section v-if="r.scale?.length" class="card mt-6 p-6">
        <h2 class="font-semibold">Statement by statement</h2>
        <p class="mt-1 text-xs text-gray-500">Percent of students who gave each answer · SD strongly disagree · D disagree · NS not sure · A agree · SA strongly agree · score out of 5</p>
        <div class="mt-3 overflow-x-auto">
          <table class="w-full min-w-[46rem] text-sm print:min-w-0 print:text-[11px]">
            <thead class="text-left text-xs uppercase tracking-wider text-gray-500">
              <tr>
                <th class="py-2 pr-3 font-semibold">Statement</th>
                <th class="w-[24%] py-2 pr-3 font-semibold print:w-[22%]">Answers</th>
                <th v-for="(s, i) in SHORT" :key="s" class="w-9 py-2 text-right font-semibold"><span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-sm" :class="SWATCH[i]" />{{ s }}</span></th>
                <th class="w-12 py-2 pl-2 text-right font-semibold">n</th>
                <th class="w-14 py-2 pl-2 text-right font-semibold">Score</th>
                <th class="w-14 py-2 pl-2 text-right font-semibold">School</th>
              </tr>
            </thead>
            <tbody v-for="g in sections" :key="g.name">
              <tr v-if="sections.length > 1" class="break-inside-avoid"><td colspan="10" class="pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gold-700">{{ g.name }}</td></tr>
              <tr v-for="s in g.rows" :key="s.question.id" class="break-inside-avoid border-t border-line">
                <td class="py-2 pr-3"><span class="mr-1 text-gray-400">{{ s.n }}.</span>{{ s.question.prompt }}</td>
                <td class="py-2 pr-3"><LikertBar :counts="s.counts ?? []" /></td>
                <td v-for="(_x, i) in SHORT" :key="i" class="py-2 text-right tabular-nums" :class="i === 4 || i === 0 ? 'font-semibold' : 'text-gray-600'">{{ pct(s.counts ?? [], i) }}%</td>
                <td class="py-2 pl-2 text-right tabular-nums text-gray-500">{{ s.answered }}</td>
                <td class="py-2 pl-2 text-right font-semibold tabular-nums" :class="scoreText(s.average)">{{ fmt(s.average) }}</td>
                <td class="py-2 pl-2 text-right tabular-nums text-gray-500">{{ fmt(s.school_average) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 6. Classes -->
      <section v-if="r.groups?.length" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">By class and stream</h2>
        <div class="mt-3 grid gap-2 sm:grid-cols-2 print:grid-cols-2">
          <div v-for="g in r.groups" :key="g.class + g.stream" class="flex items-center justify-between rounded-lg border border-line px-3 py-2 text-sm">
            <span>{{ g.class }} {{ g.stream }} <span class="text-gray-500">· {{ g.ratings }} ratings</span></span>
            <span v-if="g.enough" class="rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(g.average)">{{ fmt(g.average) }}</span>
            <span v-else class="text-xs text-gray-500">fewer than {{ r.min_group }} - not shown</span>
          </div>
        </div>
      </section>

      <!-- 7. Over time -->
      <section v-if="r.trend && r.trend.length > 1" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">Over time <span class="font-normal text-gray-500">· overall score in each teacher poll</span></h2>
        <div class="mt-4 flex h-40 items-end gap-3 border-b border-line">
          <div v-for="t in r.trend" :key="t.poll_id" class="flex flex-1 flex-col items-center gap-1" :title="`${t.title}: ${t.overall.toFixed(2)} (${t.ratings} ratings)`">
            <span class="text-xs font-semibold tabular-nums">{{ t.overall.toFixed(2) }}</span>
            <div class="relative h-28 w-full max-w-[3.5rem]"><div class="absolute inset-x-0 bottom-0 rounded-t" :class="t.poll_id === r.poll.id ? 'bg-gold-400' : 'bg-indigo-600'" :style="{ height: `${(t.overall / 5) * 100}%` }" /></div>
          </div>
        </div>
        <div class="mt-1 flex gap-3">
          <span v-for="t in r.trend" :key="t.poll_id" class="line-clamp-2 flex-1 text-center text-[0.68rem] text-gray-500">{{ t.title }}</span>
        </div>
      </section>

      <!-- 8. Other questions and students' comments -->
      <section v-for="o in r.others" :key="o.question.id" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">{{ o.question.prompt }}</h2>
        <template v-if="o.texts">
          <p v-if="!o.texts.length" class="mt-2 text-sm text-gray-500">No written answers.</p>
          <ul v-else class="mt-3 grid gap-2 sm:grid-cols-2 print:grid-cols-2">
            <li v-for="(t, k) in o.texts" :key="k" class="break-inside-avoid whitespace-pre-line rounded-lg border-l-4 border-crest-300 bg-gray-50 px-3 py-2 text-sm">“{{ t }}”</li>
          </ul>
        </template>
        <ul v-else-if="o.counts" class="mt-3 space-y-2.5">
          <li v-for="(c, k) in o.counts" :key="k">
            <div class="mb-1 flex justify-between text-sm"><span>{{ o.question.type === 'yesno' ? (k === 0 ? 'Yes' : 'No') : o.question.options[k] }}</span><span class="tabular-nums text-gray-600">{{ c }} · {{ percent(c, o.answered) }}%</span></div>
            <Meter :value="c" :max="o.answered" />
          </li>
        </ul>
      </section>

      <section class="mt-6 break-inside-avoid rounded-xl border border-dashed border-line p-4 text-xs leading-relaxed text-gray-600">
        <strong class="text-ink">Reading this report.</strong> Scores are averages from 1 (strongly disagree) to 5 (strongly agree): 3.5 and above is good (blue), 3 to 3.5 needs attention (amber), below 3 is a concern (red). "School" compares with every teacher rated in this poll. Each student answered a mix of the statements, so the number answering ("n") differs. Answers are anonymous, and groups smaller than {{ r.min_group }} are never shown.
      </section>
    </template>

    <p class="mt-6 text-xs text-gray-500">{{ session.school }} · Student Voice · printed {{ formatDate(new Date().toISOString()) }}</p>
  </article>
</template>
