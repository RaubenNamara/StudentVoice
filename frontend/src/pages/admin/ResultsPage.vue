<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, EyeSlashIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Meter from '@/components/Meter.vue'
import StatusChip from '@/components/StatusChip.vue'
import { api } from '@/api'
import { formatDate, percent } from '@/feedback'
import { SCALE, type Poll, type Question } from '@/types'

interface QResult {
  question: Question & { id: number }
  answered: number
  skipped: number
  counts?: number[]
  average?: number | null
  agree_share?: number | null
  texts?: string[]
}
interface Turnout {
  eligible: number
  voted: number
  classes: { class: string; students: number; voted: number; streams: { stream: string; students: number; voted: number }[] }[]
}
interface Results {
  poll: Poll
  ballots: number
  hidden: boolean
  min_group: number
  turnout: Turnout
  results: QResult[]
}

const route = useRoute()
const data = ref<Results | null>(null)
const filter = ref({ class: '', stream: '' })
const showAllTexts = ref<Record<number, boolean>>({})

async function load() {
  data.value = await api.get<Results>(`/admin/polls/${route.params.id}/results`, filter.value)
}
onMounted(load)
watch(() => filter.value.class, () => (filter.value.stream = ''))
watch(filter, load, { deep: true })

const streams = computed(() => data.value?.turnout.classes.find((c) => c.class === filter.value.class)?.streams ?? [])
const scope = computed(() => (filter.value.class ? `${filter.value.class}${filter.value.stream ? ' ' + filter.value.stream : ''}` : 'Whole school'))

/** Sections in order, with their questions */
const grouped = computed(() => {
  const out: { section: string | null; items: { r: QResult; n: number }[] }[] = []
  data.value?.results.forEach((r, i) => {
    const last = out[out.length - 1]
    if (!last || last.section !== r.question.section) out.push({ section: r.question.section, items: [] })
    out[out.length - 1].items.push({ r, n: i + 1 })
  })
  return out
})

const SCALE_COLOURS = ['bg-red-500', 'bg-orange-400', 'bg-gray-300', 'bg-indigo-400', 'bg-indigo-700']
const avgTone = (avg: number | null | undefined) =>
  avg == null ? 'text-gray-400' : avg >= 4 ? 'text-emerald-700 dark:text-emerald-400' : avg >= 3 ? 'text-gold-700' : 'text-red-700 dark:text-red-400'

const print = () => window.print()

