<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircleIcon, CheckIcon, ChevronRightIcon } from '@heroicons/vue/24/solid'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import StudentShell from '@/layouts/StudentShell.vue'
import QuestionStepper from '@/components/QuestionStepper.vue'
import { api, ApiError } from '@/api'
import { confirm, toast } from '@/feedback'
import { useSession } from '@/stores/session'
import type { Question } from '@/types'

interface Person { id: number; name: string }
interface Subject {
  subject_id: number
  subject: string
  places: number
  done: number
  skipped: boolean
  assigned: Person[]
  options: Person[]
  rated: number[]
  question_ids: number[]
}
interface VotePoll {
  id: number
  title: string
  intro: string | null
  about: 'school' | 'teachers'
  closes_at: string | null
  questions: (Question & { id: number })[]
  subjects?: Subject[]
}

const route = useRoute()
const router = useRouter()
const session = useSession()
const poll = ref<VotePoll | null>(null)
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const serverErrors = ref<Record<string, string>>()
const finished = ref(false)
const moreWaiting = ref(0)

/** Where the student is: the start (school poll) or the subject tiles, a subject, the questions */
const stage = ref<'start' | 'subject' | 'questions'>('start')
const subject = ref<Subject | null>(null)
const teacherId = ref<number | ''>('')
const justDone = ref<number | null>(null)
const round = ref(0)

const isTeachers = computed(() => poll.value?.about === 'teachers')
const subjects = computed(() => poll.value?.subjects ?? [])
const totalPlaces = computed(() => subjects.value.reduce((s, x) => s + x.places, 0))
const donePlaces = computed(() => subjects.value.reduce((s, x) => s + x.done, 0))
const open = (s: Subject) => s.done < s.places

onMounted(async () => {
  try {
    poll.value = await api.get<VotePoll>(`/vote/polls/${route.params.id}`)
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Could not open this poll.'
  } finally {
    loading.value = false
  }
})

/** The questions for what is being answered now (a teacher poll asks each student a set mix) */
const questions = computed(() => {
  if (!poll.value) return []
  if (!isTeachers.value || !subject.value) return poll.value.questions
  const ids = new Set(subject.value.question_ids)
  return poll.value.questions.filter((q) => ids.has(q.id))
})
const teacher = computed(() => subject.value?.options.find((t) => t.id === teacherId.value))
const choosable = computed(() => subject.value?.options.filter((t) => !subject.value!.rated.includes(t.id)) ?? [])
const listed = computed(() => (subject.value?.assigned ?? []).filter((t) => !subject.value!.rated.includes(t.id)))
const changedFromList = computed(() => !!listed.value.length && teacherId.value !== '' && !listed.value.some((t) => t.id === teacherId.value))

function openSubject(s: Subject) {
  subject.value = s
  teacherId.value = listed.value[0]?.id ?? (s.options.length === 1 ? s.options[0].id : '')
  stage.value = 'subject'
  window.scrollTo({ top: 0 })
}
function start() {
  serverErrors.value = undefined
  round.value++
  stage.value = 'questions'
  window.scrollTo({ top: 0 })
}
function backToSubjects() {
  subject.value = null
  stage.value = 'start'
  window.scrollTo({ top: 0 })
}

async function notTaking() {
  const s = subject.value!
  if (!(await confirm(`You don't take ${s.subject}?`, `${s.subject} will be taken off your list for this poll.`, `I don't take ${s.subject}`))) return
  try {
    await api.post(`/vote/polls/${poll.value!.id}/skip`, { subject_id: s.subject_id })
    s.skipped = true
    s.done = s.places
    await afterSubject(s)
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not save. Try again.', 'error')
  }
}

