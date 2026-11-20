<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { EyeSlashIcon, PrinterIcon } from '@heroicons/vue/24/outline'
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

/** Strongest and weakest statements, by their average */
const ranked = computed(() => [...(r.value?.scale ?? [])].filter((s) => s.average != null).sort((a, b) => b.average! - a.average!))
const strongest = computed(() => ranked.value.slice(0, 3))
const weakest = computed(() => ranked.value.slice(-3).reverse())
const diff = (a?: number | null, b?: number | null) => (a == null || b == null ? null : Math.round((a - b) * 100) / 100)
const signed = (v: number | null) => (v == null ? '' : v > 0 ? `+${v.toFixed(2)}` : v.toFixed(2))
const SCALE_COLOURS = ['bg-red-500', 'bg-orange-400', 'bg-gray-300', 'bg-indigo-400', 'bg-indigo-700']
const trendMax = 5
const print = () => window.print()
</script>

<template>
  <div v-if="!r" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-64 animate-pulse" />
  </div>

  <article v-else class="mx-auto max-w-4xl">
    <div class="no-print mb-4 flex items-center justify-between gap-3">
      <RouterLink :to="{ name: 'teacher-results', params: { id: r.poll.id } }" class="text-sm text-gray-500 hover:text-ink">← All teachers</RouterLink>
      <button class="btn-secondary" @click="print"><PrinterIcon class="h-4 w-4" /> Print</button>
    </div>

    <PrintHeader title="Teacher report · confidential" :subtitle="r.poll.title" />
    <!-- Heading -->
    <header class="card p-6">
      <p class="eyebrow">Teacher report · confidential, for the administration</p>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">{{ r.teacher.name }}</h1>
          <p class="mt-1 text-gray-600">{{ r.subject.name }} · {{ r.poll.title }}<template v-if="r.teacher.employee_number"> · {{ r.teacher.employee_number }}</template></p>
        </div>
        <div v-if="!r.hidden" class="text-right">
          <div class="text-4xl font-semibold tabular-nums" :class="scoreText(r.overall)">{{ fmt(r.overall) }}</div>
          <div class="text-xs text-gray-500">overall of 5 · school {{ fmt(r.school_overall) }} ({{ signed(diff(r.overall, r.school_overall)) }})</div>
        </div>
      </div>
      <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-line pt-4 text-sm">
        <div><dt class="text-gray-500">Ratings</dt><dd class="text-lg font-semibold tabular-nums">{{ r.ratings }}</dd></div>
        <div><dt class="text-gray-500">Students listed</dt><dd class="text-lg font-semibold tabular-nums">{{ r.expected }} <span class="text-sm font-normal text-gray-500">({{ percent(r.ratings, r.expected) }}% answered)</span></dd></div>
        <div><dt class="text-gray-500">"Not my teacher"</dt><dd class="text-lg font-semibold tabular-nums">{{ r.not_my_teacher }}</dd></div>
      </dl>
    </header>

    <div v-if="r.hidden" class="card mt-6 flex items-start gap-3 p-6 text-sm text-gray-600">
      <EyeSlashIcon class="h-5 w-5 shrink-0" />
      <p>Only {{ r.ratings }} students have rated this teacher. Results are shown from {{ r.min_group }} ratings, so nobody can work out what a particular student said.</p>
    </div>

    <template v-else>
      <!-- Sections -->
      <section v-if="r.sections?.length" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">By area</h2>
        <div class="mt-4 space-y-4">
          <div v-for="s in r.sections" :key="s.section">
            <div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
              <span class="font-medium">{{ s.section || 'Questions' }}</span>
              <span class="tabular-nums"><strong :class="scoreText(s.average)">{{ fmt(s.average) }}</strong> <span class="text-gray-500">· school {{ fmt(s.school_average) }} ({{ signed(diff(s.average, s.school_average)) }})</span></span>
            </div>
            <div class="relative h-3 rounded-full bg-gray-100">
              <div class="h-3 rounded-full" :class="(s.average ?? 0) >= 3.5 ? 'bg-indigo-600' : (s.average ?? 0) >= 3 ? 'bg-gold-400' : 'bg-red-500'" :style="{ width: `${((s.average ?? 0) / 5) * 100}%` }" />
              <div v-if="s.school_average" class="absolute -top-1 h-5 w-0.5 bg-ink" :style="{ left: `${(s.school_average / 5) * 100}%` }" title="School average" />
            </div>
          </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">The black line is the school average for that area.</p>
      </section>

      <!-- Strongest / weakest -->
      <div v-if="ranked.length >= 4" class="mt-6 grid gap-6 sm:grid-cols-2">
        <section class="card break-inside-avoid p-5">
          <h2 class="font-semibold text-indigo-800 dark:text-indigo-300">Strongest</h2>
          <ul class="mt-3 space-y-3">
            <li v-for="s in strongest" :key="s.question.id" class="flex items-start justify-between gap-3 text-sm">
              <span>{{ s.question.prompt }}</span><span class="shrink-0 rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(s.average)">{{ fmt(s.average) }}</span>
            </li>
          </ul>
        </section>
        <section class="card break-inside-avoid p-5">
          <h2 class="font-semibold text-red-700 dark:text-red-400">To work on</h2>
          <ul class="mt-3 space-y-3">
            <li v-for="s in weakest" :key="s.question.id" class="flex items-start justify-between gap-3 text-sm">
              <span>{{ s.question.prompt }}</span><span class="shrink-0 rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(s.average)">{{ fmt(s.average) }}</span>
            </li>
          </ul>
        </section>
      </div>

      <!-- Every statement -->
      <section v-if="r.scale?.length" class="card mt-6 p-6">
        <h2 class="font-semibold">Every statement</h2>
        <table class="mt-3 w-full text-sm">
          <thead class="text-left text-xs uppercase text-gray-500">
            <tr><th class="py-2 pr-3">Statement</th><th class="w-40 py-2 pr-3">Answers</th><th class="py-2 pr-2 text-right">Score</th><th class="py-2 text-right">School</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="s in r.scale" :key="s.question.id" class="break-inside-avoid">
              <td class="py-2 pr-3">{{ s.question.prompt }}</td>
              <td class="py-2 pr-3">
                <div class="flex h-2.5 overflow-hidden rounded-full bg-gray-100" :title="SCALE.map((x, k) => `${x.label}: ${s.counts?.[k] ?? 0}`).join(', ')">
                  <div v-for="(c, k) in s.counts" :key="k" :class="SCALE_COLOURS[k]" :style="{ width: `${s.answered ? (c / s.answered) * 100 : 0}%` }" />
                </div>
                <div class="mt-0.5 text-[0.68rem] text-gray-500">{{ s.agree_share }}% agree</div>
              </td>
              <td class="py-2 pr-2 text-right font-semibold tabular-nums" :class="scoreText(s.average)">{{ fmt(s.average) }}</td>
              <td class="py-2 text-right tabular-nums text-gray-500">{{ fmt(s.school_average) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Classes -->
      <section v-if="r.groups?.length" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">By class and stream</h2>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
          <div v-for="g in r.groups" :key="g.class + g.stream" class="flex items-center justify-between rounded-lg border border-line px-3 py-2 text-sm">
            <span>{{ g.class }} {{ g.stream }} <span class="text-gray-500">· {{ g.ratings }} ratings</span></span>
            <span v-if="g.enough" class="rounded px-2 py-0.5 font-semibold tabular-nums" :class="scoreCell(g.average)">{{ fmt(g.average) }}</span>
            <span v-else class="text-xs text-gray-500">too few to show</span>
          </div>
        </div>
      </section>

      <!-- Trend -->
      <section v-if="r.trend && r.trend.length > 1" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">Over time</h2>
        <div class="mt-4 flex h-36 items-end gap-3">
          <div v-for="t in r.trend" :key="t.poll_id" class="flex flex-1 flex-col items-center gap-1">
            <span class="text-xs font-semibold tabular-nums">{{ t.overall.toFixed(2) }}</span>
            <div class="relative h-24 w-full max-w-[4rem]"><div class="absolute inset-x-0 bottom-0 rounded-t" :class="t.poll_id === r.poll.id ? 'bg-gold-400' : 'bg-indigo-600'" :style="{ height: `${(t.overall / trendMax) * 100}%` }" /></div>
            <span class="line-clamp-2 text-center text-[0.68rem] text-gray-500">{{ t.title }}</span>
          </div>
        </div>
      </section>

      <!-- Other questions and comments -->
      <section v-for="o in r.others" :key="o.question.id" class="card mt-6 break-inside-avoid p-6">
        <h2 class="font-semibold">{{ o.question.prompt }}</h2>
        <template v-if="o.texts">
          <p v-if="!o.texts.length" class="mt-2 text-sm text-gray-500">No written answers.</p>
          <ul v-else class="mt-3 space-y-2">
            <li v-for="(t, k) in o.texts" :key="k" class="whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2 text-sm">{{ t }}</li>
          </ul>
        </template>
        <ul v-else-if="o.counts" class="mt-3 space-y-2.5">
          <li v-for="(c, k) in o.counts" :key="k">
            <div class="mb-1 flex justify-between text-sm"><span>{{ o.question.type === 'yesno' ? (k === 0 ? 'Yes' : 'No') : o.question.options[k] }}</span><span class="tabular-nums text-gray-600">{{ c }} · {{ percent(c, o.answered) }}%</span></div>
            <Meter :value="c" :max="o.answered" />
          </li>
        </ul>
      </section>
    </template>

    <p class="mt-8 text-xs text-gray-500">
      {{ session.school }} · Student Voice · answers are anonymous; groups under {{ r.min_group }} are never shown · printed {{ formatDate(new Date().toISOString()) }}
    </p>
  </article>
</template>