/** One row per question (and option): the numbers, for a spreadsheet */
function downloadCsv() {
  if (!data.value) return
  const rows: (string | number)[][] = [['#', 'Section', 'Question', 'Type', 'Answer', 'Count', 'Share %', 'Average (1-5)']]
  data.value.results.forEach((r, i) => {
    const q = r.question
    const base = [i + 1, q.section ?? '', q.prompt, q.type]
    if (q.type === 'scale') r.counts!.forEach((n, k) => rows.push([...base, `${k + 1} ${SCALE[k].label}`, n, percent(n, r.answered), k === 0 ? r.average ?? '' : '']))
    else if (q.type === 'yesno') ['Yes', 'No'].forEach((t, k) => rows.push([...base, t, r.counts![k], percent(r.counts![k], r.answered), '']))
    else if (q.type === 'choice' || q.type === 'multi') q.options.forEach((o, k) => rows.push([...base, o, r.counts![k], percent(r.counts![k], r.answered), '']))
    else r.texts!.forEach((t) => rows.push([...base, t, '', '', '']))
  })
  const csv = rows.map((row) => row.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
  a.download = `${data.value.poll.title} - ${scope.value}.csv`.replace(/[\\/:*?"<>|]/g, '')
  a.click()
  URL.revokeObjectURL(a.href)
}
</script>

<template>
  <div v-if="!data" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-48 animate-pulse" />
  </div>

  <template v-else>
    <PageHeader :title="data.poll.title" :subtitle="`Results · ${scope}`">
      <template #crumb>
        <RouterLink :to="{ name: 'poll', params: { id: data.poll.id } }" class="no-print text-sm text-gray-500 hover:text-ink">← Poll</RouterLink>
        <div class="mb-1 mt-2"><StatusChip :status="data.poll.status" :accepting="data.poll.accepting" /></div>
      </template>
      <button class="btn-secondary no-print" :disabled="!data.ballots || data.hidden" @click="downloadCsv"><ArrowDownTrayIcon class="h-4 w-4" /> Spreadsheet</button>
      <button class="btn-secondary no-print" @click="print"><PrinterIcon class="h-4 w-4" /> Print</button>
    </PageHeader>

    <!-- Turnout -->
    <section class="card p-5">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">Turnout</h2>
        <p class="text-sm text-gray-600">
          <strong class="text-lg tabular-nums text-ink">{{ data.turnout.voted.toLocaleString() }}</strong> of {{ data.turnout.eligible.toLocaleString() }} students voted
          · {{ percent(data.turnout.voted, data.turnout.eligible) }}%
        </p>
      </div>
      <div class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        <details v-for="c in data.turnout.classes" :key="c.class" class="group">
          <summary class="cursor-pointer list-none">
            <div class="mb-1 flex justify-between text-sm">
              <span class="font-medium">{{ c.class }} <span class="text-xs font-normal text-gray-400 group-open:hidden">▸ streams</span></span>
              <span class="tabular-nums text-gray-600">{{ c.voted }} / {{ c.students }} · {{ percent(c.voted, c.students) }}%</span>
            </div>
            <Meter :value="c.voted" :max="c.students" tone="gold" />
          </summary>
          <ul class="mt-2 space-y-1 border-l-2 border-line pl-3">
            <li v-for="s in c.streams" :key="s.stream" class="flex justify-between text-xs text-gray-600">
              <span>{{ c.class }} {{ s.stream || '(no stream)' }}</span>
              <span class="tabular-nums">{{ s.voted }} / {{ s.students }} · {{ percent(s.voted, s.students) }}%</span>
            </li>
          </ul>
        </details>
      </div>
    </section>

    <!-- Filter -->
    <div class="no-print mt-6 flex flex-wrap items-end gap-3">
      <div>
        <label class="mb-1 block text-xs font-semibold text-gray-500" for="fc">Show answers from</label>
        <select id="fc" v-model="filter.class" class="input w-44">
          <option value="">Whole school</option>
          <option v-for="c in data.turnout.classes" :key="c.class" :value="c.class">{{ c.class }}</option>
        </select>
      </div>
      <div v-if="filter.class">
        <label class="mb-1 block text-xs font-semibold text-gray-500" for="fs">Stream</label>
        <select id="fs" v-model="filter.stream" class="input w-36">
          <option value="">All streams</option>
          <option v-for="s in streams" :key="s.stream" :value="s.stream">{{ s.stream || '(none)' }}</option>
        </select>
      </div>
      <p class="pb-2 text-sm text-gray-500">{{ data.ballots.toLocaleString() }} sets of answers</p>
    </div>

    <div v-if="data.hidden" class="card mt-4 flex items-start gap-3 p-6 text-sm text-gray-600">
      <EyeSlashIcon class="h-5 w-5 shrink-0" />
      <p>Only {{ data.ballots }} students in {{ scope }} have voted. Answers from groups smaller than {{ data.min_group }} are not shown, so nobody can work out what a particular student said.</p>
    </div>
    <div v-else-if="!data.ballots" class="card mt-4 p-10 text-center text-sm text-gray-500">No answers yet.</div>

    <!-- Per question -->
    <div v-else class="mt-4 space-y-8">
      <section v-for="(g, gi) in grouped" :key="gi">
        <h2 v-if="g.section" class="mb-3 border-b-2 border-gold-400 pb-2 font-semibold text-indigo-800 dark:text-indigo-300">{{ g.section }}</h2>
        <div class="space-y-3">
          <article v-for="{ r, n } in g.items" :key="r.question.id" class="card break-inside-avoid p-5">
            <div class="flex items-start justify-between gap-4">
              <p class="font-medium"><span class="mr-1 text-gray-400">{{ n }}.</span> {{ r.question.prompt }}</p>
              <div v-if="r.question.type === 'scale'" class="shrink-0 text-right">
                <div class="text-2xl font-semibold tabular-nums" :class="avgTone(r.average)">{{ r.average?.toFixed(1) ?? '–' }}</div>
                <div class="text-[0.7rem] text-gray-500">average of 5</div>
              </div>
            </div>
            <p class="mt-1 text-xs text-gray-500">{{ r.answered }} answered<template v-if="r.skipped"> · {{ r.skipped }} skipped</template></p>

            <!-- scale: one stacked bar, then the five counts -->
            <template v-if="r.question.type === 'scale' && r.counts">
              <div class="mt-4 flex h-4 overflow-hidden rounded-full bg-gray-100">
                <div v-for="(c, k) in r.counts" :key="k" :class="SCALE_COLOURS[k]" :style="{ width: `${r.answered ? (c / r.answered) * 100 : 0}%` }" :title="`${SCALE[k].label}: ${c}`" />
              </div>
              <div class="mt-3 grid grid-cols-5 gap-2 text-center text-xs">
                <div v-for="(c, k) in r.counts" :key="k">
                  <div class="mx-auto mb-1 h-2 w-2 rounded-full" :class="SCALE_COLOURS[k]" />
                  <div class="font-semibold tabular-nums">{{ percent(c, r.answered) }}%</div>
                  <div class="text-gray-500">{{ SCALE[k].label }}</div>
                </div>
              </div>
              <p v-if="r.agree_share !== null" class="mt-3 text-sm text-gray-600"><strong>{{ r.agree_share }}%</strong> agree or strongly agree.</p>
            </template>

            <!-- yes/no, pick one, pick any -->
            <ul v-else-if="r.counts" class="mt-4 space-y-2.5">
              <li v-for="(c, k) in r.counts" :key="k">
                <div class="mb-1 flex justify-between gap-3 text-sm">
                  <span>{{ r.question.type === 'yesno' ? (k === 0 ? 'Yes' : 'No') : r.question.options[k] }}</span>
                  <span class="shrink-0 tabular-nums text-gray-600">{{ c }} · {{ percent(c, r.answered) }}%</span>
                </div>
                <Meter :value="c" :max="r.answered" />
              </li>
              <li v-if="r.question.type === 'multi'" class="text-xs text-gray-500">Students could pick more than one, so shares add up to more than 100%.</li>
            </ul>

            <!-- written answers -->
            <template v-else-if="r.texts">
              <p v-if="!r.texts.length" class="mt-3 text-sm text-gray-500">No written answers.</p>
              <ul v-else class="mt-3 space-y-2">
                <li v-for="(t, k) in showAllTexts[r.question.id] ? r.texts : r.texts.slice(0, 8)" :key="k" class="whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2 text-sm">{{ t }}</li>
              </ul>
              <button v-if="r.texts.length > 8 && !showAllTexts[r.question.id]" class="btn-ghost no-print mt-2 px-2 text-xs" @click="showAllTexts[r.question.id] = true">Show all {{ r.texts.length }}</button>
            </template>
          </article>
        </div>
      </section>
    </div>

    <p class="mt-8 text-xs text-gray-500">
      Answers are stored without names or voting numbers. Printed {{ formatDate(new Date().toISOString()) }}.
    </p>
  </template>
</template>
