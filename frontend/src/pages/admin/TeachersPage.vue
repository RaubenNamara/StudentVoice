<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowPathIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import { api, ApiError } from '@/api'
import { formatDate, toast } from '@/feedback'
import { CLASSES } from '@/types'
import { useSession } from '@/stores/session'

const session = useSession()

interface TeacherRow { id: number; name: string; employee_number: string | null; status: 'active' | 'gone'; subjects: string | null; streams: number }
interface Overview {
  teachers: TeacherRow[]
  sync: { configured: boolean; last: { finished_at: string | null; started_at: string; status: string; message: string | null; started_by_name: string } | null }
  gaps: number
  subjects: number
}
interface Person { id: number; name: string }
interface Cell { takers: number; teachers: { teacher_id: number; source: 'auto' | 'admin' }[] }
interface Grid {
  class: string
  streams: { stream: string; students: number }[]
  subjects: { id: number; name: string; teachers: Person[]; cells: Record<string, Cell> }[]
  all_teachers: Person[]
  all_subjects: { id: number; name: string; teachers: Person[] }[]
  has_subject_data: boolean
  gaps: number
}

const overview = ref<Overview | null>(null)
const klass = ref('S.1')
const grid = ref<Grid | null>(null)
const syncing = ref(false)
const saving = ref<string | null>(null)
const q = ref('')
const adding = ref<number | ''>('')

async function loadOverview() {
  overview.value = await api.get<Overview>('/admin/teachers')
}
async function loadGrid() {
  grid.value = null
  grid.value = await api.get<Grid>('/admin/teaching', { class: klass.value })
}
onMounted(() => Promise.all([loadOverview(), loadGrid()]))
watch(klass, loadGrid)

async function sync() {
  syncing.value = true
  try {
    const r = await api.post<{ message: string }>('/admin/teachers/sync')
    toast(r.message)
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'The sync failed.', 'error')
  } finally {
    syncing.value = false
    await Promise.all([loadOverview(), loadGrid()])
  }
}

const nameOf = computed(() => Object.fromEntries((grid.value?.all_teachers ?? []).map((t) => [t.id, t.name])))
const others = (subject: Grid['subjects'][number]) => grid.value!.all_teachers.filter((t) => !subject.teachers.some((s) => s.id === t.id))
const current = (cell: Cell) => (cell.teachers[0]?.teacher_id ?? '') as number | ''

async function setCell(subjectId: number, stream: string, value: string) {
  const key = `${subjectId}|${stream}`
  saving.value = key
  try {
    const r = await api.post<{ gaps: number }>('/admin/teaching', { class: klass.value, stream, subject_id: subjectId, teacher_ids: value ? [Number(value)] : [] })
    const row = grid.value!.subjects.find((s) => s.id === subjectId)!
    row.cells[stream].teachers = value ? [{ teacher_id: Number(value), source: 'admin' }] : []
    grid.value!.gaps = r.gaps
    if (overview.value) overview.value.gaps = r.gaps
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not save.', 'error')
  } finally {
    saving.value = null
  }
}

async function fillClass(subjectId: number, value: string) {
  if (!value) return
  const r = await api.post<{ gaps: number }>('/admin/teaching/fill-class', { class: klass.value, subject_id: subjectId, teacher_ids: [Number(value)] })
  toast(`${nameOf.value[Number(value)]} set for every ${klass.value} stream.`)
  if (overview.value) overview.value.gaps = r.gaps
  await loadGrid()
}

/** An elective Tusome doesn't record for this class: add its row, then pick teachers */
function addSubject() {
  const s = grid.value?.all_subjects.find((x) => x.id === adding.value)
  if (!s || !grid.value) return
  grid.value.subjects.push({ ...s, cells: Object.fromEntries(grid.value.streams.map((st) => [st.stream, { takers: 0, teachers: [] }])) })
  grid.value.subjects.sort((a, b) => a.name.localeCompare(b.name))
  adding.value = ''
}
const missingSubjects = computed(() => grid.value?.all_subjects.filter((s) => !grid.value!.subjects.some((x) => x.id === s.id)) ?? [])

