<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircleIcon, CheckIcon } from '@heroicons/vue/24/solid'
import StudentShell from '@/layouts/StudentShell.vue'
import { api, ApiError } from '@/api'
import { confirm, toast } from '@/feedback'
import { useSession } from '@/stores/session'
import { SCALE, type Question } from '@/types'

interface VotePoll {
  id: number
  title: string
  intro: string | null
  closes_at: string | null
  questions: (Question & { id: number })[]
}

const route = useRoute()
const router = useRouter()
const poll = ref<VotePoll | null>(null)
const loading = ref(true)
const loadError = ref('')
const answers = reactive<Record<number, number | number[] | string | null>>({})
const errors = ref<Record<string, string>>({})
const busy = ref(false)
const done = ref(false)
const moreWaiting = ref(0)
const session = useSession()

onMounted(async () => {
  try {
    poll.value = await api.get<VotePoll>(`/vote/polls/${route.params.id}`)
    for (const q of poll.value.questions) answers[q.id] = q.type === 'multi' ? [] : q.type === 'text' ? '' : null
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Could not open this poll.'
  } finally {
    loading.value = false
  }
})

/** Questions grouped under their section headings, numbered straight through */
const groups = computed(() => {
  const out: { section: string | null; items: { q: VotePoll['questions'][number]; n: number }[] }[] = []
  poll.value?.questions.forEach((q, i) => {
    const last = out[out.length - 1]
    if (!last || last.section !== q.section) out.push({ section: q.section, items: [] })
    out[out.length - 1].items.push({ q, n: i + 1 })
  })
  return out
})

function answered(q: Question & { id: number }) {
  const a = answers[q.id]
  return Array.isArray(a) ? a.length > 0 : typeof a === 'string' ? a.trim() !== '' : a !== null && a !== undefined
}
const total = computed(() => poll.value?.questions.length ?? 0)
const count = computed(() => poll.value?.questions.filter(answered).length ?? 0)
const missing = computed(() => poll.value?.questions.filter((q) => q.required && !answered(q)) ?? [])

function pick(q: Question & { id: number }, value: number) {
  answers[q.id] = value
  delete errors.value[q.id]
}
function toggle(q: Question & { id: number }, i: number) {
  const list = (answers[q.id] as number[]) ?? []
  answers[q.id] = list.includes(i) ? list.filter((x) => x !== i) : [...list, i].sort((a, b) => a - b)
  delete errors.value[q.id]
}

