<script setup lang="ts">
import { ref, watch } from 'vue'
import { ArrowDownTrayIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { formatDate } from '@/feedback'
import { downloadCsv } from '@/scores'

/**
 * The students who have taken part in a poll (or not yet), to chase the rest. Who took part only:
 * never what they answered or which teacher they rated.
 */
interface Student { id: number; name: string; reg_no: string; class: string; stream: string; voted_at: string | null }
interface Page { total: number; page: number; pages: number; students: Student[] }

const props = defineProps<{ pollId: number }>()
const show = defineModel<'voted' | 'not'>('show', { default: 'voted' })
const cls = defineModel<string>('cls', { default: '' })
const stream = defineModel<string>('stream', { default: '' })
const q = ref('')
const page = ref(1)
const data = ref<Page | null>(null)
const busy = ref(false)
const CLASSES = ['S.1', 'S.2', 'S.3', 'S.4', 'S.5', 'S.6']

const params = () => ({ show: show.value, class: cls.value, stream: stream.value, q: q.value.trim() })
async function load() {
  busy.value = true
  try {
    data.value = await api.get<Page>(`/admin/polls/${props.pollId}/voters`, { ...params(), page: page.value })
  } finally {
    busy.value = false
  }
}
let timer: ReturnType<typeof setTimeout> | undefined
watch([() => props.pollId, show, cls, stream], () => { page.value = 1; load() }, { immediate: true })
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 300) })
watch(page, load)

/** Every matching student (all pages) as a CSV, e.g. a class teacher's "not yet voted" list */
async function exportCsv() {
  const rows: Student[] = []
  for (let p = 1; ; p++) {
    const r = await api.get<Page>(`/admin/polls/${props.pollId}/voters`, { ...params(), page: p })
    rows.push(...r.students)
    if (p >= r.pages) break
  }
  const label = show.value === 'voted' ? 'voted' : 'not-yet-voted'
  downloadCsv(`students-${label}${cls.value ? '-' + cls.value + stream.value : ''}`, [
    ['Name', 'Reg no', 'Class', 'Stream', ...(show.value === 'voted' ? ['Took part'] : [])],
    ...rows.map((s) => [s.name, s.reg_no, s.class, s.stream, ...(show.value === 'voted' ? [s.voted_at ?? ''] : [])]),
  ])
}
</script>

<template>
  <section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="font-semibold">Students</h2>
        <p class="text-xs text-gray-500">Who has taken part. Their answers stay anonymous.</p>
      </div>
      <div class="inline-flex rounded-lg border border-line p-0.5 text-sm" role="tablist">
        <button v-for="o in [{ v: 'voted', t: 'Voted' }, { v: 'not', t: 'Not yet voted' }] as const" :key="o.v" type="button" role="tab" :aria-selected="show === o.v"
          class="rounded-md px-3 py-1.5 font-medium" :class="show === o.v ? 'bg-indigo-700 text-white' : 'text-gray-600 hover:text-ink'" @click="show = o.v">{{ o.t }}</button>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
      <label class="relative min-w-[12rem] flex-1">
        <span class="sr-only">Search by name or reg no</span>
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
        <input v-model="q" class="input pl-9" placeholder="Name or reg no" />
      </label>
      <select v-model="cls" class="input w-auto" aria-label="Class" @change="stream = ''">
        <option value="">All classes</option>
        <option v-for="c in CLASSES" :key="c" :value="c">{{ c }}</option>
      </select>
      <input v-model.trim="stream" class="input w-24 uppercase" placeholder="Stream" aria-label="Stream" maxlength="20" />
      <button type="button" class="btn-ghost" :disabled="!data?.total" @click="exportCsv"><ArrowDownTrayIcon class="h-4 w-4" /> CSV</button>
    </div>

    <p class="mt-3 text-sm text-gray-600" aria-live="polite">
      <template v-if="data"><strong class="tabular-nums">{{ data.total.toLocaleString() }}</strong> {{ show === 'voted' ? 'have voted' : 'have not voted yet' }}<template v-if="cls"> in {{ cls }}{{ stream }}</template></template>
    </p>

    <div class="mt-2 overflow-x-auto" :class="busy ? 'opacity-60' : ''">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-gray-500">
            <th class="py-2 pr-3">Name</th>
            <th class="py-2 pr-3">Reg no</th>
            <th class="py-2 pr-3">Class</th>
            <th v-if="show === 'voted'" class="py-2 text-right">Took part</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in data?.students ?? []" :key="s.id" class="border-b border-line/60 last:border-0">
            <td class="py-2 pr-3 font-medium">{{ s.name }}</td>
            <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ s.reg_no }}</td>
            <td class="py-2 pr-3 whitespace-nowrap">{{ s.class }}{{ s.stream }}</td>
            <td v-if="show === 'voted'" class="py-2 text-right text-xs whitespace-nowrap text-gray-500">{{ formatDate(s.voted_at) }}</td>
          </tr>
          <tr v-if="data && !data.students.length">
            <td :colspan="show === 'voted' ? 4 : 3" class="py-8 text-center text-gray-500">{{ show === 'voted' ? 'Nobody here has voted yet.' : 'Everyone here has voted.' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="data && data.pages > 1" class="mt-3 flex items-center justify-between text-sm">
      <button type="button" class="btn-ghost" :disabled="page <= 1" @click="page--">Previous</button>
      <span class="text-gray-500 tabular-nums">Page {{ data.page }} of {{ data.pages }}</span>
      <button type="button" class="btn-ghost" :disabled="page >= data.pages" @click="page++">Next</button>
    </div>
  </section>
</template>