const classGaps = computed(() => {
  let n = 0
  for (const s of grid.value?.subjects ?? []) for (const c of Object.values(s.cells)) if (c.takers > 0 && !c.teachers.length) n++
  return n
})
const shownTeachers = computed(() => {
  const term = q.value.trim().toLowerCase()
  return (overview.value?.teachers ?? []).filter((t) => !term || t.name.toLowerCase().includes(term) || (t.subjects ?? '').toLowerCase().includes(term))
})
</script>

<template>
  <PageHeader title="Teachers" subtitle="Who teaches each subject in each stream - used for teacher polls. Teachers never see any results." />

  <!-- Tusome -->
  <section class="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Teachers from Tusome</h2>
      <p class="mt-1 text-sm text-gray-600">
        Brings in Tusome's teachers, their subjects, and which subjects each student takes. A subject with only one teacher is filled in for you; your choices below are never changed by a sync.
      </p>
      <p v-if="overview && !overview.sync.configured" class="mt-2 text-sm text-red-700 dark:text-red-400">The link to Tusome is not set up yet (TUSOME_TEACHING_API_URL and TUSOME_API_KEY in backend/.env).</p>
      <p v-else-if="overview?.sync.last" class="mt-2 text-xs" :class="overview.sync.last.status === 'failed' ? 'text-red-700 dark:text-red-400' : 'text-gray-500'">
        Last sync {{ formatDate(overview.sync.last.finished_at ?? overview.sync.last.started_at) }}: {{ overview.sync.last.message ?? 'running…' }}
      </p>
    </div>
    <button class="btn-primary shrink-0" :disabled="syncing || !overview?.sync.configured" @click="sync">
      <ArrowPathIcon class="h-4 w-4" :class="syncing ? 'animate-spin' : ''" /> {{ syncing ? 'Syncing…' : 'Sync from Tusome' }}
    </button>
  </section>

  <!-- Teaching list -->
  <section class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
      <div>
        <h2 class="font-semibold">Teaching list</h2>
        <p class="text-sm text-gray-600">The teacher of each subject in each stream is already picked for students. Where nobody is listed, students choose their teacher from the department.</p>
        <p v-if="!session.isSuper" class="mt-1 text-xs font-medium text-gold-700">Only a super admin can change this list.</p>
      </div>
      <p v-if="overview" class="chip" :class="overview.gaps ? 'bg-gold-100 text-gold-700' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'">
        {{ overview.gaps ? `${overview.gaps} still need a teacher (whole school)` : 'Complete' }}
      </p>
    </div>
    <div class="flex gap-1 overflow-x-auto border-b border-line px-4 pt-3" role="tablist">
      <button
        v-for="c in CLASSES"
        :key="c"
        role="tab"
        :aria-selected="klass === c"
        class="rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold"
        :class="klass === c ? 'border-indigo-700 text-indigo-800 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-ink'"
        @click="klass = c"
      >
        {{ c }}
      </button>
    </div>

    <div v-if="!grid" class="p-10 text-center text-sm text-gray-500">Loading…</div>
    <div v-else-if="!grid.subjects.length && !grid.all_subjects.length" class="p-10 text-center text-sm text-gray-500">Sync from Tusome first to bring in teachers and subjects.</div>
    <template v-else>
      <p v-if="classGaps" class="flex items-center gap-2 bg-gold-50 px-4 py-2 text-sm text-gold-700">
        <ExclamationTriangleIcon class="h-4 w-4" /> {{ classGaps }} subject{{ classGaps === 1 ? '' : 's' }} in {{ klass }} need a teacher (highlighted).
      </p>
      <div class="overflow-x-auto">
        <table class="w-full min-w-max text-sm">
          <thead class="table-head">
            <tr>
              <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2">Subject</th>
              <th v-for="s in grid.streams" :key="s.stream" class="px-2 py-2 text-center">{{ klass }} {{ s.stream }}<div class="font-normal normal-case text-gray-400">{{ s.students }} students</div></th>
              <th class="px-3 py-2">Same teacher for all streams</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="sub in grid.subjects" :key="sub.id">
              <td class="sticky left-0 z-10 bg-surface px-3 py-2 font-semibold">
                {{ sub.name }}
                <div class="text-xs font-normal text-gray-500">{{ sub.teachers.length }} teacher{{ sub.teachers.length === 1 ? '' : 's' }} in Tusome</div>
              </td>
              <td v-for="st in grid.streams" :key="st.stream" class="px-1.5 py-1.5 align-top">
                <div
                  class="rounded-lg p-1"
                  :class="sub.cells[st.stream].takers > 0 && !sub.cells[st.stream].teachers.length ? 'bg-gold-100 dark:bg-gold-700/30' : ''"
                >
                  <select
                    class="input w-40 px-2 py-1 text-xs"
                    :value="current(sub.cells[st.stream])"
                    :disabled="!session.isSuper || saving === `${sub.id}|${st.stream}`"
                    :aria-label="`${sub.name} teacher for ${klass} ${st.stream}`"
                    @change="setCell(sub.id, st.stream, ($event.target as HTMLSelectElement).value)"
                  >
                    <option value="">— nobody —</option>
                    <optgroup :label="`${sub.name} teachers`">
                      <option v-for="t in sub.teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </optgroup>
                    <optgroup label="Other teachers">
                      <option v-for="t in others(sub)" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </optgroup>
                  </select>
                  <div class="mt-0.5 flex justify-between px-1 text-[0.68rem] text-gray-500">
                    <span>{{ sub.cells[st.stream].takers ? `${sub.cells[st.stream].takers} take it` : grid.has_subject_data ? 'not in Tusome' : '' }}</span>
                    <span v-if="sub.cells[st.stream].teachers.length > 1">+{{ sub.cells[st.stream].teachers.length - 1 }} more</span>
                    <span v-else-if="sub.cells[st.stream].teachers[0]?.source === 'auto'" title="Filled in because this subject has only one teacher">auto</span>
                  </div>
                </div>
              </td>
              <td class="px-3 py-1.5 align-top">
                <select class="input w-44 px-2 py-1 text-xs" value="" :disabled="!session.isSuper" @change="fillClass(sub.id, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
                  <option value="">Choose…</option>
                  <option v-for="t in sub.teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="missingSubjects.length" class="flex flex-wrap items-center gap-2 border-t border-line p-4 text-sm">
        <span class="text-gray-600">A subject Tusome doesn't list for {{ klass }} (an elective)?</span>
        <select v-model="adding" class="input w-48 py-1.5">
          <option value="">Choose a subject…</option>
          <option v-for="s in missingSubjects" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <button class="btn-secondary py-1.5" :disabled="!adding" @click="addSubject"><PlusIcon class="h-4 w-4" /> Add row</button>
        <span class="w-full text-xs text-gray-500">Everyone in a stream rates a teacher you list for a subject Tusome doesn't record there; students who don't take it can say "Not my teacher".</span>
      </div>
    </template>
  </section>

  <!-- All teachers -->
  <section class="card mt-6 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
      <h2 class="font-semibold">All teachers <span class="font-normal text-gray-500">({{ overview?.teachers.filter((t) => t.status === 'active').length ?? 0 }})</span></h2>
      <div class="relative w-64">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
        <input v-model="q" class="input pl-9" placeholder="Name or subject" />
      </div>
    </div>
    <div class="max-h-[28rem] overflow-y-auto">
      <table class="w-full text-sm">
        <thead class="table-head sticky top-0"><tr><th class="px-4 py-2">Teacher</th><th class="px-4 py-2">Subjects (Tusome)</th><th class="px-4 py-2 text-right">Streams on the list</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-for="t in shownTeachers" :key="t.id" :class="t.status === 'gone' ? 'opacity-50' : ''">
            <td class="px-4 py-2"><div class="font-medium">{{ t.name }}</div><div class="text-xs text-gray-500">{{ t.employee_number }}<template v-if="t.status === 'gone'"> · no longer in Tusome</template></div></td>
            <td class="px-4 py-2 text-gray-600">{{ t.subjects ?? '—' }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ t.streams }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