async function goTo(id: number) {
  await nextTick()
  document.getElementById(`q-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function submit() {
  if (!poll.value) return
  if (missing.value.length) {
    errors.value = Object.fromEntries(missing.value.map((q) => [q.id, 'Please answer this question.']))
    toast(missing.value.length === 1 ? 'One question still needs an answer.' : `${missing.value.length} questions still need an answer.`, 'error')
    goTo(missing.value[0].id)
    return
  }
  if (!(await confirm('Send your answers?', 'You can only vote once in this poll, so you will not be able to change them afterwards.', 'Send my answers'))) return
  busy.value = true
  try {
    await api.post(`/vote/polls/${poll.value.id}`, { answers })
    done.value = true
    window.scrollTo({ top: 0 })
    // Shared computers (labs, the library): nothing else to answer, so sign out for the next student
    const left = await api.get<{ accepting: boolean; voted: boolean }[]>('/vote/polls').catch(() => [])
    moreWaiting.value = left.filter((p) => p.accepting && !p.voted).length
    if (!moreWaiting.value) await session.studentLogout()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'already_voted') {
      toast(e.message, 'error')
      router.replace({ name: 'vote-polls' })
      return
    }
    if (e instanceof ApiError && Object.keys(e.errors).length) {
      errors.value = e.errors
      const first = Object.keys(e.errors).find((k) => /^\d+$/.test(k))
      if (first) goTo(Number(first))
    }
    toast(e instanceof ApiError ? e.message : 'Could not send your answers. Try again.', 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <StudentShell>
    <template v-if="poll && !done" #bar>
      <div class="h-1 bg-gray-100">
        <div class="h-1 bg-gold-400 transition-all duration-300" :style="{ width: `${total ? (count / total) * 100 : 0}%` }" />
      </div>
    </template>

    <div v-if="loading" class="space-y-4">
      <div class="h-8 w-2/3 animate-pulse rounded bg-gray-100" />
      <div v-for="i in 3" :key="i" class="card h-32 animate-pulse" />
    </div>

    <div v-else-if="loadError" class="card p-8 text-center">
      <p class="text-gray-700">{{ loadError }}</p>
      <RouterLink :to="{ name: 'vote-polls' }" class="btn-primary mt-5">Back to my polls</RouterLink>
    </div>

    <div v-else-if="done" class="card px-6 py-12 text-center">
      <CheckCircleIcon class="mx-auto h-16 w-16 text-emerald-600" />
      <h1 class="mt-4 text-2xl font-semibold">Thank you!</h1>
      <p class="mx-auto mt-2 max-w-sm text-gray-600">Your answers have been saved without your name. They will help make school better for everyone.</p>
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
      <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ poll.title }}</h1>
      <p v-if="poll.intro" class="mt-2 whitespace-pre-line text-gray-600">{{ poll.intro }}</p>
      <p class="mt-3 text-sm text-gray-500">{{ total }} questions · questions marked <span class="text-red-600">*</span> need an answer</p>

      <form class="mt-8 space-y-10" @submit.prevent="submit">
        <section v-for="(g, gi) in groups" :key="gi">
          <h2 v-if="g.section" class="mb-4 border-b-2 border-gold-400 pb-2 text-base font-semibold text-indigo-800 dark:text-indigo-300">{{ g.section }}</h2>
          <div class="space-y-4">
            <fieldset
              v-for="{ q, n } in g.items"
              :id="`q-${q.id}`"
              :key="q.id"
              class="card scroll-mt-24 p-5"
              :class="errors[q.id] ? 'border-red-300 ring-2 ring-red-200 dark:border-red-800 dark:ring-red-900' : ''"
            >
              <legend class="sr-only">Question {{ n }}</legend>
              <p class="font-medium">
                <span class="mr-1 text-gray-400">{{ n }}.</span> {{ q.prompt }}
                <span v-if="q.required" class="text-red-600">*</span>
              </p>

              <!-- 1-5 agree scale -->
              <div v-if="q.type === 'scale'" class="mt-4 grid grid-cols-5 gap-1.5 sm:gap-2" role="radiogroup">
                <button
                  v-for="s in SCALE"
                  :key="s.value"
                  type="button"
                  role="radio"
                  :aria-checked="answers[q.id] === s.value"
                  class="flex min-h-[3.75rem] flex-col items-center justify-center rounded-xl border px-1 py-2 text-center transition"
                  :class="answers[q.id] === s.value ? 'border-indigo-700 bg-indigo-700 text-white shadow-sm' : 'border-line bg-surface hover:border-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/40'"
                  @click="pick(q, s.value)"
                >
                  <span class="text-lg font-semibold leading-none">{{ s.value }}</span>
                  <span class="mt-1 text-[0.68rem] leading-tight sm:text-xs" :class="answers[q.id] === s.value ? 'text-indigo-100' : 'text-gray-500'">{{ s.label }}</span>
                </button>
              </div>

              <!-- yes / no -->
              <div v-else-if="q.type === 'yesno'" class="mt-4 grid grid-cols-2 gap-2" role="radiogroup">
                <button
                  v-for="opt in [{ v: 1, t: 'Yes' }, { v: 0, t: 'No' }]"
                  :key="opt.v"
                  type="button"
                  role="radio"
                  :aria-checked="answers[q.id] === opt.v"
                  class="rounded-xl border px-4 py-3 font-semibold transition"
                  :class="answers[q.id] === opt.v ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-line hover:border-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/40'"
                  @click="pick(q, opt.v)"
                >
                  {{ opt.t }}
                </button>
              </div>

              <!-- pick one / pick any -->
              <div v-else-if="q.type === 'choice' || q.type === 'multi'" class="mt-4 space-y-2" :role="q.type === 'choice' ? 'radiogroup' : 'group'">
                <p v-if="q.type === 'multi'" class="-mt-2 mb-2 text-xs text-gray-500">Choose all that apply.</p>
                <button
                  v-for="(opt, i) in q.options"
                  :key="i"
                  type="button"
                  :role="q.type === 'choice' ? 'radio' : 'checkbox'"
                  :aria-checked="q.type === 'choice' ? answers[q.id] === i : (answers[q.id] as number[]).includes(i)"
                  class="flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-left text-sm transition"
                  :class="(q.type === 'choice' ? answers[q.id] === i : (answers[q.id] as number[]).includes(i)) ? 'border-indigo-700 bg-indigo-50 font-medium text-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-100' : 'border-line hover:border-indigo-300'"
                  @click="q.type === 'choice' ? pick(q, i) : toggle(q, i)"
                >
                  <span
                    class="flex h-5 w-5 shrink-0 items-center justify-center border-2"
                    :class="[
                      q.type === 'choice' ? 'rounded-full' : 'rounded-md',
                      (q.type === 'choice' ? answers[q.id] === i : (answers[q.id] as number[]).includes(i)) ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-gray-300',
                    ]"
                  >
                    <CheckIcon v-if="q.type === 'multi' ? (answers[q.id] as number[]).includes(i) : answers[q.id] === i" class="h-3.5 w-3.5" />
                  </span>
                  {{ opt }}
                </button>
              </div>

              <!-- written -->
              <div v-else class="mt-3">
                <textarea v-model="answers[q.id] as string" class="input min-h-[6rem]" maxlength="3000" placeholder="Write your answer. Do not write your name." @input="delete errors[q.id]" />
              </div>

              <p v-if="errors[q.id]" class="field-error">{{ errors[q.id] }}</p>
            </fieldset>
          </div>
        </section>

        <div class="sticky bottom-0 -mx-4 border-t border-line bg-paper/95 px-4 py-4 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
          <div class="flex items-center justify-between gap-4">
            <span class="text-sm text-gray-600">{{ count }} of {{ total }} answered</span>
            <button type="submit" class="btn-primary px-6 py-3 text-base" :disabled="busy">{{ busy ? 'Sending…' : 'Send my answers' }}</button>
          </div>
        </div>
      </form>
    </template>
  </StudentShell>
</template>
