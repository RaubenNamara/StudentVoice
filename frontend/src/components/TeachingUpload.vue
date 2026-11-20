<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowUpTrayIcon, CheckCircleIcon, DocumentArrowUpIcon, ExclamationTriangleIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/api'
import { toast } from '@/feedback'
import { readTeachingFile, type ImportRow } from '@/teachingImport'

/**
 * Teachers > Upload from Excel: read the file here, show what it would do (matched teachers, new
 * ones, anything unclear, streams where several teachers share a subject), then apply.
 */
interface Teacher {
  row: number
  name: string
  match: { id: number; name: string; how: 'exact' | 'reordered' | 'close' } | null
  subjects: string[]
  streams: number
  placed: number
  note: string | null
  skip: boolean
}
interface Preview {
  teachers: Teacher[]
  cells: number
  cells_by_class: Record<string, number>
  changed: number
  streams: number
  unknown_streams: string[]
  shared: { class: string; stream: string; subject: string; teachers: string[] }[]
  summary: { rows: number; matched: number; new: number; unclear: number }
}

const emit = defineEmits<{ done: [] }>()
const input = ref<HTMLInputElement>()
const reading = ref(false)
const busy = ref(false)
const fileName = ref('')
const sheet = ref('')
const rows = ref<ImportRow[]>([])
const preview = ref<Preview | null>(null)
const mode = ref<'replace' | 'fill'>('replace')
const tab = ref<'teachers' | 'new' | 'check' | 'shared'>('teachers')

const newOnes = computed(() => preview.value?.teachers.filter((t) => !t.match && !t.skip) ?? [])
const toCheck = computed(() => preview.value?.teachers.filter((t) => t.skip || t.note || t.match?.how === 'close') ?? [])

async function pick(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  reading.value = true
  fileName.value = file.name
  try {
    const read = await readTeachingFile(file)
    rows.value = read.rows
    sheet.value = read.sheet
    preview.value = await api.post<Preview>('/admin/teaching/import/preview', { rows: read.rows })
    tab.value = 'teachers'
  } catch (err) {
    toast(err instanceof ApiError || err instanceof Error ? err.message : 'Could not read that file.', 'error')
  } finally {
    reading.value = false
    if (input.value) input.value.value = ''
  }
}

async function apply() {
  busy.value = true
  try {
    const r = await api.post<{ written: number; new_teachers: number; gaps: number }>('/admin/teaching/import/apply', { rows: rows.value, mode: mode.value })
    toast(`Teaching list updated: ${r.written} places filled${r.new_teachers ? `, ${r.new_teachers} new teachers added` : ''}.`)
    preview.value = null
    emit('done')
  } catch (err) {
    toast(err instanceof ApiError ? err.message : 'Could not apply the file.', 'error')
  } finally {
    busy.value = false
  }
}

const HOW: Record<string, string> = { exact: 'Same name', reordered: 'Same name, other order', close: 'Close spelling' }
</script>

