<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, EyeSlashIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import LikertBar from '@/components/LikertBar.vue'
import { api } from '@/api'
import { formatDate } from '@/feedback'
import { asPct, fmt, scoreCell } from '@/scores'
import { subjectLook } from '@/subjects'
import { FLAGS, flagOf } from '@/commentFlags'
import { SCALE, type Poll } from '@/types'
import { useSession } from '@/stores/session'

/**
 * The meeting report: teachers printed two or three to a page, a subject at a time, each with
 * their overall picture, strongest statements, statements to work on and a few chosen student
 * comments, to be read out in a staff meeting. Admins choose which comments print (the most
 * useful are picked for them) and can leave a teacher out. Admin-only, like every result.
 */
interface Q { id: number; prompt: string; section: string | null }
interface Row {
  teacher_id: number
  teacher: string
  subject_id: number
  subject: string
  ratings: number
  counts: Record<string, number[]>
  comments: Record<string, string[]>
}
interface Data {
  poll: Poll
  questions: Q[]
  comment_questions: { id: number; prompt: string }[]
  school: number[]
  teachers: Row[]
  hidden: number
  min_group: number
}

const route = useRoute()
const session = useSession()
const d = ref<Data | null>(null)
const error = ref('')
onMounted(async () => {
  try {
    d.value = await api.get<Data>(`/admin/polls/${route.params.id}/teachers/meeting`)
    pickDefaults()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not load the report.'
  }
})

/* ---------- choices (screen only) ---------- */
const subject = ref<number | 0>(0)
const perPage = ref<2 | 3>(3)
const perQuestion = ref(3)
const order = ref<'name' | 'best' | 'lowest'>('name')
/** only teachers with a sensitive comment; and the top 5 / bottom 5 page at the end */
const onlyFlagged = ref(false)
const withSummary = ref(true)
const left = reactive(new Set<string>()) // teachers left out ("t|s")
const chosen = reactive(new Set<string>()) // comments to print ("t|s|q|i")
const openPicker = reactive(new Set<string>()) // comment lists opened to choose from

const key = (r: Row) => `${r.teacher_id}|${r.subject_id}`

/* ---------- numbers ---------- */
const zero = () => [0, 0, 0, 0, 0]
const total = (c: number[]) => c.reduce((s, n) => s + n, 0)
const share = (c: number[], ...i: number[]) => (total(c) ? Math.round((i.reduce((s, k) => s + c[k], 0) / total(c)) * 100) : 0)
const average = (c: number[]) => (total(c) ? c.reduce((s, n, i) => s + n * (i + 1), 0) / total(c) : null)
const overall = (r: Row) => Object.values(r.counts).reduce((acc, c) => acc.map((n, i) => n + c[i]), zero())

/** Statements this teacher has enough answers on, best first */
function ranked(r: Row) {
  const min = d.value?.min_group ?? 5
  return (d.value?.questions ?? [])
    .map((q) => ({ q, c: r.counts[q.id] ?? zero() }))
    .filter((x) => total(x.c) >= min)
    .map((x) => ({ ...x, avg: average(x.c)! }))
    .sort((a, b) => b.avg - a.avg)
}
function strengths(r: Row) {
  const list = ranked(r)
  return list.length >= 4 ? list.slice(0, 2) : list.slice(0, 1)
}
function toWorkOn(r: Row) {
  const list = ranked(r)
  return list.length >= 4 ? list.slice(-2).reverse() : list.length > 1 ? list.slice(-1) : []
}
/** Why a statement is weak, in words */
const weakNote = (c: number[]) => {
  const dis = share(c, 0, 1)
  return dis ? `${dis}% disagree` : `${share(c, 2)}% not sure`
}

/* ---------- comments ---------- */
const NOTHING = /^(n\/?a|no|none|nothing|nil|non|ok|okay|good|fine|yes|no comment|nothing to say|\.|-)+$/i
/** How useful a comment is to read out: real sentences first, one-word answers never */
function worth(text: string): number {
  const t = text.trim()
  if (t.length < 12 || NOTHING.test(t.replace(/[.!\s]+$/g, ''))) return 0
  const words = t.split(/\s+/).length
  return Math.min(words, 40) + (/[.!?]/.test(t) ? 2 : 0)
}
const clean = (t: string) => t.trim().replace(/\s+/g, ' ').replace(/^./, (c) => c.toUpperCase())

