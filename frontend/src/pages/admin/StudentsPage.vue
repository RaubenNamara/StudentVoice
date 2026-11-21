<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowPathIcon, KeyIcon, MagnifyingGlassIcon, PlusIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/api'
import { confirm, formatDate, toast } from '@/feedback'
import { CLASSES, type ClassGroup } from '@/types'
import { useSession } from '@/stores/session'

interface Row {
  id: number
  reg_no: string
  name: string
  class: string
  stream: string
  voter_code: string
  status: 'active' | 'left'
  source: 'smis' | 'manual'
  left_at: string | null
  votes: number
}
interface SyncRun {
  id: number
  started_at: string
  finished_at: string | null
  status: 'running' | 'success' | 'failed'
  message: string | null
  started_by_name: string
}

const classes = ref<ClassGroup[]>([])
const list = ref<{ students: Row[]; total: number; page: number; per_page: number } | null>(null)
const filter = ref({ q: '', class: '', stream: '', status: 'active', page: 1 })
const sync = ref<{ running: boolean; runs: SyncRun[] }>({ running: false, runs: [] })
const syncing = ref(false)
const adding = ref(false)
const form = ref({ reg_no: '', name: '', class: 'S.1', stream: '' })
const formErrors = ref<Record<string, string>>({})
const shown = ref<Record<number, boolean>>({})

const total = computed(() => classes.value.reduce((s, c) => s + c.students, 0))
const streams = computed(() => classes.value.find((c) => c.class === filter.value.class)?.streams ?? [])
const pages = computed(() => (list.value ? Math.max(1, Math.ceil(list.value.total / list.value.per_page)) : 1))

async function loadList() {
  list.value = await api.get('/admin/students', filter.value)
}
async function loadAll() {
  ;[classes.value, sync.value] = await Promise.all([api.get<ClassGroup[]>('/admin/students/classes'), api.get<typeof sync.value>('/admin/students/sync')])
  await loadList()
}
onMounted(loadAll)

let timer: number | undefined
watch(() => filter.value.q, () => {
  clearTimeout(timer)
  timer = window.setTimeout(() => ((filter.value.page = 1), loadList()), 300)
})
watch(() => [filter.value.class, filter.value.stream, filter.value.status], () => ((filter.value.page = 1), loadList()))
watch(() => filter.value.class, () => (filter.value.stream = ''))
watch(() => filter.value.page, loadList)

async function runSync() {
  syncing.value = true
  try {
    const r = await api.post<{ message: string }>('/admin/students/sync')
    toast(r.message)
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'The sync failed.', 'error')
  } finally {
    syncing.value = false
    await loadAll()
  }
}

async function addStudent() {
  formErrors.value = {}
  try {
    const r = await api.post<{ voter_code: string }>('/admin/students', form.value)
    toast(`Added. Voting number ${r.voter_code}`)
    adding.value = false
    form.value = { reg_no: '', name: '', class: form.value.class, stream: form.value.stream }
    await loadAll()
  } catch (e) {
    if (e instanceof ApiError) formErrors.value = { ...e.errors, form: e.message }
  }
}

async function newCode(s: Row) {
  if (!(await confirm(`New voting number for ${s.name}?`, 'The old number stops working at once. Votes already cast still count, and they still cannot vote twice.', 'Give a new number'))) return
  const r = await api.post<{ voter_code: string }>(`/admin/students/${s.id}/new-code`)
  s.voter_code = r.voter_code
  shown.value[s.id] = true
  toast(`New voting number: ${r.voter_code}`)
}

async function setStatus(s: Row, status: 'active' | 'left') {
  await api.post(`/admin/students/${s.id}/status`, { status })
  toast(status === 'left' ? `${s.name} can no longer vote.` : `${s.name} can vote again.`)
  await loadAll()
}

const lastRun = computed(() => sync.value.runs[0])

