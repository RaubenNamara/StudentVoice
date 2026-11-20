<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import {
  ArrowDownIcon, ArrowUpIcon, ChartBarIcon, ClipboardDocumentListIcon, DocumentDuplicateIcon, LockClosedIcon,
  PlayIcon, PlusIcon, StopIcon, TrashIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Modal from '@/components/Modal.vue'
import StatusChip from '@/components/StatusChip.vue'
import AudiencePicker from '@/components/AudiencePicker.vue'
import { api, ApiError } from '@/api'
import { confirm, formatDate, percent, toast } from '@/feedback'
import { TYPE_LABELS, type PollDetail, type Question, type QuestionType } from '@/types'

type EditQuestion = Question & { _k: number }

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))
const poll = ref<PollDetail | null>(null)
const details = ref({ title: '', intro: '', about: 'school' as 'school' | 'teachers', sample_size: 0, audience: [] as string[], closes_at: '' })
const gaps = ref<number | null>(null)
const questions = ref<EditQuestion[]>([])
const saved = ref('')
const busy = ref(false)
const errors = ref<Record<string, string>>({})
const pasting = ref(false)
const paste = ref({ text: '', type: 'scale' as QuestionType, section: '' })
let key = 1

const scaleCount = computed(() => questions.value.filter((q) => q.type === 'scale').length)
const locked = computed(() => (poll.value?.votes ?? 0) > 0)
const dirty = computed(() => JSON.stringify(strip()) !== saved.value)
const sections = computed(() => [...new Set(questions.value.map((q) => q.section).filter(Boolean))] as string[])

function strip() {
  return questions.value.map(({ _k, ...q }) => ({ ...q, options: q.type === 'choice' || q.type === 'multi' ? q.options : [] }))
}

async function load() {
  const p = await api.get<PollDetail>(`/admin/polls/${id.value}`)
  poll.value = p
  details.value = { title: p.title, intro: p.intro ?? '', about: p.about, sample_size: p.sample_size ?? 0, audience: p.audience ?? [], closes_at: p.closes_at ? p.closes_at.slice(0, 16).replace(' ', 'T') : '' }
  gaps.value = p.about === 'teachers' ? (await api.get<{ gaps: number }>('/admin/teachers')).gaps : null
  questions.value = p.questions.map((q) => ({ ...q, options: [...q.options], _k: key++ }))
  saved.value = JSON.stringify(strip())
}
onMounted(load)
watch(id, load)

// Unsaved questions: ask before leaving
const beforeUnload = (e: BeforeUnloadEvent) => {
  if (dirty.value) e.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
onBeforeRouteLeave(async () => !dirty.value || (await confirm('Leave without saving?', 'Your changes to the questions will be lost.', 'Leave', true)))

function blank(type: QuestionType = 'scale', section: string | null = null): EditQuestion {
  return { _k: key++, prompt: '', type, section, options: type === 'choice' || type === 'multi' ? ['', ''] : [], required: type !== 'text' }
}
function add(type: QuestionType = 'scale') {
  const last = questions.value[questions.value.length - 1]
  questions.value.push(blank(type, last?.section ?? null))
  setTimeout(() => document.querySelector<HTMLTextAreaElement>(`[data-q="${questions.value.length - 1}"]`)?.focus())
}
function move(i: number, by: number) {
  const j = i + by
  if (j < 0 || j >= questions.value.length) return
  const list = [...questions.value]
  ;[list[i], list[j]] = [list[j], list[i]]
  questions.value = list
}
function copy(i: number) {
  const q = questions.value[i]
  questions.value.splice(i + 1, 0, { ...q, id: undefined, options: [...q.options], _k: key++ })
}
function changeType(q: EditQuestion) {
  if ((q.type === 'choice' || q.type === 'multi') && q.options.length < 2) q.options = [...q.options, '', ''].slice(0, Math.max(2, q.options.length))
  if (q.type === 'text') q.required = false
}

/** Many questions at once: one per line (blank lines and numbering like "1." or "a)" are ignored) */
const pasted = computed(() =>
  paste.value.text
    .split(/\r?\n/)
    .map((l) => l.replace(/^\s*(\d+|[a-zA-Z])[.)]\s+/, '').trim())
    .filter(Boolean),
)
function addPasted() {
  const section = paste.value.section.trim() || null
  for (const prompt of pasted.value) questions.value.push({ ...blank(paste.value.type, section), prompt })
  toast(`${pasted.value.length} questions added. Save to keep them.`)
  pasting.value = false
  paste.value.text = ''
}

async function saveDetails() {
  busy.value = true
  try {
    await api.patch(`/admin/polls/${id.value}`, details.value)
    toast('Details saved.')
    await reloadKeepingQuestions()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not save.', 'error')
  } finally {
    busy.value = false
  }
}

