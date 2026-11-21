<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckIcon, ChevronRightIcon } from '@heroicons/vue/24/solid'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import StudentShell from '@/layouts/StudentShell.vue'
import QuestionStepper from '@/components/QuestionStepper.vue'
import { api, ApiError } from '@/api'
import { confirm, toast } from '@/feedback'
import { useSession } from '@/stores/session'
import type { Question } from '@/types'
import Confetti from '@/components/Confetti.vue'
import { subjectLook } from '@/subjects'

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
  elective: boolean
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
// progress is by subject (one with several listed teachers is a single step), counting the
// student's own subjects; electives are optional
const core = computed(() => subjects.value.filter((x) => !x.elective))
const electives = computed(() => subjects.value.filter((x) => x.elective))
const totalPlaces = computed(() => core.value.length)
const donePlaces = computed(() => core.value.filter((x) => !open(x)).length)
const coreDone = computed(() => !core.value.some(open))
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
/** The teacher is set by the school: no choosing, just rate them */
const fixed = computed(() => !!subject.value?.assigned.length)
const look = (code: string) => subjectLook(code)

function openSubject(s: Subject) {
  subject.value = s
  teacherId.value = listed.value[0]?.id ?? (s.options.length === 1 ? s.options[0].id : '')
  stage.value = 'subject'
  window.scrollTo({ top: 0 })
}
/** A listed teacher (one of several listed): straight to their questions */
function rateListed(t: Person) {
  teacherId.value = t.id
  start()
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
  const name = subjectLook(s.subject).name
  const ask = s.rated.length
    ? [`Done with ${name}?`, 'The other teachers listed will be marked as not your teacher.', `I'm done with ${name}`]
    : s.places > 1
      ? [`None of these teach you ${name}?`, `${name} will be taken off your list for this poll.`, 'None of these']
      : [`You don't take ${name}?`, `${name} will be taken off your list for this poll.`, `I don't take ${name}`]
  if (!(await confirm(ask[0], ask[1], ask[2]))) return
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
      if (open(s)) {
        // several listed teachers: back to them, to rate another or say they are done
        toast(`Thank you - ${teacher.value?.name ?? 'teacher'} rated.`)
        stage.value = 'subject'
        window.scrollTo({ top: 0 })
      } else {
        await afterSubject(s)
      }
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
  // nothing left: finished. Own subjects done but electives open: the "Finish" banner shows
  if (!subjects.value.some(open)) await finish()
}

/** Stop before every subject is done: what they sent is saved; nothing is needed for the rest */
async function finishEarly() {
  const left = core.value.filter(open).length
  if (!(await confirm('Finish now?', `Your answers so far are saved. You are leaving ${left} subject${left === 1 ? '' : 's'} without a rating - that's fine if you have nothing to say about ${left === 1 ? 'it' : 'them'}.`, 'Finish now'))) return
  await finish()
}

async function finish() {
  finished.value = true
  window.scrollTo({ top: 0 })
  // Shared computers (labs, the library): nothing else to answer, so sign out for the next student
  const polls = await api.get<{ id: number; accepting: boolean; voted: boolean }[]>('/vote/polls').catch(() => [])
  // this poll is finished for now even if some subjects were left out
  moreWaiting.value = polls.filter((p) => p.accepting && !p.voted && p.id !== poll.value?.id).length
  if (moreWaiting.value) return
  // From Tusome: signed out here now, then back to Tusome's sign-in page (signed out there too)
  // after a few seconds, so the next student signs in with their own Tusome account.
  leaveTo.value = session.exitUrl
  await session.studentLogout()
  if (leaveTo.value) {
    const tick = setInterval(() => {
      if (--leaveIn.value <= 0) {
        clearInterval(tick)
        window.location.href = leaveTo.value
      }
    }, 1000)
  }
}
/** Tusome's sign-out page when the student came from Tusome, and the seconds until we go there */
const leaveTo = ref('')
const leaveIn = ref(6)

const initials = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('')
</script>