/**
 * The comments worth offering (repeats merged): sensitive ones first (harassment of any kind, then
 * academic concerns), then the most useful. A sensitive comment is kept however short it is.
 */
function offered(r: Row, qid: number) {
  const seen = new Set<string>()
  return (r.comments[qid] ?? [])
    .map((text, i) => ({ text: clean(text), i, w: worth(text), flag: flagOf(text) }))
    .filter((c) => (c.w > 0 || c.flag) && c.text.length > 1 && !seen.has(c.text.toLowerCase()) && seen.add(c.text.toLowerCase()))
    .sort((a, b) => (a.flag?.rank ?? 9) - (b.flag?.rank ?? 9) || b.w - a.w)
}
/** Sensitive comments found, per kind, across the teachers being printed */
const flagged = computed(() => {
  const out = new Map<string, { label: string; count: number; teachers: Set<string> }>()
  for (const s of sheets.value) {
    for (const r of s.rows) {
      for (const q of d.value?.comment_questions ?? []) {
        for (const c of offered(r, q.id)) {
          if (!c.flag) continue
          const e = out.get(c.flag.key) ?? { label: c.flag.label, count: 0, teachers: new Set<string>() }
          e.count++
          e.teachers.add(key(r))
          out.set(c.flag.key, e)
        }
      }
    }
  }
  return FLAGS.filter((f) => out.has(f.key)).map((f) => ({ key: f.key, ...out.get(f.key)! }))
})
const hasFlag = (r: Row) => (d.value?.comment_questions ?? []).some((q) => offered(r, q.id).some((c) => c.flag))
const ck = (r: Row, qid: number, i: number) => `${key(r)}|${qid}|${i}`

function pickDefaults() {
  chosen.clear()
  for (const r of d.value?.teachers ?? []) {
    for (const q of d.value?.comment_questions ?? []) {
      offered(r, q.id).slice(0, perQuestion.value).forEach((c) => chosen.add(ck(r, q.id, c.i)))
    }
  }
}
watch(perQuestion, pickDefaults)
const printed = (r: Row, qid: number) => offered(r, qid).filter((c) => chosen.has(ck(r, qid, c.i)))
function toggle(id: string) {
  if (chosen.has(id)) chosen.delete(id)
  else chosen.add(id)
}
function togglePicker(id: string) {
  if (openPicker.has(id)) openPicker.delete(id)
  else openPicker.add(id)
}
/** Short heading for a comment question ("What does this teacher do well?" -> "Does well") */
function label(prompt: string) {
  if (/well/i.test(prompt)) return 'What students say works well'
  if (/better|improve/i.test(prompt)) return 'What students would like better'
  return prompt
}

/* ---------- pages ---------- */
const subjects = computed(() => {
  const seen = new Map<number, string>()
  for (const r of d.value?.teachers ?? []) seen.set(r.subject_id, subjectLook(r.subject).name)
  return [...seen].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
})
const sorted = (rows: Row[]) =>
  [...rows].sort((a, b) =>
    order.value === 'name' ? a.teacher.localeCompare(b.teacher) : ((average(overall(b)) ?? 0) - (average(overall(a)) ?? 0)) * (order.value === 'best' ? 1 : -1),
  )
/** Sheets of paper: per subject, perPage teachers each */
const sheets = computed(() => {
  const out: { subject: string; part: number; parts: number; rows: Row[] }[] = []
  for (const s of subjects.value) {
    if (subject.value && subject.value !== s.id) continue
    const rows = sorted((d.value?.teachers ?? []).filter((r) => r.subject_id === s.id && !left.has(key(r)) && (!onlyFlagged.value || hasFlag(r))))
    const parts = Math.ceil(rows.length / perPage.value)
    for (let p = 0; p < parts; p++) out.push({ subject: s.name, part: p + 1, parts, rows: rows.slice(p * perPage.value, (p + 1) * perPage.value) })
  }
  return out
})
/** Teachers left out, in the subjects being printed (so they can be put back) */
const leftOut = computed(() => (d.value?.teachers ?? []).filter((r) => left.has(key(r)) && (!subject.value || r.subject_id === subject.value)))
const teacherCount = computed(() => sheets.value.reduce((n, s) => n + s.rows.length, 0))