async function saveQuestions() {
  errors.value = {}
  busy.value = true
  try {
    await api.post(`/admin/polls/${id.value}/questions`, { questions: strip() })
    toast('Questions saved.')
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      toast(e.message, 'error')
      const first = Object.keys(e.errors)[0]
      if (first) document.getElementById(first)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  } finally {
    busy.value = false
  }
}

async function reloadKeepingQuestions() {
  const keep = dirty.value ? questions.value : null
  await load()
  if (keep) questions.value = keep
}

async function setStatus(status: 'open' | 'closed' | 'draft') {
  if (dirty.value) {
    toast('Save your questions first.', 'error')
    return
  }
  const ask = {
    open: ['Open this poll?', 'Students in the chosen classes can vote straight away. Once someone votes, questions can no longer be added, removed or changed (only reworded).', 'Open poll'],
    closed: ['Close this poll?', 'No more votes will be taken. You can open it again later.', 'Close poll'],
    draft: ['Back to draft?', 'Students will no longer see this poll.', 'Back to draft'],
  }[status]
  if (!(await confirm(ask[0], ask[1], ask[2]))) return
  try {
    await api.post(`/admin/polls/${id.value}/status`, { status })
    toast(status === 'open' ? 'The poll is open.' : status === 'closed' ? 'The poll is closed.' : 'Back to draft.')
    await load()
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not change the poll.', 'error')
  }
}

async function duplicate() {
  const r = await api.post<{ id: number }>(`/admin/polls/${id.value}/duplicate`)
  toast('Copied as a new draft.')
  router.push({ name: 'poll', params: { id: r.id } })
}

async function remove() {
  if (!(await confirm('Delete this poll?', 'The poll and its questions are deleted for good.', 'Delete', true))) return
  try {
    await api.delete(`/admin/polls/${id.value}`)
    saved.value = JSON.stringify(strip())
    toast('Poll deleted.')
    router.push({ name: 'polls' })
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not delete.', 'error')
  }
}
</script>

