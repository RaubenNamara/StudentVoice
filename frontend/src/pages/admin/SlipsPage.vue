<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowDownTrayIcon, PrinterIcon, ScissorsIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import PrintHeader from '@/components/PrintHeader.vue'
import { api } from '@/api'
import { toast } from '@/feedback'
import { useSession } from '@/stores/session'
import type { ClassGroup } from '@/types'

interface Slip {
  reg_no: string
  name: string
  class: string
  stream: string
  voter_code: string
}

const session = useSession()
const classes = ref<ClassGroup[]>([])
const pick = ref({ class: 'S.1', stream: '' })
const slips = ref<Slip[]>([])
const loading = ref(false)
const layout = ref<'slips' | 'list'>('slips')
const voteUrl = computed(() => window.location.origin + import.meta.env.BASE_URL)
const streams = computed(() => classes.value.find((c) => c.class === pick.value.class)?.streams ?? [])
const allClasses = computed(() => pick.value.class === 'all')

onMounted(async () => {
  classes.value = await api.get<ClassGroup[]>('/admin/students/classes')
  if (classes.value.length) pick.value.class = classes.value[0].class
  await load()
})
watch(() => pick.value.class, () => (pick.value.stream = ''))
watch(pick, load, { deep: true })

async function load() {
  loading.value = true
  try {
    slips.value = (await api.get<{ students: Slip[] }>('/admin/students/slips', pick.value)).students
  } finally {
    loading.value = false
  }
}

const byName = (a: Slip, b: Slip) => a.name.localeCompare(b.name, 'en', { sensitivity: 'base' })

/** One group per class and stream, students A-Z by name */
const groups = computed(() => {
  const map = new Map<string, Slip[]>()
  for (const s of slips.value) {
    const k = `${s.class}|${s.stream}`
    map.set(k, [...(map.get(k) ?? []), s])
  }
  return [...map.entries()]
    .sort(([a], [b]) => a.localeCompare(b, 'en', { numeric: true }))
    .map(([k, list]) => {
      const [klass, stream] = k.split('|')
      return { key: k, klass, stream, label: `${klass} ${stream}`.trim(), students: [...list].sort(byName) }
    })
})
/** Slips in the same order as the lists: by stream, then A-Z */
const orderedSlips = computed(() => groups.value.flatMap((g) => g.students))

const scope = computed(() => (allClasses.value ? 'whole school' : `${pick.value.class}${pick.value.stream ? ' ' + pick.value.stream : ''}`))

/** One sheet per stream: #, Name, Voter ID */
async function downloadExcel() {
  try {
    const XLSX = await import('xlsx')
    const wb = XLSX.utils.book_new()
    for (const g of groups.value) {
      const rows: (string | number)[][] = [
        [session.school || "St. Mark's College Namagoma"],
        [`Student Voice · voting numbers · ${g.label} · ${g.students.length} students`],
        [`Vote at ${voteUrl.value}`],
        [],
        ['#', 'Name', 'Voter ID'],
        ...g.students.map((s, i) => [i + 1, s.name, s.voter_code]),
      ]
      const ws = XLSX.utils.aoa_to_sheet(rows)
      ws['!cols'] = [{ wch: 5 }, { wch: 38 }, { wch: 14 }]
      XLSX.utils.book_append_sheet(wb, ws, g.label.replace(/[\\/?*[\]:]/g, '').slice(0, 31) || 'Stream')
    }
    XLSX.writeFile(wb, `Voting numbers - ${scope.value}.xlsx`)
  } catch {
    toast('Could not make the Excel file.', 'error')
  }
}
const print = () => window.print()
</script>