/* ---------- the general summary: best 5 and lowest 5 ---------- */
const scoreOf = (r: Row) => average(overall(r)) ?? 0
/** Teachers the summary ranks: the chosen subject(s), less any left out */
const inScope = computed(() => (d.value?.teachers ?? []).filter((r) => (!subject.value || r.subject_id === subject.value) && !left.has(key(r))))
const best = computed(() => [...inScope.value].sort((a, b) => scoreOf(b) - scoreOf(a) || b.ratings - a.ratings).slice(0, 5))
/** Lowest first; never a teacher already in the best 5 (when there are fewer than 10) */
const lowest = computed(() => {
  const top = new Set(best.value.map(key))
  return [...inScope.value].filter((r) => !top.has(key(r))).sort((a, b) => scoreOf(a) - scoreOf(b) || b.ratings - a.ratings).slice(0, 5)
})
const showSummary = computed(() => withSummary.value && inScope.value.length >= 2)
const pageCount = computed(() => sheets.value.length + (showSummary.value ? 1 : 0))
const summaryTables = computed(() => [
  { title: 'Best performing teachers', rows: best.value, tone: 'text-indigo-800', bar: 'bg-indigo-700' },
  { title: 'Teachers who need the most support', rows: lowest.value, tone: 'text-red-700', bar: 'bg-red-600' },
])

const PER = [2, 3] as const
const SWATCH = ['bg-lk-1', 'bg-lk-2', 'bg-lk-3', 'bg-lk-4', 'bg-lk-5']
/** Colour of a sensitive-comment tag (on screen and paper) */
const FLAG_TONE: Record<string, string> = {
  sexual: 'bg-red-100 text-red-800 ring-red-300',
  physical: 'bg-red-100 text-red-800 ring-red-300',
  verbal: 'bg-orange-100 text-orange-800 ring-orange-300',
  academic: 'bg-gold-50 text-gold-700 ring-gold-300',
}
const today = formatDate(new Date().toISOString(), false)
const print = () => window.print()