<template>
  <div v-if="!poll" class="space-y-4">
    <div class="h-10 w-1/2 animate-pulse rounded bg-gray-100" />
    <div class="card h-48 animate-pulse" />
  </div>

  <template v-else>
    <PageHeader :title="poll.title">
      <template #crumb>
        <RouterLink :to="{ name: 'polls' }" class="text-sm text-gray-500 hover:text-ink">← Polls</RouterLink>
        <div class="mb-1 mt-2"><StatusChip :status="poll.status" :accepting="poll.accepting" /></div>
      </template>
      <RouterLink v-if="poll.votes && poll.about === 'teachers'" :to="{ name: 'teacher-results', params: { id: poll.id } }" class="btn-secondary"><ChartBarIcon class="h-4 w-4" /> Teacher results</RouterLink>
      <RouterLink v-else-if="poll.votes" :to="{ name: 'results', params: { id: poll.id } }" class="btn-secondary"><ChartBarIcon class="h-4 w-4" /> Results</RouterLink>
      <button v-if="poll.status !== 'open'" class="btn-primary" @click="setStatus('open')"><PlayIcon class="h-4 w-4" /> {{ poll.status === 'closed' ? 'Open again' : 'Open poll' }}</button>
      <button v-else class="btn-secondary" @click="setStatus('closed')"><StopIcon class="h-4 w-4" /> Close poll</button>
    </PageHeader>

    <div v-if="poll.about === 'teachers' && gaps" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gold-50 p-4 text-sm text-gold-700">
      <span>Each student rates the teachers on the Teaching list for them. {{ gaps }} subject{{ gaps === 1 ? '' : 's' }} across the school still {{ gaps === 1 ? 'has' : 'have' }} no teacher, so those students won't rate one.</span>
      <RouterLink :to="{ name: 'teachers' }" class="btn-secondary">Open the Teaching list</RouterLink>
    </div>

    <div v-if="poll.status !== 'draft'" class="mb-6 card flex flex-wrap items-center gap-x-6 gap-y-2 p-4 text-sm">
      <span><strong class="tabular-nums">{{ poll.votes.toLocaleString() }}</strong> of {{ poll.eligible.toLocaleString() }} students have voted ({{ percent(poll.votes, poll.eligible) }}%)</span>
      <span v-if="poll.opened_at" class="text-gray-500">Opened {{ formatDate(poll.opened_at) }}</span>
      <span v-if="poll.closed_at" class="text-gray-500">Closed {{ formatDate(poll.closed_at) }}</span>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
      <!-- Details -->
      <section class="card h-fit space-y-4 p-5 lg:sticky lg:top-20">
        <h2 class="font-semibold">Details</h2>
        <div>
          <label class="label" for="title">Title</label>
          <input id="title" v-model="details.title" class="input" maxlength="190" />
        </div>
        <div>
          <label class="label" for="intro">Note to students</label>
          <textarea id="intro" v-model="details.intro" class="input min-h-[6rem]" />
        </div>
        <div>
          <span class="label">This poll is about</span>
          <div class="flex rounded-lg border border-line p-0.5 text-sm" role="radiogroup">
            <button
              v-for="o in [{ v: 'school', t: 'The school' }, { v: 'teachers', t: 'Each teacher' }] as const"
              :key="o.v"
              type="button"
              role="radio"
              :aria-checked="details.about === o.v"
              :disabled="locked"
              class="flex-1 rounded-md px-3 py-1.5 font-medium disabled:cursor-not-allowed"
              :class="details.about === o.v ? 'bg-indigo-700 text-white' : 'text-gray-600'"
              @click="details.about = o.v"
            >
              {{ o.t }}
            </button>
          </div>
          <p class="mt-1.5 text-xs text-gray-500">{{ details.about === 'teachers' ? 'Each student answers once for each of their teachers (from the Teaching list).' : 'Each student answers once.' }}</p>
        </div>
        <div v-if="details.about === 'teachers'">
          <label class="label" for="sample">Statements per teacher</label>
          <select id="sample" v-model.number="details.sample_size" class="input">
            <option :value="0">All {{ scaleCount }}</option>
            <option v-for="n in [5, 6, 8, 10, 12]" v-show="n < scaleCount" :key="n" :value="n">{{ n }} (a different mix for each student)</option>
          </select>
          <p class="mt-1.5 text-xs text-gray-500">Fewer statements keep it quick. Each student gets a random mix across the sections, so every statement is still answered by many students and the averages stay fair.</p>
        </div>
        <div>
          <span class="label">Who votes</span>
          <AudiencePicker v-model="details.audience" />
        </div>
        <div>
          <label class="label" for="closes">Closes automatically <span class="font-normal text-gray-500">(optional)</span></label>
          <input id="closes" v-model="details.closes_at" type="datetime-local" class="input" />
        </div>
        <button class="btn-primary w-full" :disabled="busy" @click="saveDetails">Save details</button>
        <div class="flex flex-wrap gap-2 border-t border-line pt-4">
          <button class="btn-ghost px-2 text-xs" @click="duplicate"><DocumentDuplicateIcon class="h-4 w-4" /> Make a copy</button>
          <button v-if="poll.status === 'open' && !poll.votes" class="btn-ghost px-2 text-xs" @click="setStatus('draft')">Back to draft</button>
          <button v-if="!poll.votes" class="btn-ghost px-2 text-xs text-red-700 dark:text-red-400" @click="remove"><TrashIcon class="h-4 w-4" /> Delete</button>
        </div>
      </section>

      <!-- Questions -->
      <section class="lg:col-span-2">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Questions <span class="font-normal text-gray-500">({{ questions.length }})</span></h2>
          <button v-if="!locked" class="btn-secondary" @click="pasting = true"><ClipboardDocumentListIcon class="h-4 w-4" /> Paste many questions</button>
        </div>

        <p v-if="locked" class="mb-4 flex items-start gap-2 rounded-xl bg-gold-50 p-3 text-sm text-gold-700">
          <LockClosedIcon class="mt-0.5 h-4 w-4 shrink-0" />
          Students have voted, so questions can only be reworded. To add, remove or change questions, make a copy of the poll.
        </p>

        <div v-if="!questions.length" class="card px-6 py-12 text-center">
          <p class="font-medium">No questions yet</p>
          <p class="mt-1 text-sm text-gray-500">Add them one at a time, or paste a list.</p>
        </div>

        <div class="space-y-3">
          <div
            v-for="(q, i) in questions"
            :id="`q${i}`"
            :key="q._k"
            class="card p-4"
            :class="errors[`q${i}`] ? 'border-red-300 ring-2 ring-red-200 dark:border-red-800 dark:ring-red-900' : ''"
          >
            <div class="flex items-start gap-3">
              <span class="mt-2 w-6 shrink-0 text-right text-sm font-semibold text-gray-400">{{ i + 1 }}</span>
              <div class="min-w-0 flex-1 space-y-3">
                <textarea v-model="q.prompt" :data-q="i" rows="2" class="input resize-y font-medium" placeholder="Write the question or statement" />
                <div class="grid gap-3 sm:grid-cols-2">
                  <div>
                    <label class="mb-1 block text-xs font-semibold text-gray-500">Answer type</label>
                    <select v-model="q.type" class="input" :disabled="locked" @change="changeType(q)">
                      <option v-for="(label, t) in TYPE_LABELS" :key="t" :value="t">{{ label }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-semibold text-gray-500">Section heading <span class="font-normal">(optional)</span></label>
                    <input v-model="q.section" class="input" list="sections" placeholder="e.g. Feeding" @input="q.section = q.section || null" />
                  </div>
                </div>
                <div v-if="q.type === 'choice' || q.type === 'multi'" class="space-y-2">
                  <label class="block text-xs font-semibold text-gray-500">Options</label>
                  <div v-for="(_o, oi) in q.options" :key="oi" class="flex items-center gap-2">
                    <span class="flex h-4 w-4 shrink-0 border-2 border-gray-300" :class="q.type === 'choice' ? 'rounded-full' : 'rounded'" />
                    <input v-model="q.options[oi]" class="input py-1.5" :placeholder="`Option ${oi + 1}`" :disabled="locked" maxlength="200" />
                    <button v-if="!locked && q.options.length > 2" class="btn-ghost px-1.5" aria-label="Remove option" @click="q.options.splice(oi, 1)"><XMarkIcon class="h-4 w-4" /></button>
                  </div>
                  <button v-if="!locked && q.options.length < 20" class="btn-ghost px-2 py-1 text-xs" @click="q.options.push('')"><PlusIcon class="h-3.5 w-3.5" /> Add option</button>
                </div>
                <p v-if="q.type === 'scale'" class="text-xs text-gray-500">Students choose 1 Strongly disagree · 2 Disagree · 3 Not sure · 4 Agree · 5 Strongly agree</p>
                <label class="inline-flex items-center gap-2 text-sm" :class="locked ? 'opacity-60' : ''">
                  <input v-model="q.required" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-700" :disabled="locked" />
                  Must be answered
                </label>
                <p v-if="errors[`q${i}`]" class="field-error">{{ errors[`q${i}`] }}</p>
              </div>
              <div v-if="!locked" class="flex shrink-0 flex-col gap-0.5">
                <button class="btn-ghost px-1.5 py-1" aria-label="Move up" :disabled="i === 0" @click="move(i, -1)"><ArrowUpIcon class="h-4 w-4" /></button>
                <button class="btn-ghost px-1.5 py-1" aria-label="Move down" :disabled="i === questions.length - 1" @click="move(i, 1)"><ArrowDownIcon class="h-4 w-4" /></button>
                <button class="btn-ghost px-1.5 py-1" aria-label="Copy question" @click="copy(i)"><DocumentDuplicateIcon class="h-4 w-4" /></button>
                <button class="btn-ghost px-1.5 py-1 text-red-700 dark:text-red-400" aria-label="Delete question" @click="questions.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
              </div>
            </div>
          </div>
        </div>
        <datalist id="sections"><option v-for="s in sections" :key="s" :value="s" /></datalist>

        <div v-if="!locked" class="mt-4 flex flex-wrap gap-2">
          <button v-for="(label, t) in TYPE_LABELS" :key="t" class="btn-secondary text-xs" @click="add(t)"><PlusIcon class="h-3.5 w-3.5" /> {{ label }}</button>
        </div>

        <div class="sticky bottom-0 z-10 -mx-4 mt-6 flex items-center justify-between gap-3 border-t border-line bg-paper/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:bg-surface/95">
          <span class="text-sm" :class="dirty ? 'font-semibold text-gold-700' : 'text-gray-500'">{{ dirty ? 'Unsaved changes' : 'All changes saved' }}</span>
          <button class="btn-primary" :disabled="busy || !dirty" @click="saveQuestions">Save questions</button>
        </div>
      </section>
    </div>

    <Modal v-if="pasting" title="Paste many questions" wide @close="pasting = false">
      <div class="space-y-4">
        <div>
          <label class="label" for="paste">One question per line</label>
          <textarea id="paste" v-model="paste.text" class="input min-h-[12rem] font-mono text-xs" placeholder="The food served is enough.&#10;Meals are served on time.&#10;The dining hall is clean." />
          <p class="mt-1 text-xs text-gray-500">Numbering like “1.” or “a)” at the start of a line is removed.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="ptype">Answer type for all of them</label>
            <select id="ptype" v-model="paste.type" class="input">
              <option v-for="(label, t) in TYPE_LABELS" :key="t" :value="t" :disabled="t === 'choice' || t === 'multi'">{{ label }}{{ t === 'choice' || t === 'multi' ? ' (add one at a time)' : '' }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="psec">Section heading <span class="font-normal text-gray-500">(optional)</span></label>
            <input id="psec" v-model="paste.section" class="input" list="sections" />
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button class="btn-secondary" @click="pasting = false">Cancel</button>
          <button class="btn-primary" :disabled="!pasted.length" @click="addPasted">Add {{ pasted.length || '' }} questions</button>
        </div>
      </div>
    </Modal>
  </template>
</template>