/** Super admins: every student gets a new voting number (old slips stop working) */
const session = useSession()
const resetting = ref(false)
const resetConfirm = ref('')
const resetBusy = ref(false)
async function resetAll() {
  resetBusy.value = true
  try {
    const r = await api.post<{ students: number }>('/admin/students/new-codes', { confirm: resetConfirm.value })
    toast(`${r.students.toLocaleString()} students have new voting numbers. Print the new slips.`)
    resetting.value = false
    resetConfirm.value = ''
    shown.value = {}
    await loadAll()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not give new numbers.', 'error')
  } finally {
    resetBusy.value = false
  }
}
</script>

<template>
  <PageHeader title="Students" :subtitle="`${total.toLocaleString()} students can vote. Each has their own voting number.`">
    <button v-if="session.isSuper" class="btn-secondary" @click="resetting = true"><KeyIcon class="h-4 w-4" /> New numbers for everyone</button>
    <RouterLink :to="{ name: 'slips' }" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> Print voting slips</RouterLink>
    <button class="btn-secondary" @click="adding = true"><PlusIcon class="h-4 w-4" /> Add a student</button>
  </PageHeader>

  <!-- SMIS -->
  <section class="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Students from SMIS</h2>
      <p class="mt-1 text-sm text-gray-600">
        The same list Tusome uses. New students get a voting number; class changes follow SMIS; students no longer in SMIS can't vote.
        Voting numbers never change on a sync.
      </p>
      <p v-if="lastRun" class="mt-2 text-xs" :class="lastRun.status === 'failed' ? 'text-red-700 dark:text-red-400' : 'text-gray-500'">
        Last sync {{ formatDate(lastRun.finished_at ?? lastRun.started_at) }}<template v-if="lastRun.started_by_name"> by {{ lastRun.started_by_name }}</template>: {{ lastRun.message ?? 'running…' }}
      </p>
    </div>
    <button class="btn-primary shrink-0" :disabled="syncing || sync.running" @click="runSync">
      <ArrowPathIcon class="h-4 w-4" :class="syncing ? 'animate-spin' : ''" /> {{ syncing ? 'Syncing…' : 'Sync from SMIS' }}
    </button>
  </section>

  <!-- Classes -->
  <div class="mb-6 grid grid-cols-3 gap-2 sm:grid-cols-6">
    <button
      v-for="c in classes"
      :key="c.class"
      class="rounded-xl border p-3 text-left transition"
      :class="filter.class === c.class ? 'border-indigo-700 bg-indigo-50 dark:bg-indigo-950/60' : 'border-line bg-surface hover:border-indigo-300'"
      @click="filter.class = filter.class === c.class ? '' : c.class"
    >
      <div class="text-sm font-semibold">{{ c.class }}</div>
      <div class="text-xs text-gray-500">{{ c.students }} · {{ c.streams.length }} streams</div>
    </button>
  </div>

  <!-- Filters -->
  <div class="mb-3 flex flex-wrap gap-2">
    <div class="relative min-w-[14rem] flex-1">
      <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
      <input v-model="filter.q" class="input pl-9" placeholder="Name, registration or voting number" />
    </div>
    <select v-if="filter.class" v-model="filter.stream" class="input w-36">
      <option value="">All streams</option>
      <option v-for="s in streams" :key="s.stream" :value="s.stream">{{ filter.class }} {{ s.stream }}</option>
    </select>
    <select v-model="filter.status" class="input w-40">
      <option value="active">Can vote</option>
      <option value="left">Left school</option>
      <option value="all">Everyone</option>
    </select>
  </div>

  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="table-head">
          <tr>
            <th class="px-4 py-2.5">Student</th>
            <th class="px-4 py-2.5">Class</th>
            <th class="px-4 py-2.5">Voting number</th>
            <th class="px-4 py-2.5 text-right">Polls voted</th>
            <th class="px-4 py-2.5" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-if="!list"><td colspan="5" class="px-4 py-8 text-center text-gray-500">Loading…</td></tr>
          <tr v-else-if="!list.students.length">
            <td colspan="5" class="px-4 py-10 text-center text-gray-500">
              {{ total ? 'No student matches.' : 'No students yet. Sync from SMIS to bring them in.' }}
            </td>
          </tr>
          <tr v-for="s in list?.students" :key="s.id" :class="s.status === 'left' ? 'opacity-60' : ''">
            <td class="px-4 py-2.5">
              <div class="font-medium">{{ s.name }}</div>
              <div class="text-xs text-gray-500">{{ s.reg_no }}<template v-if="s.source === 'manual'"> · added by hand</template><template v-if="s.status === 'left'"> · left {{ formatDate(s.left_at, false) }}</template></div>
            </td>
            <td class="whitespace-nowrap px-4 py-2.5">{{ s.class }} {{ s.stream }}</td>
            <td class="whitespace-nowrap px-4 py-2.5">
              <button v-if="!shown[s.id]" class="text-xs font-semibold text-indigo-700 hover:underline dark:text-indigo-300" @click="shown[s.id] = true">Show</button>
              <span v-else class="font-mono tracking-wider">{{ s.voter_code }}</span>
            </td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ s.votes }}</td>
            <td class="whitespace-nowrap px-4 py-2.5 text-right">
              <button v-if="s.status === 'active'" class="btn-ghost px-2 py-1 text-xs" @click="newCode(s)">New number</button>
              <button v-if="s.source === 'manual'" class="btn-ghost px-2 py-1 text-xs" @click="setStatus(s, s.status === 'active' ? 'left' : 'active')">
                {{ s.status === 'active' ? 'Mark as left' : 'Can vote again' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="list && pages > 1" class="flex items-center justify-between border-t border-line px-4 py-2.5 text-sm">
      <span class="text-gray-500">{{ list.total.toLocaleString() }} students · page {{ list.page }} of {{ pages }}</span>
      <div class="flex gap-2">
        <button class="btn-secondary px-3 py-1" :disabled="filter.page <= 1" @click="filter.page--">Previous</button>
        <button class="btn-secondary px-3 py-1" :disabled="filter.page >= pages" @click="filter.page++">Next</button>
      </div>
    </div>
  </div>

  <Modal v-if="adding" title="Add a student" @close="adding = false">
    <form class="space-y-4" @submit.prevent="addStudent">
      <p class="text-sm text-gray-600">For a student not yet in SMIS. When SMIS has them, the sync takes them over and keeps their voting number.</p>
      <div>
        <label class="label" for="reg">Registration number</label>
        <input id="reg" v-model="form.reg_no" class="input" required />
        <p v-if="formErrors.reg_no" class="field-error">{{ formErrors.reg_no }}</p>
      </div>
      <div>
        <label class="label" for="nm">Name</label>
        <input id="nm" v-model="form.name" class="input" required />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="cl">Class</label>
          <select id="cl" v-model="form.class" class="input"><option v-for="c in CLASSES" :key="c">{{ c }}</option></select>
        </div>
        <div>
          <label class="label" for="st">Stream</label>
          <input id="st" v-model="form.stream" class="input uppercase" maxlength="20" />
        </div>
      </div>
      <p v-if="formErrors.form" class="field-error">{{ formErrors.form }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="adding = false">Cancel</button>
        <button class="btn-primary">Add student</button>
      </div>
    </form>
  </Modal>
  <Modal v-if="resetting" title="New voting numbers for everyone" @close="resetting = false">
    <div class="space-y-3 text-sm text-gray-700">
      <p>Every student gets a <strong>new 6-character voting number</strong> (like K7P-4QX).</p>
      <ul class="list-disc space-y-1 pl-5">
        <li>The old numbers and every printed slip <strong>stop working at once</strong> - print and hand out new slips.</li>
        <li>Votes already cast still count, and nobody can vote twice.</li>
      </ul>
      <label class="label mt-4" for="rc">Type <span class="font-mono">NEW NUMBERS</span> to confirm</label>
      <input id="rc" v-model="resetConfirm" class="input font-mono uppercase" autocomplete="off" />
    </div>
    <div class="mt-5 flex justify-end gap-2">
      <button class="btn-secondary" @click="resetting = false">Cancel</button>
      <button class="btn bg-red-700 text-white hover:bg-red-800" :disabled="resetBusy || resetConfirm.trim().toUpperCase() !== 'NEW NUMBERS'" @click="resetAll">
        {{ resetBusy ? 'Giving new numbers…' : 'Give everyone new numbers' }}
      </button>
    </div>
  </Modal>
</template>