/* ---------- one PDF per teacher, zipped (to send each teacher their own) ---------- */
const withoutSensitive = ref(false)
const zipping = ref<{ done: number; total: number } | null>(null)
const zipNote = ref('')
/** Teachers the download covers: the chosen subject(s), less any left out, one file each */
const zipTeachers = computed(() => new Set(inScope.value.map((r) => r.teacher_id)).size)
async function downloadZip() {
  if (!d.value || zipping.value) return
  zipNote.value = ''
  zipping.value = { done: 0, total: zipTeachers.value }
  try {
    const { teacherReportsZip } = await import('@/teacherPdf')
    const { blob, files, comments } = await teacherReportsZip({
      school: session.school || "St. Mark's College Namagoma",
      poll: d.value.poll.title,
      questions: d.value.questions,
      commentQuestions: d.value.comment_questions,
      schoolCounts: d.value.school,
      minGroup: d.value.min_group,
      rows: inScope.value.map((r) => ({ ...r, subject: subjectLook(r.subject).name })),
      withoutSensitive: withoutSensitive.value,
      onProgress: (done, total) => (zipping.value = { done, total }),
    })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    const part = subject.value ? ` - ${subjects.value.find((x) => x.id === subject.value)?.name ?? ''}` : ''
    a.download = `${d.value.poll.title}${part} - teacher reports.zip`.replace(/[\\/:*?"<>|]/g, '')
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 10000)
    zipNote.value = `${files} teacher report${files === 1 ? '' : 's'} downloaded, with ${comments} written comment${comments === 1 ? '' : 's'}.`
  } catch (e) {
    zipNote.value = e instanceof Error ? `Could not make the reports: ${e.message}` : 'Could not make the reports.'
  } finally {
    zipping.value = null
  }
}
</script>

<template>
  <div v-if="error" class="card p-8 text-center text-gray-700">{{ error }}</div>
  <div v-else-if="!d" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-64 animate-pulse" />
  </div>

  <div v-else class="meeting mx-auto max-w-4xl">
    <!-- Choices (never printed) -->
    <div class="no-print">
      <RouterLink :to="{ name: 'teacher-results', params: { id: d.poll.id } }" class="text-sm text-gray-500 hover:text-ink">← Teacher results</RouterLink>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">Meeting report</h1>
          <p class="mt-1 text-sm text-gray-600">{{ d.poll.title }} · teachers {{ perPage }} to a page, a subject at a time, with chosen student comments</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button class="btn-secondary" :disabled="!zipTeachers || !!zipping" title="One PDF for each teacher, with all their comments, in a ZIP file" @click="downloadZip">
            <ArrowDownTrayIcon class="h-4 w-4" />
            {{ zipping ? `Making ${zipping.done} of ${zipping.total}...` : `Teacher reports (ZIP, ${zipTeachers})` }}
          </button>
          <button class="btn-primary" :disabled="!pageCount" @click="print"><PrinterIcon class="h-4 w-4" /> Print report</button>
        </div>
      </div>

      <div class="card mt-4 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <label class="block">
          <span class="label">Subject</span>
          <select v-model.number="subject" class="input">
            <option :value="0">All subjects ({{ subjects.length }})</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <div>
          <span class="label">Teachers per page</span>
          <div class="flex gap-2">
            <button v-for="n in PER" :key="n" type="button" class="flex-1 rounded-lg border px-3 py-2 text-sm font-semibold" :class="perPage === n ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-line hover:bg-gray-50'" @click="perPage = n">{{ n }}</button>
          </div>
        </div>
        <label class="block">
          <span class="label">Comments to pick for each</span>
          <select v-model.number="perQuestion" class="input">
            <option :value="0">None</option>
            <option v-for="n in 10" :key="n" :value="n">{{ n === 10 ? 'Up to 10' : n }} of each kind</option>
          </select>
        </label>
        <label class="block">
          <span class="label">Order in a subject</span>
          <select v-model="order" class="input">
            <option value="name">By name</option>
            <option value="best">Highest score first</option>
            <option value="lowest">Lowest score first</option>
          </select>
        </label>
      </div>
      <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
        <label class="flex items-center gap-2"><input v-model="onlyFlagged" type="checkbox" class="rounded" /> Only teachers with sensitive comments</label>
        <label class="flex items-center gap-2"><input v-model="withSummary" type="checkbox" class="rounded" /> Add a last page: best 5 and lowest 5 teachers</label>
        <label class="flex items-center gap-2"><input v-model="withoutSensitive" type="checkbox" class="rounded" /> Leave sensitive comments out of the teachers' own reports</label>
      </div>
      <p class="mt-2 text-sm text-gray-600">
        <strong>Teacher reports (ZIP)</strong> makes one PDF for each teacher, named after them, to send to them privately (for example on WhatsApp). Each has their scores for every statement and <strong>every</strong> written comment, not only the ticked ones (identical comments are listed once with how many students wrote them). A teacher of two subjects gets both in one file.
      </p>
      <p v-if="zipNote" class="mt-2 text-sm font-medium text-indigo-800 dark:text-indigo-300">{{ zipNote }}</p>

      <!-- sensitive comments found (harassment, academic concerns) -->
      <div v-if="flagged.length" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
        <p class="font-semibold">Sensitive comments found</p>
        <p class="mt-1 flex flex-wrap gap-2">
          <span v-for="f in flagged" :key="f.key" class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1" :class="FLAG_TONE[f.key]">{{ f.label }}: {{ f.count }} ({{ f.teachers.size }} teacher{{ f.teachers.size === 1 ? '' : 's' }})</span>
        </p>
        <p class="mt-2 text-xs">They are listed first under each teacher and marked. They are found by their words, so read every comment too: some may be missed (other wording, Luganda) or marked by mistake. Harassment reports may need to be followed up privately, not only read out in the meeting.</p>
      </div>

      <p class="mt-3 text-sm text-gray-600">
        Sensitive comments come first, then the most useful ones, and they are ticked for you. Under each teacher, <strong>Choose comments</strong> lets you tick or untick any of them; only ticked ones print.
        <template v-if="d.hidden"> {{ d.hidden }} teacher{{ d.hidden === 1 ? ' has' : 's have' }} fewer than {{ d.min_group }} ratings and {{ d.hidden === 1 ? 'is' : 'are' }} not in the report.</template>
      </p>
      <p v-if="leftOut.length" class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
        Left out:
        <button v-for="r in leftOut" :key="key(r)" type="button" class="rounded-full border border-line px-2.5 py-0.5 text-xs hover:bg-gray-50" @click="left.delete(key(r))">{{ r.teacher }} ({{ subjectLook(r.subject).name }}) · put back</button>
      </p>
    </div>

    <div v-if="!sheets.length" class="card mt-6 flex items-start gap-3 p-6 text-sm text-gray-600">
      <EyeSlashIcon class="h-5 w-5 shrink-0" />
      <p v-if="onlyFlagged && inScope.length">No sensitive comments were found for these teachers. Untick <strong>Only teachers with sensitive comments</strong> to see everyone.</p>
      <p v-else>No teacher has {{ d.min_group }} or more ratings yet<template v-if="subject"> in this subject</template>. Teachers appear here once enough students have rated them, so nobody can work out what a particular student said.</p>
    </div>

    <!-- The pages -->
    <section v-for="s in sheets" :key="s.subject + s.part" class="sheet force-light mt-6 rounded-2xl border border-line bg-white p-6 text-[rgb(24,24,38)] shadow-sm">
      <!-- letterhead -->
      <header class="flex items-center gap-3 border-b-4 border-double border-indigo-800 pb-2">
        <BrandMark :size="44" />
        <div class="min-w-0 flex-1">
          <p class="text-base font-semibold uppercase tracking-wide text-indigo-900">{{ session.school || "St. Mark's College Namagoma" }}</p>
          <p class="text-xs text-gray-700">Student Voice · {{ d.poll.title }} · <strong>confidential, for the staff meeting</strong></p>
        </div>
        <div class="text-right">
          <p class="text-lg font-semibold leading-tight text-indigo-900">{{ s.subject }}</p>
          <p v-if="s.parts > 1" class="text-xs text-gray-600">part {{ s.part }} of {{ s.parts }}</p>
        </div>
      </header>
      <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.7rem] text-gray-600" aria-hidden="true">
        <span class="font-semibold">Students' answers:</span>
        <span v-for="(sc, i) in SCALE" :key="sc.value" class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" :class="SWATCH[i]" />{{ sc.label }}</span>
        <span class="ml-auto">Score = average answer out of 5, as a % · school {{ asPct(average(d.school)) }} ({{ fmt(average(d.school)) }})</span>
      </div>

      <!-- teachers on this page -->
      <article v-for="r in s.rows" :key="key(r)" class="teacher mt-3 rounded-xl border border-line p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-lg font-semibold leading-tight">{{ r.teacher }}</h2>
            <p class="text-xs text-gray-600">{{ subjectLook(r.subject).name }} · {{ r.ratings }} students rated</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <button type="button" class="no-print rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-50 hover:text-ink" title="Leave this teacher out of the printout" @click="left.add(key(r))">Leave out</button>
          </div>
        </div>
        <!-- comments: the ticked ones print; the picker is screen-only -->
        <div v-if="d.comment_questions.length" class="mt-2 grid grid-cols-2 gap-4 text-[0.8rem]">
          <div v-for="q in d.comment_questions" :key="q.id">
            <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-gold-700">{{ label(q.prompt) }}</p>
            <ol v-if="printed(r, q.id).length" class="mt-1 space-y-1">
              <li v-for="(c, k) in printed(r, q.id)" :key="c.i" class="flex gap-1.5 leading-snug">
                <span class="w-5 shrink-0 text-right font-semibold tabular-nums text-gray-500">{{ k + 1 }}.</span>
                <span class="min-w-0">
                  <span v-if="c.flag" class="mr-1 whitespace-nowrap rounded px-1.5 py-px text-[0.65rem] font-semibold not-italic ring-1" :class="FLAG_TONE[c.flag.key]">{{ c.flag.label }}</span>
                  <span class="italic">“{{ c.text }}”</span>
                </span>
              </li>
            </ol>
            <p v-else class="mt-1 text-gray-500">{{ offered(r, q.id).length ? 'None chosen.' : 'No written comments.' }}</p>
            <div v-if="offered(r, q.id).length" class="no-print mt-1.5">
              <button type="button" class="text-xs font-semibold text-indigo-700 hover:underline" @click="togglePicker(key(r) + '|' + q.id)">
                {{ openPicker.has(key(r) + '|' + q.id) ? 'Done choosing' : `Choose comments (${offered(r, q.id).length})` }}
              </button>
              <ul v-if="openPicker.has(key(r) + '|' + q.id)" class="mt-1.5 max-h-64 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                <li v-for="c in offered(r, q.id)" :key="c.i">
                  <label class="flex cursor-pointer items-start gap-2 rounded px-1 py-0.5 hover:bg-gray-50">
                    <input type="checkbox" class="mt-0.5 rounded" :checked="chosen.has(ck(r, q.id, c.i))" @change="toggle(ck(r, q.id, c.i))" />
                    <span><span v-if="c.flag" class="mr-1 rounded px-1.5 py-px text-[0.65rem] font-semibold ring-1" :class="FLAG_TONE[c.flag.key]">{{ c.flag.label }}</span>{{ c.text }}</span>
                  </label>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- the numbers, below the comments -->
        <div class="stats mt-3 border-t border-dashed border-line pt-3">
          <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1"><LikertBar :counts="overall(r)" /></div>
            <div class="flex shrink-0 items-center gap-2">
              <span class="rounded-lg bg-gray-50 px-2.5 py-1 text-center text-xs text-gray-600"><span class="block text-base font-semibold tabular-nums text-ink">{{ share(overall(r), 3, 4) }}%</span>agree</span>
              <span class="rounded-lg px-2.5 py-1 text-center text-xs" :class="scoreCell(average(overall(r)))"><span class="block text-base font-semibold tabular-nums">{{ asPct(average(overall(r))) }}</span>score · {{ fmt(average(overall(r))) }}/5</span>
            </div>
          </div>
          <div v-if="ranked(r).length" class="mt-3 grid grid-cols-2 gap-4 text-[0.8rem]">
            <div>
              <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-indigo-800">Strongest</p>
              <ul class="mt-1 space-y-1">
                <li v-for="x in strengths(r)" :key="x.q.id" class="flex justify-between gap-2"><span>{{ x.q.prompt }}</span><span class="shrink-0 font-semibold tabular-nums text-indigo-800">{{ share(x.c, 3, 4) }}% agree</span></li>
              </ul>
            </div>
            <div>
              <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-red-700">To work on</p>
              <ul class="mt-1 space-y-1">
                <li v-for="x in toWorkOn(r)" :key="x.q.id" class="flex justify-between gap-2"><span>{{ x.q.prompt }}</span><span class="shrink-0 font-semibold tabular-nums text-red-700">{{ weakNote(x.c) }}</span></li>
                <li v-if="!toWorkOn(r).length" class="text-gray-500">Not enough answers per statement yet.</li>
              </ul>
            </div>
          </div>

        </div>
      </article>

      <footer class="mt-3 flex justify-between text-[0.68rem] text-gray-500">
        <span>Anonymous: students' names are never kept with their answers. Teachers with fewer than {{ d.min_group }} ratings are not shown.</span>
        <span class="shrink-0 pl-3">{{ today }}</span>
      </footer>
    </section>

    <!-- The last page: best 5 and lowest 5 -->
    <section v-if="showSummary" class="sheet force-light mt-6 rounded-2xl border border-line bg-white p-6 text-[rgb(24,24,38)] shadow-sm">
      <header class="flex items-center gap-3 border-b-4 border-double border-indigo-800 pb-2">
        <BrandMark :size="44" />
        <div class="min-w-0 flex-1">
          <p class="text-base font-semibold uppercase tracking-wide text-indigo-900">{{ session.school || "St. Mark's College Namagoma" }}</p>
          <p class="text-xs text-gray-700">Student Voice · {{ d.poll.title }} · <strong>confidential, for the staff meeting</strong></p>
        </div>
        <div class="text-right">
          <p class="text-lg font-semibold leading-tight text-indigo-900">General summary</p>
          <p class="text-xs text-gray-600">{{ subject ? subjects.find((x) => x.id === subject)?.name : 'All subjects' }} · {{ inScope.length }} teachers</p>
        </div>
      </header>
      <p class="mt-2 text-xs text-gray-600">Ranked by score: the average answer out of 5, as a %. School average {{ asPct(average(d.school)) }} ({{ fmt(average(d.school)) }}). Only teachers rated by {{ d.min_group }} or more students.</p>

      <div v-for="t in summaryTables" :key="t.title" class="mt-5">
        <h2 class="text-base font-semibold" :class="t.tone">{{ t.title }} <span class="font-normal text-gray-500">({{ t.rows.length }})</span></h2>
        <table class="mt-2 w-full text-sm">
          <thead class="text-left text-[0.68rem] uppercase tracking-wider text-gray-500">
            <tr>
              <th class="w-8 py-1.5 pr-2 font-semibold">#</th>
              <th class="py-1.5 pr-3 font-semibold">Teacher</th>
              <th class="py-1.5 pr-3 font-semibold">Subject</th>
              <th class="py-1.5 pr-3 text-right font-semibold">Students</th>
              <th class="py-1.5 pr-3 text-right font-semibold">Agree</th>
              <th class="w-[30%] py-1.5 font-semibold">Score</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in t.rows" :key="key(r)" class="border-t border-line">
              <td class="py-2 pr-2 font-semibold tabular-nums text-gray-500">{{ i + 1 }}</td>
              <td class="py-2 pr-3 font-medium">{{ r.teacher }}</td>
              <td class="py-2 pr-3 text-gray-600">{{ subjectLook(r.subject).name }}</td>
              <td class="py-2 pr-3 text-right tabular-nums text-gray-600">{{ r.ratings }}</td>
              <td class="py-2 pr-3 text-right tabular-nums">{{ share(overall(r), 3, 4) }}%</td>
              <td class="py-2">
                <div class="flex items-center gap-2">
                  <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full" :class="t.bar" :style="{ width: `${(scoreOf(r) / 5) * 100}%` }" /></div>
                  <span class="w-24 shrink-0 text-right font-semibold tabular-nums" :class="t.tone">{{ asPct(scoreOf(r)) }} <span class="text-xs font-normal text-gray-500">{{ fmt(scoreOf(r)) }}</span></span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="mt-5 flex justify-between text-[0.68rem] text-gray-500">
        <span>Anonymous: students' names are never kept with their answers. Teachers with fewer than {{ d.min_group }} ratings are not ranked.</span>
        <span class="shrink-0 pl-3">{{ today }}</span>
      </footer>
    </section>

    <p v-if="sheets.length" class="no-print mt-4 text-center text-xs text-gray-500">{{ teacherCount }} teacher{{ teacherCount === 1 ? '' : 's' }} on {{ sheets.length }} page{{ sheets.length === 1 ? '' : 's' }}</p>
  </div>
</template>

<style scoped>
@media print {
  .meeting { max-width: none; }
  /* one sheet of paper each; no card look on paper */
  .sheet { margin: 0; border: 0; border-radius: 0; box-shadow: none; padding: 0; break-after: page; }
  .sheet:last-of-type { break-after: auto; }
  /* compact enough for three teachers on one A4 page */
  .teacher { break-inside: avoid; margin-top: 7px; padding: 8px 12px; font-size: 11px; line-height: 1.3; }
  .teacher h2 { font-size: 15px; }
  .teacher :is(.text-\[0\.8rem\]) { font-size: 10.5px; margin-top: 6px; }
  .teacher li { margin-top: 1px; }
}
</style>