<template>
  <section class="card mb-6 overflow-hidden">
    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
        <DocumentArrowUpIcon class="h-6 w-6" />
      </div>
      <div class="min-w-0 flex-1">
        <h2 class="font-semibold">Upload from Excel</h2>
        <p class="mt-1 text-sm text-gray-600">
          A sheet of teachers with their subjects and streams (S1A, S2B, S5B1…) fills the Teaching list in one go. You see what it will do before anything changes.
        </p>
        <p class="mt-1 text-xs text-gray-500">Columns it understands: Teacher Name · Department · Subjects Taught · All Streams Taught (or S1-S6 Streams), a ✓ grid of streams, or Teacher · Subject · Class · Stream.</p>
      </div>
      <label class="btn-primary shrink-0 cursor-pointer" :class="reading ? 'pointer-events-none opacity-60' : ''">
        <ArrowUpTrayIcon class="h-4 w-4" /> {{ reading ? 'Reading…' : 'Choose Excel file' }}
        <input ref="input" type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="pick" />
      </label>
    </div>
  </section>

  <Modal v-if="preview" title="Check before applying" wide @close="preview = null">
    <p class="text-sm text-gray-600"><strong>{{ fileName }}</strong> · sheet "{{ sheet }}"</p>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
      <div class="rounded-xl bg-gray-50 p-3"><div class="text-2xl font-semibold tabular-nums">{{ preview.summary.rows }}</div><div class="text-xs text-gray-500">teachers in the file</div></div>
      <div class="rounded-xl bg-indigo-50 p-3 dark:bg-indigo-950/50"><div class="text-2xl font-semibold tabular-nums text-indigo-800 dark:text-indigo-200">{{ preview.summary.matched }}</div><div class="text-xs text-gray-500">found in Tusome</div></div>
      <div class="rounded-xl bg-gold-50 p-3 dark:bg-gold-700/20"><div class="text-2xl font-semibold tabular-nums text-gold-700">{{ preview.summary.new }}</div><div class="text-xs text-gray-500">new (will be added)</div></div>
      <div class="rounded-xl p-3" :class="preview.summary.unclear ? 'bg-red-50 dark:bg-red-950/40' : 'bg-gray-50'"><div class="text-2xl font-semibold tabular-nums" :class="preview.summary.unclear ? 'text-red-700 dark:text-red-400' : ''">{{ preview.summary.unclear }}</div><div class="text-xs text-gray-500">subject unclear (skipped)</div></div>
    </div>
    <p class="mt-3 text-sm text-gray-600">
      {{ preview.cells }} subject places across {{ preview.streams }} streams
      (<span v-for="(n, c, i) in preview.cells_by_class" :key="c">{{ i ? ', ' : '' }}{{ c }} {{ n }}</span>).
      <template v-if="preview.changed"> {{ preview.changed }} differ from the Teaching list now.</template>
    </p>
    <p v-if="preview.unknown_streams.length" class="mt-2 flex items-start gap-2 text-sm text-gold-700"><ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" /> Streams with no students, left out: {{ preview.unknown_streams.join(', ') }}</p>

    <div class="mt-5 flex gap-1 overflow-x-auto border-b border-line" role="tablist">
      <button v-for="t in [{ k: 'teachers', l: `All teachers (${preview.teachers.length})` }, { k: 'new', l: `New (${newOnes.length})` }, { k: 'check', l: `To check (${toCheck.length})` }, { k: 'shared', l: `Shared subjects (${preview.shared.length})` }] as const" :key="t.k" role="tab" :aria-selected="tab === t.k" class="border-b-2 px-3 py-2 text-sm font-semibold" :class="tab === t.k ? 'border-indigo-700 text-indigo-800 dark:text-indigo-300' : 'border-transparent text-gray-500'" @click="tab = t.k">{{ t.l }}</button>
    </div>

    <div class="max-h-[22rem] overflow-y-auto">
      <table v-if="tab !== 'shared'" class="w-full text-sm">
        <thead class="table-head sticky top-0"><tr><th class="px-2 py-2">In the file</th><th class="px-2 py-2">Teacher here</th><th class="px-2 py-2">Subjects</th><th class="px-2 py-2 text-right">Streams</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-for="t in tab === 'new' ? newOnes : tab === 'check' ? toCheck : preview.teachers" :key="t.row" :class="t.skip ? 'bg-red-50/60 dark:bg-red-950/20' : ''">
            <td class="px-2 py-2 font-medium">{{ t.name }}</td>
            <td class="px-2 py-2">
              <template v-if="t.match">{{ t.match.name }} <span class="block text-[0.7rem]" :class="t.match.how === 'close' ? 'text-gold-700' : 'text-gray-400'">{{ HOW[t.match.how] }}</span></template>
              <span v-else-if="!t.skip" class="inline-flex items-center gap-1 text-gold-700"><UserPlusIcon class="h-4 w-4" /> New</span>
            </td>
            <td class="px-2 py-2">
              <span v-if="t.subjects.length">{{ t.subjects.join(', ') }}</span>
              <span v-if="t.note" class="block text-[0.7rem]" :class="t.skip ? 'text-red-700 dark:text-red-400' : 'text-gray-500'">{{ t.note }}</span>
            </td>
            <td class="px-2 py-2 text-right tabular-nums">{{ t.streams }}</td>
          </tr>
        </tbody>
      </table>
      <div v-else class="py-2">
        <p class="mb-2 text-xs text-gray-500">These streams have more than one teacher for a subject in the file (shared electives, split groups, or two teachers for one subject). Students there pick which of the listed teachers is theirs. Change any of them later on the Teaching list.</p>
        <ul class="divide-y divide-line text-sm">
          <li v-for="s in preview.shared" :key="s.class + s.stream + s.subject" class="flex gap-3 py-1.5"><span class="w-28 shrink-0 font-medium">{{ s.class }} {{ s.stream }} · {{ s.subject }}</span><span class="text-gray-600">{{ s.teachers.join(', ') }}</span></li>
        </ul>
      </div>
    </div>

    <fieldset class="mt-5 space-y-2 rounded-xl border border-line p-3">
      <legend class="px-1 text-sm font-semibold">How to apply</legend>
      <label class="flex items-start gap-2 text-sm"><input v-model="mode" type="radio" value="replace" class="mt-1" /> <span><strong>Replace</strong> - every stream in the file gets exactly the file's teachers (choices made by hand in those streams are replaced).</span></label>
      <label class="flex items-start gap-2 text-sm"><input v-model="mode" type="radio" value="fill" class="mt-1" /> <span><strong>Only fill gaps</strong> - keep everything already on the list; add the file's teachers where nobody is set.</span></label>
    </fieldset>

    <div class="mt-5 flex justify-end gap-2">
      <button class="btn-secondary" @click="preview = null">Cancel</button>
      <button class="btn-primary" :disabled="busy" @click="apply"><CheckCircleIcon class="h-4 w-4" /> {{ busy ? 'Applying…' : 'Apply to the Teaching list' }}</button>
    </div>
  </Modal>
</template>
