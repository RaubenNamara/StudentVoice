<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CheckCircleIcon, ChevronRightIcon, ClockIcon } from '@heroicons/vue/24/solid'
import { AcademicCapIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import StudentShell from '@/layouts/StudentShell.vue'
import { api, ApiError } from '@/api'
import { formatDate } from '@/feedback'
import { useSession } from '@/stores/session'

interface MyPoll {
  id: number
  title: string
  intro: string | null
  closes_at: string | null
  accepting: boolean
  questions: number
  voted: boolean
  about: 'school' | 'teachers'
  teachers?: number
  handled?: number
}

const session = useSession()
const polls = ref<MyPoll[]>([])
const loading = ref(true)
const error = ref('')

const waiting = computed(() => polls.value.filter((p) => p.accepting && !p.voted))
const done = computed(() => polls.value.filter((p) => p.voted))
const firstName = computed(() => {
  const parts = (session.student?.name ?? '').split(' ').filter(Boolean)
  const name = parts.length > 1 ? parts[parts.length - 1] : parts[0] ?? ''
  return name.charAt(0) + name.slice(1).toLowerCase()
})
const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})

onMounted(async () => {
  try {
    polls.value = await api.get<MyPoll[]>('/vote/polls')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not load your polls.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <StudentShell>
    <p class="text-sm font-medium text-gold-700 dark:text-gold-300">{{ greeting }}</p>
    <h1 class="mt-1 text-3xl font-semibold tracking-tight">{{ firstName ? `Hi, ${firstName}` : 'Hi there' }}</h1>
    <p class="mt-2 text-gray-600">
      <template v-if="loading">Looking for your polls…</template>
      <template v-else-if="waiting.length">{{ waiting.length === 1 ? 'One poll is' : `${waiting.length} polls are` }} waiting for your voice.</template>
      <template v-else-if="done.length">You have answered every open poll. Thank you!</template>
      <template v-else>There are no polls open to your class right now.</template>
    </p>

    <p v-if="error" class="field-error mt-4">{{ error }}</p>

    <div v-if="loading" class="mt-6 space-y-3">
      <div v-for="i in 2" :key="i" class="card h-32 animate-pulse" />
    </div>

    <section v-if="waiting.length" class="mt-7 space-y-4">
      <RouterLink
        v-for="p in waiting"
        :key="p.id"
        :to="{ name: 'vote-poll', params: { id: p.id } }"
        class="group relative block overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-700 via-indigo-700 to-indigo-900 p-6 text-white shadow-lift transition hover:-translate-y-0.5"
      >
        <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/10" aria-hidden="true" />
        <div class="pointer-events-none absolute -bottom-16 right-16 h-32 w-32 rounded-full bg-gold-400/20" aria-hidden="true" />
        <div class="relative flex items-start gap-4">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/15">
            <component :is="p.about === 'teachers' ? AcademicCapIcon : ChatBubbleLeftRightIcon" class="h-6 w-6" />
          </div>
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold">{{ p.title }}</h2>
            <p v-if="p.intro" class="mt-1 line-clamp-2 text-sm text-indigo-100">{{ p.intro }}</p>
          </div>
        </div>
        <div v-if="p.about === 'teachers'" class="relative mt-5">
          <div class="flex justify-between text-xs text-indigo-100"><span>{{ p.handled }} of {{ p.teachers }} teachers done</span><span>about a minute each</span></div>
          <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-white/15"><div class="h-2 rounded-full bg-gold-400" :style="{ width: `${p.teachers ? ((p.handled ?? 0) / p.teachers) * 100 : 0}%` }" /></div>
        </div>
        <div class="relative mt-5 flex items-center justify-between">
          <span class="flex items-center gap-1 text-xs text-indigo-200">
            <template v-if="p.closes_at"><ClockIcon class="h-3.5 w-3.5" /> Closes {{ formatDate(p.closes_at) }}</template>
            <template v-else-if="p.about !== 'teachers'">{{ p.questions }} quick questions</template>
          </span>
          <span class="inline-flex items-center gap-1 rounded-full bg-gold-400 px-4 py-2 text-sm font-semibold text-indigo-950 transition group-hover:bg-gold-300">
            {{ p.handled ? 'Continue' : 'Start' }} <ChevronRightIcon class="h-4 w-4" />
          </span>
        </div>
      </RouterLink>
    </section>

    <section v-if="done.length" class="mt-10">
      <h2 class="eyebrow">Done</h2>
      <ul class="mt-3 space-y-2">
        <li v-for="p in done" :key="p.id" class="flex items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3">
          <CheckCircleIcon class="h-5 w-5 shrink-0 text-emerald-600" />
          <span class="flex-1 text-sm font-medium">{{ p.title }}</span>
          <span class="text-xs text-gray-500">Voted</span>
        </li>
      </ul>
    </section>
  </StudentShell>
</template>