<template>
  <div class="force-light min-h-screen bg-white text-[rgb(24,24,38)]">
    <div class="no-print sticky top-0 z-10 border-b border-gray-200 bg-white/95 backdrop-blur">
      <div class="mx-auto flex max-w-5xl flex-wrap items-end gap-3 px-4 py-3">
        <RouterLink :to="{ name: 'students' }" class="btn-ghost mr-auto">← Students</RouterLink>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="c">Class</label>
          <select id="c" v-model="pick.class" class="input w-36">
            <option v-for="c in classes" :key="c.class">{{ c.class }}</option>
            <option value="all">All classes</option>
          </select>
        </div>
        <div v-if="!allClasses">
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="s">Stream</label>
          <select id="s" v-model="pick.stream" class="input w-36">
            <option value="">All streams</option>
            <option v-for="s in streams" :key="s.stream" :value="s.stream">{{ s.stream }} ({{ s.students }})</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="l">Print as</label>
          <select id="l" v-model="layout" class="input w-56">
            <option value="slips">Slips to cut out</option>
            <option value="list">Lists by stream (Name, Voter ID)</option>
          </select>
        </div>
        <button class="btn-secondary" :disabled="!slips.length" @click="downloadExcel"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</button>
        <button class="btn-primary" :disabled="!slips.length" @click="print"><PrinterIcon class="h-4 w-4" /> Print {{ slips.length }}</button>
      </div>
      <p class="mx-auto max-w-5xl px-4 pb-3 text-xs text-gray-500">
        Keep these safe: anyone holding a student's voter ID can vote as that student. If one is lost or seen by others, give that student a new number on the Students page.
        Excel gives one sheet per stream.
      </p>
    </div>

    <p v-if="loading" class="py-16 text-center text-gray-500">Loading…</p>
    <p v-else-if="!slips.length" class="py-16 text-center text-gray-500">No students here.</p>

    <!-- Slips: 10 to an A4 page, by stream then A-Z -->
    <div v-else-if="layout === 'slips'" class="mx-auto grid max-w-5xl grid-cols-2 gap-0 p-4 print:p-0">
      <div v-for="s in orderedSlips" :key="s.reg_no" class="slip break-inside-avoid border border-dashed border-gray-300 p-4">
        <div class="flex items-center gap-2">
          <BrandMark :size="28" />
          <div class="min-w-0 leading-tight">
            <div class="truncate text-[10px] font-semibold uppercase tracking-wide text-indigo-900">{{ session.school }}</div>
            <div class="text-[9px] text-gray-500">Student Voice · voting slip</div>
          </div>
          <ScissorsIcon class="ml-auto h-3.5 w-3.5 text-gray-300" />
        </div>
        <div class="mt-2 truncate text-sm font-semibold">{{ s.name }}</div>
        <div class="text-[11px] text-gray-500">{{ s.class }} {{ s.stream }} · {{ s.reg_no }}</div>
        <div class="mt-2 rounded-md bg-gray-100 py-1.5 text-center font-mono text-xl font-semibold tracking-[0.25em]">{{ s.voter_code }}</div>
        <div class="mt-1.5 text-[9px] leading-snug text-gray-500">Go to <b>{{ voteUrl }}</b> and type this voting number. Keep it secret. Your answers never show your name.</div>
      </div>
    </div>

    <!-- Lists: one table per stream, A-Z, each stream starting a new printed page -->
    <div v-else class="mx-auto max-w-3xl p-4 print:max-w-none print:p-0">
      <section v-for="(g, gi) in groups" :key="g.key" class="stream-list" :class="gi ? 'mt-10 print:mt-0' : ''">
        <PrintHeader always :title="`Voting numbers · ${g.label}`" :subtitle="`${g.students.length} students · vote at ${voteUrl}`" />
        <table class="w-full border-collapse text-sm">
          <thead>
            <tr class="bg-indigo-900 text-left text-xs uppercase tracking-wider text-white">
              <th class="w-12 border border-indigo-900 px-2 py-2 text-right">#</th>
              <th class="border border-indigo-900 px-3 py-2">Name</th>
              <th class="w-40 border border-indigo-900 px-3 py-2">Voter ID</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(s, i) in g.students" :key="s.reg_no" class="break-inside-avoid even:bg-gray-50">
              <td class="border border-gray-300 px-2 py-1.5 text-right tabular-nums text-gray-500 print:py-[3px]">{{ i + 1 }}</td>
              <td class="border border-gray-300 px-3 py-1.5 print:py-[3px]">{{ s.name }}</td>
              <td class="border border-gray-300 px-3 py-1.5 font-mono font-semibold tracking-wider print:py-[3px]">{{ s.voter_code }}</td>
            </tr>
          </tbody>
        </table>
        <p class="mt-2 text-[10px] text-gray-500">{{ g.label }} · {{ g.students.length }} students · sorted A-Z · keep this list safe</p>
      </section>
    </div>
  </div>
</template>

<style scoped>
@media print {
  @page { size: A4; margin: 10mm; }
  /* 2 x 5 slips per page */
  .slip { height: 55mm; }
  /* every stream starts on a new page; the table header repeats on each page */
  .stream-list + .stream-list { break-before: page; }
  thead { display: table-header-group; }
}
</style>
