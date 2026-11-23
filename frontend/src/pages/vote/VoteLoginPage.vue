<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { EyeSlashIcon, HandRaisedIcon, LockClosedIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { ApiError } from '@/api'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()
const route = useRoute()
const code = ref('')
const error = ref(route.query.expired ? 'You were signed out after a while without activity. Enter your registration number again.' : '')

/** Sent back from the Tusome link (?tusome=reason): what went wrong, in plain words */
const TUSOME_PROBLEMS: Record<string, string> = {
  expired: 'That link from Tusome has expired. Go back to Tusome and tap Student Voice again.',
  used: 'That link from Tusome was already used. Go back to Tusome and tap Student Voice again.',
  invalid: "That link didn't come from your school's Tusome. Open Student Voice from your Tusome dashboard.",
  'not-linked': 'Student Voice is not linked to Tusome yet. Tell the person running the vote.',
  'other-school': 'This Student Voice is for another school.',
  'not-listed': "You're not on the Student Voice list yet. Tell the person running the vote.",
  left: 'Your account is not active in Student Voice. Tell the person running the vote.',
}
const tusomeProblem = computed(() => {
  const r = route.query.tusome
  return typeof r === 'string' ? TUSOME_PROBLEMS[r] ?? 'Signing in from Tusome did not work. Try again from your Tusome dashboard.' : ''
})
const busy = ref(false)
const input = ref<HTMLInputElement>()
const campus = import.meta.env.BASE_URL + 'brand/stmark-campus.webp'

onMounted(() => {
  if (session.student) router.replace({ name: 'vote-polls' })
  input.value?.focus()
})

/** The registration number box: capitals, no spaces (a slip's voting number also works) */
function onInput(e: Event) {
  const el = e.target as HTMLInputElement
  code.value = el.value.toUpperCase().replace(/\s+/g, '').slice(0, 40)
  el.value = code.value
  if (error.value) error.value = ''
}

async function submit() {
  error.value = ''
  if (!code.value.trim()) {
    error.value = 'Type your registration number, like 22-09390.'
    return
  }
  busy.value = true
  try {
    await session.studentLogin(code.value)
    router.push({ name: 'vote-polls' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong.'
  } finally {
    busy.value = false
  }
}

const promises = [
  { icon: EyeSlashIcon, t: 'No names', d: 'Answers are saved without your name or number.' },
  { icon: HandRaisedIcon, t: 'Once each', d: 'You can rate each teacher only once.' },
  { icon: LockClosedIcon, t: 'Safe to be honest', d: 'Teachers never see who said what.' },
]
</script>

<template>
  <div class="flex min-h-screen flex-col bg-paper">
    <!-- School banner: the campus under St Mark's navy -->
    <section class="relative overflow-hidden bg-indigo-900 pb-28 text-white">
      <img :src="campus" alt="" class="absolute inset-0 h-full w-full object-cover opacity-35" aria-hidden="true" />
      <div class="absolute inset-0 bg-gradient-to-b from-indigo-950/70 via-indigo-900/80 to-indigo-900" aria-hidden="true" />
      <div class="relative flex items-center justify-end px-4 py-3 sm:px-8">
        <ThemeToggle class="text-white hover:bg-white/10" />
      </div>
      <div class="relative mx-auto flex max-w-xl flex-col items-center px-4 text-center">
        <div class="rounded-full bg-white p-3 shadow-xl ring-4 ring-gold-400/70">
          <BrandMark :size="84" />
        </div>
        <p class="mt-4 text-lg font-semibold uppercase tracking-wide sm:text-xl">{{ session.school || "St. Mark's College Namagoma" }}</p>
        <p class="mt-1 text-xs font-semibold uppercase tracking-[0.35em] text-gold-300">To be, not to seem</p>
        <h1 class="mt-6 text-4xl font-semibold tracking-tight sm:text-5xl">Student <span class="text-gold-300">Voice</span></h1>
        <p class="mx-auto mt-3 max-w-sm text-crest-100">Have your say. It takes a few minutes and helps make school better for everyone.</p>
      </div>
    </section>

    <main class="relative -mt-20 flex flex-1 justify-center px-4 pb-12">
      <div class="w-full max-w-md">
        <!-- Coming back from Tusome with a problem -->
        <p v-if="tusomeProblem" class="mb-4 rounded-xl border border-gold-300 bg-gold-50 p-4 text-sm text-gold-700 shadow-card dark:border-gold-700 dark:bg-gold-700/20 dark:text-gold-300" role="alert">{{ tusomeProblem }}</p>

        <!-- Voting numbers switched off: students come from their Tusome dashboard -->
        <div v-if="!session.votingNumbers" class="card p-6 text-center shadow-lift sm:p-8">
          <h2 class="text-lg font-semibold">Open Student Voice from Tusome</h2>
          <p class="mt-2 text-sm text-gray-600">Sign in to Tusome with your usual account, then tap <strong>Student Voice</strong> on your dashboard. It opens here, ready for you.</p>
          <a v-if="session.tusomeUrl" :href="session.tusomeUrl" class="btn-primary mt-5 w-full py-3.5 text-base">Go to Tusome</a>
        </div>

        <form v-else class="card p-6 shadow-lift sm:p-8" @submit.prevent="submit">
          <label for="code" class="label">Your registration number</label>
          <input
            id="code"
            ref="input"
            :value="code"
            class="input py-3.5 text-center font-mono text-2xl tracking-[0.15em] uppercase"
            placeholder="e.g. 22-09390"
            autocomplete="off"
            autocapitalize="characters"
            autocorrect="off"
            spellcheck="false"
            inputmode="text"
            enterkeyhint="go"
            @input="onInput"
          />
          <p class="mt-1.5 text-xs text-gray-500">Your school registration number (the same one on your report card).</p>
          <p v-if="error" class="field-error" role="alert">{{ error }}</p>
          <button type="submit" class="btn-primary mt-5 w-full py-3.5 text-base" :disabled="busy">
            {{ busy ? 'Checking…' : "Let's go" }}
          </button>
          <p v-if="session.tusomeUrl" class="mt-4 text-center text-xs text-gray-500">
            Or open Student Voice from your <a :href="session.tusomeUrl" class="font-semibold text-indigo-700 hover:underline dark:text-crest-300">Tusome</a> dashboard.
          </p>
        </form>

        <ul class="mt-6 grid grid-cols-3 gap-2">
          <li v-for="p in promises" :key="p.t" class="rounded-xl border border-line bg-surface p-3 text-center" :title="p.d">
            <component :is="p.icon" class="mx-auto h-5 w-5 text-indigo-700 dark:text-crest-300" />
            <div class="mt-1.5 text-xs font-semibold">{{ p.t }}</div>
            <div class="mt-0.5 hidden text-[0.68rem] leading-snug text-gray-500 sm:block">{{ p.d }}</div>
          </li>
        </ul>
      </div>
    </main>

    <footer class="pb-6 text-center text-xs text-gray-500">
      {{ session.school }} · <RouterLink :to="{ name: 'admin-login' }" class="hover:text-ink">Staff sign in</RouterLink>
    </footer>
  </div>
</template>