<template>
  <StudentShell>
    <div v-if="loading" class="space-y-4">
      <div class="h-8 w-2/3 animate-pulse rounded bg-gray-100" />
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3"><div v-for="i in 6" :key="i" class="card h-32 animate-pulse" /></div>
    </div>

    <div v-else-if="loadError" class="card p-8 text-center">
      <p class="text-gray-700">{{ loadError }}</p>
      <RouterLink :to="{ name: 'vote-polls' }" class="btn-primary mt-5">Back to my polls</RouterLink>
    </div>

    <!-- Finished -->
    <div v-else-if="finished" class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-700 to-indigo-900 px-6 py-14 text-center text-white shadow-lift">
      <Confetti />
      <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10" aria-hidden="true" />
      <div class="relative mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-gold-400 text-indigo-950 shadow-lg">
        <CheckIcon class="h-11 w-11" />
      </div>
      <h1 class="relative mt-5 text-3xl font-semibold">Thank you!</h1>
      <p class="relative mx-auto mt-2 max-w-sm text-indigo-100">Your voice has been counted, without your name. It helps make school better for everyone.</p>
      <template v-if="moreWaiting">
        <p class="relative mt-5 text-sm font-medium">{{ moreWaiting === 1 ? 'One more poll is' : `${moreWaiting} more polls are` }} waiting for you.</p>
        <RouterLink :to="{ name: 'vote-polls' }" class="relative mt-4 inline-flex items-center gap-1 rounded-full bg-gold-400 px-6 py-3 font-semibold text-indigo-950 hover:bg-gold-300">Back to my polls <ChevronRightIcon class="h-4 w-4" /></RouterLink>
      </template>
      <template v-else>
        <template v-if="leaveTo">
          <p class="relative mt-5 text-sm text-indigo-200">You have been signed out of Student Voice and Tusome, so the next student can sign in to Tusome on this device.</p>
          <a :href="leaveTo" class="relative mt-4 inline-flex items-center gap-1 rounded-full bg-gold-400 px-6 py-3 font-semibold text-indigo-950 hover:bg-gold-300">Next student <ChevronRightIcon class="h-4 w-4" /></a>
          <p class="relative mt-3 text-xs text-indigo-200">Going back to Tusome in {{ Math.max(leaveIn, 0) }}…</p>
        </template>
        <template v-else>
          <p class="relative mt-5 text-sm text-indigo-200">You have been signed out, so the next student can vote on this device.</p>
          <RouterLink :to="{ name: 'vote-login' }" class="relative mt-4 inline-flex items-center gap-1 rounded-full bg-gold-400 px-6 py-3 font-semibold text-indigo-950 hover:bg-gold-300">Next student <ChevronRightIcon class="h-4 w-4" /></RouterLink>
        </template>
      </template>
    </div>

    <template v-else-if="poll">
      <!-- Questions, one at a time -->
      <template v-if="stage === 'questions'">
        <div class="mb-5 flex items-center gap-3">
          <div v-if="isTeachers && teacher" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-600 to-indigo-800 text-sm font-semibold text-white shadow">{{ initials(teacher.name) }}</div>
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ isTeachers && teacher ? teacher.name : poll.title }}</p>
            <p v-if="isTeachers && subject" class="text-sm text-gray-500">{{ look(subject.subject).name }} · think only about their lessons</p>
          </div>
          <button class="btn-ghost ml-auto shrink-0 text-sm" @click="isTeachers ? (stage = 'subject') : (stage = 'start')">Cancel</button>
        </div>
        <QuestionStepper :key="round" :questions="questions" :busy="busy" :server-errors="serverErrors" @send="send" />
      </template>

      <!-- Teacher poll: one subject -->
      <template v-else-if="stage === 'subject' && subject">
        <button class="btn-ghost -ml-3 mb-3 px-3 text-sm" @click="backToSubjects"><ArrowLeftIcon class="h-4 w-4" /> My subjects</button>
        <div class="card overflow-hidden shadow-lift">
          <div class="flex items-center gap-4 border-b border-line p-6" :class="look(subject.subject).tone">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/70 dark:bg-white/10"><component :is="look(subject.subject).icon" class="h-7 w-7" /></div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider opacity-80">{{ subject.subject }}</p>
              <h1 class="text-2xl font-semibold">{{ look(subject.subject).name }}</h1>
            </div>
          </div>

          <div class="p-6 sm:p-8">
            <!-- The school has set the teacher: just their card(s), no choosing -->
            <template v-if="fixed">
              <p class="text-sm font-medium text-gray-600">{{ subject.places > 1 ? `Which of these teaches you ${look(subject.subject).name}? Rate yours.` : `Your ${look(subject.subject).name} teacher` }}</p>
              <ul class="mt-3 space-y-3">
                <li v-for="t in subject.assigned" :key="t.id" class="flex items-center gap-4 rounded-2xl border border-line bg-gray-50 p-4">
                  <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-600 to-indigo-800 text-lg font-semibold text-white shadow">{{ initials(t.name) }}</div>
                  <div class="min-w-0 flex-1">
                    <p class="text-lg font-semibold">{{ t.name }}</p>
                    <p class="text-sm text-gray-500">{{ questions.filter((q) => q.type !== 'text').length }} quick questions · about a minute</p>
                  </div>
                  <span v-if="subject.rated.includes(t.id)" class="chip bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><CheckIcon class="h-3.5 w-3.5" /> Done</span>
                  <button v-else-if="subject.places > 1" class="btn-primary shrink-0 rounded-full px-4" @click="rateListed(t)">Rate</button>
                </li>
              </ul>
              <button
                v-for="t in subject.places === 1 ? listed : []"
                :key="t.id"
                class="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-700 to-indigo-600 py-3.5 text-base font-semibold text-white shadow-sm transition hover:from-indigo-800 hover:to-indigo-700"
                @click="rateListed(t)"
              >
                Rate {{ t.name.split(' ')[0] }} <ChevronRightIcon class="h-5 w-5" />
              </button>
            </template>

            <!-- Nobody set: the student picks from the department -->
            <template v-else>
              <label for="teacher" class="label">Who teaches you {{ look(subject.subject).name }}?</label>
              <select id="teacher" v-model="teacherId" class="input py-3 text-base">
                <option value="" disabled>Choose your teacher…</option>
                <option v-for="t in choosable" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
              <button
                class="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-700 to-indigo-600 py-3.5 text-base font-semibold text-white shadow-sm transition hover:from-indigo-800 hover:to-indigo-700 disabled:opacity-50"
                :disabled="teacherId === ''"
                @click="start"
              >
                Start · {{ questions.filter((q) => q.type !== 'text').length }} quick questions <ChevronRightIcon class="h-5 w-5" />
              </button>
            </template>

            <button v-if="subject.rated.length" class="btn-secondary mt-5 w-full py-3" @click="notTaking"><CheckIcon class="h-4 w-4" /> I'm done with {{ look(subject.subject).name }}</button>
            <button v-else class="btn-ghost mx-auto mt-3 block text-sm text-gray-500" @click="notTaking">{{ subject.places > 1 ? 'None of these / ' : '' }}I don't take {{ look(subject.subject).name }}</button>
          </div>
        </div>
      </template>

      <!-- Teacher poll: the subject tiles -->
      <template v-else-if="isTeachers">
        <h1 class="text-3xl font-semibold tracking-tight">{{ poll.title }}</h1>
        <p class="mt-2 text-gray-600">Tap a subject and answer a few quick questions about your teacher.</p>

        <div class="mt-5 flex items-center gap-4 rounded-2xl border border-line bg-surface/80 p-4 backdrop-blur">
          <div class="relative h-14 w-14 shrink-0">
            <svg viewBox="0 0 36 36" class="h-14 w-14 -rotate-90">
              <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="4" class="stroke-gray-100" />
              <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="4" stroke-linecap="round" class="stroke-gold-400 transition-all duration-700" :stroke-dasharray="`${totalPlaces ? (donePlaces / totalPlaces) * 97.4 : 0} 97.4`" />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums">{{ donePlaces }}/{{ totalPlaces }}</span>
          </div>
          <div>
            <p class="font-semibold">{{ donePlaces === 0 ? 'Ready when you are' : donePlaces * 2 >= totalPlaces ? 'More than halfway!' : 'Nice going' }}</p>
            <p class="text-sm text-gray-500">{{ totalPlaces - donePlaces }} subject{{ totalPlaces - donePlaces === 1 ? '' : 's' }} to go · about a minute each</p>
          </div>
        </div>

        <div v-if="coreDone && electives.length" class="mt-6 flex flex-col gap-3 rounded-2xl bg-emerald-50 p-4 sm:flex-row sm:items-center dark:bg-emerald-950/40">
          <p class="flex-1 text-sm font-medium text-emerald-900 dark:text-emerald-200">All your subjects are done. Rate an elective below if you take one, or finish now.</p>
          <button class="btn-primary shrink-0" @click="finish">Finish</button>
        </div>
        <h2 v-if="electives.length" class="mt-7 text-sm font-semibold text-gray-600">Your subjects</h2>
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <button
            v-for="s in core"
            :key="s.subject_id"
            class="group relative flex min-h-[9rem] flex-col rounded-2xl border border-line bg-surface p-4 text-left shadow-card transition"
            :class="[open(s) ? 'hover:-translate-y-1 hover:shadow-lift' : 'cursor-default opacity-75', justDone === s.subject_id ? 'ring-4 ring-emerald-300' : '']"
            :disabled="!open(s)"
            @click="openSubject(s)"
          >
            <div class="flex items-start justify-between">
              <div class="flex h-11 w-11 items-center justify-center rounded-xl" :class="look(s.subject).tone"><component :is="look(s.subject).icon" class="h-6 w-6" /></div>
              <span v-if="!open(s) && !s.skipped" class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white"><CheckIcon class="h-4 w-4" /></span>
            </div>
            <span class="mt-3 font-semibold leading-tight">{{ look(s.subject).name }}</span>
            <span class="mt-auto pt-2 text-xs text-gray-500">
              <template v-if="s.skipped">Not taking it</template>
              <template v-else-if="!open(s)">Done - thank you</template>
              <template v-else-if="s.places > 1">{{ s.rated.length ? `${s.rated.length} rated` : `Choose from ${s.places} teachers` }}</template>
              <span v-else-if="s.assigned[0]" class="font-medium text-ink">{{ s.assigned[0].name }}</span>
              <template v-else>Choose your teacher</template>
            </span>
          </button>
        </div>
        <template v-if="electives.length">
          <h2 class="mt-8 text-sm font-semibold text-gray-600">Electives <span class="font-normal text-gray-500">- only if you take them</span></h2>
          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <button
              v-for="s in electives"
              :key="s.subject_id"
              class="group relative flex min-h-[9rem] flex-col rounded-2xl border border-line bg-surface p-4 text-left shadow-card transition"
              :class="[open(s) ? 'hover:-translate-y-1 hover:shadow-lift' : 'cursor-default opacity-75', justDone === s.subject_id ? 'ring-4 ring-emerald-300' : '']"
              :disabled="!open(s)"
              @click="openSubject(s)"
            >
              <div class="flex items-start justify-between">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl" :class="look(s.subject).tone"><component :is="look(s.subject).icon" class="h-6 w-6" /></div>
                <span v-if="!open(s) && !s.skipped" class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white"><CheckIcon class="h-4 w-4" /></span>
              </div>
              <span class="mt-3 font-semibold leading-tight">{{ look(s.subject).name }}</span>
              <span class="mt-auto pt-2 text-xs text-gray-500">
                <template v-if="s.skipped">Not taking it</template>
                <template v-else-if="!open(s)">Done - thank you</template>
                <template v-else-if="s.places > 1">{{ s.rated.length ? `${s.rated.length} rated` : `Choose from ${s.places} teachers` }}</template>
                <span v-else-if="s.assigned[0]" class="font-medium text-ink">{{ s.assigned[0].name }}</span>
                <template v-else>Choose your teacher</template>
              </span>
            </button>
          </div>
        </template>
        <div v-if="!coreDone" class="mt-8 flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line p-5 text-center">
          <p class="text-sm text-gray-600">Nothing to say about some teachers? You don't have to rate every subject. Each teacher you rate is saved straight away.</p>
          <button class="btn-secondary" @click="finishEarly">{{ donePlaces ? "I'm finished" : 'Finish without rating' }}</button>
        </div>
        <p class="mt-6 text-center text-xs text-gray-500">Your answers never show your name. Teachers never see who said what.</p>
      </template>

      <!-- School poll: start -->
      <div v-else class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-700 to-indigo-900 p-8 text-center text-white shadow-lift sm:p-12">
        <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10" aria-hidden="true" />
        <div class="pointer-events-none absolute -bottom-16 -left-10 h-40 w-40 rounded-full bg-gold-400/20" aria-hidden="true" />
        <h1 class="relative text-3xl font-semibold tracking-tight">{{ poll.title }}</h1>
        <p v-if="poll.intro" class="relative mx-auto mt-3 max-w-lg whitespace-pre-line text-indigo-100">{{ poll.intro }}</p>
        <p class="relative mt-4 text-sm text-indigo-200">{{ poll.questions.length }} questions · one at a time · tap to answer</p>
        <button class="relative mt-7 inline-flex items-center gap-1 rounded-full bg-gold-400 px-8 py-3 text-base font-semibold text-indigo-950 hover:bg-gold-300" @click="start">Start <ChevronRightIcon class="h-5 w-5" /></button>
      </div>
    </template>
  </StudentShell>
</template>