async function send(answers: Record<number, unknown>) {
  if (!poll.value) return
  const s = subject.value
  busy.value = true
  serverErrors.value = undefined
  try {
    await api.post(`/vote/polls/${poll.value.id}`, s ? { answers, subject_id: s.subject_id, teacher_id: teacherId.value } : { answers })
    if (s) {
      s.rated.push(Number(teacherId.value))
      s.done = Math.min(s.places, s.done + 1)
      await afterSubject(s)
    } else {
      await finish()
    }
  } catch (e) {
    if (e instanceof ApiError && e.code === 'already_voted') {
      toast(e.message, 'error')
      if (s) {
        s.done = s.places
        await afterSubject(s)
      } else {
        router.replace({ name: 'vote-polls' })
      }
      return
    }
    if (e instanceof ApiError && Object.keys(e.errors).length) serverErrors.value = e.errors
    toast(e instanceof ApiError ? e.message : 'Could not send your answers. Try again.', 'error')
  } finally {
    busy.value = false
  }
}

/** Back to the subject tiles (with a tick on the one just done), or finished */
async function afterSubject(s: Subject) {
  justDone.value = s.subject_id
  setTimeout(() => (justDone.value = null), 1600)
  backToSubjects()
  if (!subjects.value.some(open)) await finish()
}

async function finish() {
  finished.value = true
  window.scrollTo({ top: 0 })
  // Shared computers (labs, the library): nothing else to answer, so sign out for the next student
  const polls = await api.get<{ accepting: boolean; voted: boolean }[]>('/vote/polls').catch(() => [])
  moreWaiting.value = polls.filter((p) => p.accepting && !p.voted).length
  if (!moreWaiting.value) await session.studentLogout()
}

const initials = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('')
</script>

