<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ArrowLeftIcon, CheckIcon } from '@heroicons/vue/24/solid'
import { SCALE, type Question } from '@/types'

/**
 * One question at a time: tap an answer and it moves on by itself. Written questions are kept
 * together on the last card ("Anything to add?"), so a short form stays short. Keys 1-5 answer a
 * scale question on a keyboard.
 */
type Q = Question & { id: number }
type Answer = number | number[] | string | null

const props = defineProps<{ questions: Q[]; busy?: boolean; sendLabel?: string; serverErrors?: Record<string, string> }>()
const emit = defineEmits<{ send: [answers: Record<number, Answer>] }>()

const answers = reactive<Record<number, Answer>>({})
for (const q of props.questions) answers[q.id] = q.type === 'multi' ? [] : q.type === 'text' ? '' : null

const tapQuestions = computed(() => props.questions.filter((q) => q.type !== 'text'))
const textQuestions = computed(() => props.questions.filter((q) => q.type === 'text'))
const steps = computed(() => tapQuestions.value.length + (textQuestions.value.length ? 1 : 0))
const step = ref(0)
const dir = ref<'next' | 'back'>('next')
const error = ref('')
const isTextStep = computed(() => step.value === tapQuestions.value.length)
const current = computed(() => tapQuestions.value[step.value])
const progress = computed(() => (steps.value ? (step.value / steps.value) * 100 : 0))

const CHEER = ['Nice start!', 'Keep going', 'You are doing great', 'Halfway there!', 'Almost done', 'Last one!']
const cheer = computed(() => {
  const left = steps.value - step.value
  if (left === 1) return CHEER[5]
  if (step.value === 0) return ''
  if (left === 2) return CHEER[4]
  if (Math.abs(step.value - Math.floor(steps.value / 2)) < 1) return CHEER[3]
  return ''
})

watch(() => props.serverErrors, (e) => {
  if (!e) return
  const first = props.questions.findIndex((q) => e[q.id])
  if (first >= 0) {
    const q = props.questions[first]
    step.value = q.type === 'text' ? tapQuestions.value.length : tapQuestions.value.indexOf(q)
    error.value = e[q.id]
  }
})

function filled(q: Q) {
  const a = answers[q.id]
  return Array.isArray(a) ? a.length > 0 : typeof a === 'string' ? a.trim() !== '' : a !== null
}

let timer: number | undefined
function next() {
  error.value = ''
  if (!isTextStep.value && current.value?.required && !filled(current.value)) {
    error.value = 'Please choose an answer.'
    return
  }
  if (step.value < steps.value - 1) {
    dir.value = 'next'
    step.value++
  } else {
    send()
  }
}
function back() {
  error.value = ''
  if (step.value > 0) {
    dir.value = 'back'
    step.value--
  }
}
/** A tap answers and moves on (a short pause so the choice is seen) */
function choose(value: number) {
  answers[current.value.id] = value
  error.value = ''
  clearTimeout(timer)
  // the very last question waits for "Send", so nothing goes off by accident
  if (step.value < steps.value - 1) timer = window.setTimeout(next, 260)
}
function toggle(i: number) {
  const list = answers[current.value.id] as number[]
  answers[current.value.id] = list.includes(i) ? list.filter((x) => x !== i) : [...list, i].sort((a, b) => a - b)
  error.value = ''
}
function send() {
  const missing = props.questions.find((q) => q.required && !filled(q))
  if (missing) {
    step.value = missing.type === 'text' ? tapQuestions.value.length : tapQuestions.value.indexOf(missing)
    error.value = 'Please answer this one.'
    return
  }
  emit('send', { ...answers })
}

function onKey(e: KeyboardEvent) {
  if ((e.target as HTMLElement)?.tagName === 'TEXTAREA') return
  if (current.value?.type === 'scale' && !isTextStep.value && /^[1-5]$/.test(e.key)) choose(Number(e.key))
  if (e.key === 'ArrowLeft') back()
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  clearTimeout(timer)
})

const SCALE_TONE = [
  'hover:border-red-300 aria-checked:border-red-600 aria-checked:bg-red-600',
  'hover:border-orange-300 aria-checked:border-orange-500 aria-checked:bg-orange-500',
  'hover:border-gray-400 aria-checked:border-gray-500 aria-checked:bg-gray-500',
  'hover:border-indigo-300 aria-checked:border-indigo-500 aria-checked:bg-indigo-500',
  'hover:border-indigo-400 aria-checked:border-indigo-700 aria-checked:bg-indigo-700',
]
</script>

