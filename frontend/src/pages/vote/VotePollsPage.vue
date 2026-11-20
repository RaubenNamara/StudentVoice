<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CheckCircleIcon, ChevronRightIcon, ClockIcon } from '@heroicons/vue/24/solid'
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
    <h1 class="text-2xl font-semibold tracking-tight">Hello{{ firstName ? `, ${firstName}` : '' }}</h1>
    <p class="mt-1 text-gray-600">
      <template v-if="loading">Looking for your polls…</template>
      <template v-else-if="waiting.length">{{ waiting.length === 1 ? 'One poll is' : `${waiting.length} polls are` }} waiting for your voice.</template>
      <template v-else-if="done.length">You have answered every open poll. Thank you!</template>
      <template v-else>There are no polls open to your class right now.</template>
    </p>

    <p v-if="error" class="field-error mt-4">{{ error }}</p>

    <div v-if="loading" class="mt-6 space-y-3">
      <div v-for="i in 2" :key="i" class="card h-24 animate-pulse" />
    </div>

    <section v-if="waiting.length" class="mt-6 space-y-3">
      <RouterLink
        v-for="p in waiting"
        :key="p.id"
        :to="{ name: 'vote-poll', params: { id: p.id } }"
        class="card group flex items-center gap-4 p-5 transition hover:-translate-y-0.5 hover:shadow-lift"
      >
        <div class="min-w-0 flex-1">
          <h2 class="text-lg font-semibold">{{ p.title }}</h2>
          <p v-if="p.intro" class="mt-1 line-clamp-2 text-sm text-gray-600">{{ p.intro }}</p>
          <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
            <span>{{ p.questions }} questions</span>
            <span v-if="p.closes_at" class="inline-flex items-center gap-1"><ClockIcon class="h-3.5 w-3.5" /> Closes {{ formatDate(p.closes_at) }}</span>
          </p>
        </div>
        <span class="btn-primary shrink-0">Start <ChevronRightIcon class="h-4 w-4" /></span>
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