<template>
  <StudentShell>
    <div v-if="loading" class="space-y-4">
      <div class="h-8 w-2/3 animate-pulse rounded bg-gray-100" />
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3"><div v-for="i in 6" :key="i" class="card h-28 animate-pulse" /></div>
    </div>

    <div v-else-if="loadError" class="card p-8 text-center">
      <p class="text-gray-700">{{ loadError }}</p>
      <RouterLink :to="{ name: 'vote-polls' }" class="btn-primary mt-5">Back to my polls</RouterLink>
    </div>

    <!-- Finished -->
    <div v-else-if="finished" class="card px-6 py-12 text-center">
      <CheckCircleIcon class="mx-auto h-16 w-16 text-emerald-600" />
      <h1 class="mt-4 text-2xl font-semibold">Thank you!</h1>
      <p class="mx-auto mt-2 max-w-sm text-gray-600">Your voice has been counted, without your name. It helps make school better for everyone.</p>
      <template v-if="moreWaiting">
        <p class="mt-4 text-sm font-medium">{{ moreWaiting === 1 ? 'One more poll is' : `${moreWaiting} more polls are` }} waiting for you.</p>
        <RouterLink :to="{ name: 'vote-polls' }" class="btn-primary mt-4">Back to my polls</RouterLink>
      </template>
      <template v-else>
        <p class="mt-4 text-sm text-gray-500">You have been signed out, so the next student can vote on this device.</p>
        <RouterLink :to="{ name: 'vote-login' }" class="btn-primary mt-4">Next student</RouterLink>
      </template>
    </div>

    <template v-else-if="poll">
      <!-- Questions, one at a time -->
      <template v-if="stage === 'questions'">
        <div class="mb-4 flex items-center gap-3">
          <div v-if="isTeachers && teacher" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-indigo-700 text-sm font-semibold text-white">{{ initials(teacher.name) }}</div>
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ isTeachers && teacher ? teacher.name : poll.title }}</p>
            <p v-if="isTeachers && subject" class="text-sm text-gray-500">{{ subject.subject }} · think only about their lessons</p>
          </div>
          <button class="btn-ghost ml-auto shrink-0 text-sm" @click="isTeachers ? (stage = 'subject') : (stage = 'start')">Cancel</button>
        </div>
        <QuestionStepper :key="round" :questions="questions" :busy="busy" :server-errors="serverErrors" @send="send" />
      </template>

      <!-- Teacher poll: choose the teacher for a subject -->
      <template v-else-if="stage === 'subject' && subject">
        <button class="btn-ghost -ml-3 mb-3 px-3 text-sm" @click="backToSubjects"><ArrowLeftIcon class="h-4 w-4" /> My subjects</button>
        <div class="card p-6 sm:p-8">
          <p class="eyebrow text-gold-600">{{ subject.subject }}</p>
          <h1 class="mt-2 text-2xl font-semibold">Who teaches you {{ subject.subject }}?</h1>
          <p v-if="subject.places > 1" class="mt-1 text-sm text-gray-600">You have {{ subject.places }} {{ subject.subject }} teachers; rate each one ({{ subject.done }} done).</p>

          <label for="teacher" class="label mt-6">Your teacher</label>
          <select id="teacher" v-model="teacherId" class="input py-3 text-base">
            <option value="" disabled>Choose your {{ subject.subject }} teacher…</option>
            <optgroup v-if="listed.length" label="Listed for your class">
              <option v-for="t in listed" :key="t.id" :value="t.id">{{ t.name }}</option>
            </optgroup>
            <optgroup :label="listed.length ? `Other ${subject.subject} teachers` : `${subject.subject} teachers`">
              <option v-for="t in choosable.filter((x) => !listed.some((l) => l.id === x.id))" :key="t.id" :value="t.id">{{ t.name }}</option>
            </optgroup>
          </select>
          <p v-if="changedFromList" class="mt-2 text-sm text-gold-700">Thanks - we'll let the school know their list may be wrong.</p>

          <button class="btn-primary mt-6 w-full py-3 text-base" :disabled="teacherId === ''" @click="start">
            Start · {{ questions.filter((q) => q.type !== 'text').length }} quick questions <ChevronRightIcon class="h-5 w-5" />
          </button>
          <button v-if="!subject.rated.length" class="btn-ghost mx-auto mt-3 block text-sm" @click="notTaking">I don't take {{ subject.subject }}</button>
        </div>
      </template>

      <!-- Teacher poll: the subject tiles -->
      <template v-else-if="isTeachers">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ poll.title }}</h1>
        <p class="mt-2 text-gray-600">Pick a subject, choose your teacher, and tap your answers. About a minute each.</p>
        <div class="mt-5 flex items-center gap-3">
          <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100">
            <div class="h-2.5 rounded-full bg-gold-400 transition-all duration-500" :style="{ width: `${totalPlaces ? (donePlaces / totalPlaces) * 100 : 0}%` }" />
          </div>
          <span class="shrink-0 text-sm font-medium text-gray-600">{{ donePlaces }} of {{ totalPlaces }} done</span>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <button
            v-for="s in subjects"
            :key="s.subject_id"
            class="card relative flex min-h-[7.5rem] flex-col items-start justify-between p-4 text-left transition"
            :class="[open(s) ? 'hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-lift' : 'cursor-default bg-gray-50', justDone === s.subject_id ? 'ring-4 ring-emerald-300' : '']"
            :disabled="!open(s)"
            @click="openSubject(s)"
          >
            <span class="text-lg font-semibold">{{ s.subject }}</span>
            <span v-if="s.skipped" class="text-xs text-gray-500">Not taking it</span>
            <span v-else-if="!open(s)" class="chip bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><CheckIcon class="h-3.5 w-3.5" /> Done</span>
            <span v-else class="flex w-full items-end justify-between gap-2">
              <span class="truncate text-xs text-gray-500">{{ s.places > 1 ? `${s.done} of ${s.places} teachers` : s.assigned[0]?.name ?? 'Choose your teacher' }}</span>
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-700 text-white"><ChevronRightIcon class="h-4 w-4" /></span>
            </span>
          </button>
        </div>
        <p class="mt-6 text-center text-xs text-gray-500">Your answers never show your name. Teachers never see who said what.</p>
      </template>

      <!-- School poll: start -->
      <div v-else class="card p-6 text-center sm:p-10">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ poll.title }}</h1>
        <p v-if="poll.intro" class="mx-auto mt-3 max-w-lg whitespace-pre-line text-gray-600">{{ poll.intro }}</p>
        <p class="mt-4 text-sm text-gray-500">{{ poll.questions.length }} questions · one at a time · tap to answer</p>
        <button class="btn-primary mt-6 px-8 py-3 text-base" @click="start">Start</button>
      </div>
    </template>
  </StudentShell>
</template>