<template>
  <div>
    <!-- Progress -->
    <div class="mb-6 flex items-center gap-3">
      <button class="btn-ghost -ml-2 px-2" :class="step === 0 ? 'invisible' : ''" aria-label="Previous question" @click="back"><ArrowLeftIcon class="h-5 w-5" /></button>
      <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
        <div class="h-2 rounded-full bg-gold-400 transition-all duration-300" :style="{ width: `${progress}%` }" />
      </div>
      <span class="w-14 shrink-0 text-right text-sm tabular-nums text-gray-500">{{ Math.min(step + 1, steps) }} / {{ steps }}</span>
    </div>

    <Transition mode="out-in" :enter-from-class="dir === 'next' ? 'translate-x-6 opacity-0' : '-translate-x-6 opacity-0'" enter-active-class="transition duration-200" leave-active-class="transition duration-100" leave-to-class="opacity-0">
      <!-- One tap question -->
      <div v-if="!isTextStep && current" :key="current.id" class="card p-6 sm:p-8">
        <p v-if="current.section" class="eyebrow text-gold-600">{{ current.section }}</p>
        <h2 class="mt-2 text-xl font-semibold leading-snug sm:text-2xl">{{ current.prompt }}</h2>

        <div v-if="current.type === 'scale'" class="mt-6 grid gap-2 sm:grid-cols-5" role="radiogroup">
          <button
            v-for="(s, k) in SCALE"
            :key="s.value"
            type="button"
            role="radio"
            :aria-checked="answers[current.id] === s.value"
            class="group flex items-center gap-3 rounded-xl border-2 border-line bg-surface px-4 py-3 text-left transition aria-checked:text-white sm:flex-col sm:gap-1 sm:px-2 sm:py-4 sm:text-center"
            :class="SCALE_TONE[k]"
            @click="choose(s.value)"
          >
            <span class="text-2xl font-semibold leading-none">{{ s.value }}</span>
            <span class="text-sm font-medium sm:text-xs">{{ s.label }}</span>
          </button>
        </div>

        <div v-else-if="current.type === 'yesno'" class="mt-6 grid grid-cols-2 gap-3" role="radiogroup">
          <button
            v-for="o in [{ v: 1, t: 'Yes' }, { v: 0, t: 'No' }]"
            :key="o.v"
            type="button"
            role="radio"
            :aria-checked="answers[current.id] === o.v"
            class="rounded-xl border-2 border-line px-4 py-5 text-lg font-semibold transition hover:border-indigo-300 aria-checked:border-indigo-700 aria-checked:bg-indigo-700 aria-checked:text-white"
            @click="choose(o.v)"
          >
            {{ o.t }}
          </button>
        </div>

        <div v-else-if="current.type === 'choice'" class="mt-6 space-y-2" role="radiogroup">
          <button
            v-for="(o, i) in current.options"
            :key="i"
            type="button"
            role="radio"
            :aria-checked="answers[current.id] === i"
            class="w-full rounded-xl border-2 border-line px-4 py-3 text-left font-medium transition hover:border-indigo-300 aria-checked:border-indigo-700 aria-checked:bg-indigo-700 aria-checked:text-white"
            @click="choose(i)"
          >
            {{ o }}
          </button>
        </div>

        <div v-else-if="current.type === 'multi'" class="mt-6 space-y-2" role="group">
          <p class="text-sm text-gray-500">Choose all that apply.</p>
          <button
            v-for="(o, i) in current.options"
            :key="i"
            type="button"
            role="checkbox"
            :aria-checked="(answers[current.id] as number[]).includes(i)"
            class="flex w-full items-center gap-3 rounded-xl border-2 border-line px-4 py-3 text-left font-medium transition hover:border-indigo-300 aria-checked:border-indigo-700 aria-checked:bg-indigo-50 dark:aria-checked:bg-indigo-950"
            @click="toggle(i)"
          >
            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2" :class="(answers[current.id] as number[]).includes(i) ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-gray-300'"><CheckIcon v-if="(answers[current.id] as number[]).includes(i)" class="h-3.5 w-3.5" /></span>
            {{ o }}
          </button>
          <button class="btn-primary mt-3 w-full py-3" @click="next">Next</button>
        </div>

        <p v-if="error" class="field-error mt-3" role="alert">{{ error }}</p>
        <p v-if="current.type === 'scale'" class="mt-5 hidden text-center text-xs text-gray-400 sm:block">Tip: press 1-5 on the keyboard</p>
        <button v-if="!current.required && current.type !== 'multi'" class="btn-ghost mx-auto mt-4 block text-sm" @click="next">Skip this one</button>
      </div>

      <!-- Written answers, together on the last card -->
      <div v-else key="text" class="card p-6 sm:p-8">
        <p class="eyebrow text-gold-600">{{ textQuestions[0]?.section || 'Anything to add?' }}</p>
        <h2 class="mt-2 text-xl font-semibold">{{ textQuestions.some((q) => q.required) ? 'A few words from you' : 'Optional - leave empty if you like' }}</h2>
        <div class="mt-5 space-y-4">
          <div v-for="q in textQuestions" :key="q.id">
            <label :for="`t-${q.id}`" class="label">{{ q.prompt }} <span v-if="q.required" class="text-red-600">*</span></label>
            <textarea :id="`t-${q.id}`" v-model="answers[q.id] as string" class="input min-h-[5rem]" maxlength="3000" placeholder="Don't write your name." />
          </div>
        </div>
        <p v-if="error" class="field-error mt-3" role="alert">{{ error }}</p>
        <button class="btn-primary mt-6 w-full py-3 text-base" :disabled="busy" @click="send">{{ busy ? 'Sending…' : sendLabel || 'Send my answers' }}</button>
      </div>
    </Transition>

    <p v-if="cheer" class="mt-4 text-center text-sm font-medium text-gold-700">{{ cheer }}</p>
    <!-- No written questions: the last tap question shows a send button once answered -->
    <button
      v-if="!textQuestions.length && step === steps - 1 && current && filled(current) && current.type !== 'multi'"
      class="btn-primary mt-4 w-full py-3 text-base"
      :disabled="busy"
      @click="send"
    >
      {{ busy ? 'Sending…' : sendLabel || 'Send my answers' }}
    </button>
  </div>
</template>
