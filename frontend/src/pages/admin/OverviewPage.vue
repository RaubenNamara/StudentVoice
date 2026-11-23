<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ArrowPathIcon, ChartBarIcon, PlusIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Meter from '@/components/Meter.vue'
import TopTeachers from '@/components/TopTeachers.vue'
import TurnoutPanel from '@/components/TurnoutPanel.vue'
import VotersList from '@/components/VotersList.vue'
import { api } from '@/api'
import { formatDate, percent } from '@/feedback'
import type { Poll } from '@/types'

interface Overview {
  students: number
  polls: { open: number; draft: number; closed: number }
  open_polls: Poll[]
  votes_by_day: { day: string; votes: number }[]
  votes_today: number
  last_sync: { finished_at: string; status: string; message: string } | null
}

const data = ref<Overview | null>(null)
/** The open poll the turnout, students and best teachers below are about */
const focusId = ref(0)
const focus = computed(() => data.value?.open_polls.find((p) => p.id === focusId.value) ?? null)
const voterClass = ref('')
const voterStream = ref('')
const voterShow = ref<'voted' | 'not'>('voted')
const votersEl = ref<HTMLElement>()
onMounted(async () => {
  data.value = await api.get<Overview>('/admin/overview')
  focusId.value = data.value.open_polls[0]?.id ?? 0
})
/** A stream tapped in the turnout: list its students who haven't voted yet */
function pickStream(cls: string, stream: string) {
  voterClass.value = cls
  voterStream.value = stream
  voterShow.value = 'not'
  votersEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const peak = computed(() => Math.max(1, ...(data.value?.votes_by_day.map((d) => d.votes) ?? [1])))
const fortnight = computed(() => data.value?.votes_by_day.reduce((s, d) => s + d.votes, 0) ?? 0)
const dayLabel = (d: string) => new Date(d).toLocaleDateString('en-GB', { weekday: 'short' }).slice(0, 2)
</script>

<template>
  <PageHeader title="Overview" subtitle="How the school's voice is coming in.">
    <RouterLink :to="{ name: 'polls', query: { new: '1' } }" class="btn-primary"><PlusIcon class="h-4 w-4" /> New poll</RouterLink>
  </PageHeader>

  <div v-if="!data" class="grid gap-4 sm:grid-cols-3">
    <div v-for="i in 3" :key="i" class="card h-28 animate-pulse" />
  </div>

  <template v-else>
    <div class="grid gap-4 sm:grid-cols-3">
      <div class="card p-5">
        <p class="eyebrow">Students who can vote</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.students.toLocaleString() }}</p>
        <p class="mt-1 text-xs text-gray-500">
          <template v-if="data.last_sync">Synced from SMIS {{ formatDate(data.last_sync.finished_at) }}</template>
          <RouterLink v-else :to="{ name: 'students' }" class="font-semibold text-indigo-700 dark:text-indigo-300">Sync from SMIS →</RouterLink>
        </p>
      </div>
      <div class="card p-5">
        <p class="eyebrow">Polls</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.polls.open }} <span class="text-base font-medium text-gray-500">open</span></p>
        <p class="mt-1 text-xs text-gray-500">{{ data.polls.draft }} draft · {{ data.polls.closed }} closed</p>
      </div>
      <div class="card p-5">
        <p class="eyebrow">Votes today</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.votes_today.toLocaleString() }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ fortnight.toLocaleString() }} in the last 14 days</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
      <section class="card p-5 lg:col-span-3">
        <h2 class="font-semibold">Open polls</h2>
        <div v-if="!data.open_polls.length" class="py-10 text-center text-sm text-gray-500">
          No poll is open. <RouterLink :to="{ name: 'polls' }" class="font-semibold text-indigo-700 dark:text-indigo-300">Open one from Polls</RouterLink>
        </div>
        <ul v-else class="mt-4 divide-y divide-line">
          <li v-for="p in data.open_polls" :key="p.id" class="py-4 first:pt-0 last:pb-0">
            <div class="flex items-center justify-between gap-3">
              <RouterLink :to="{ name: 'poll', params: { id: p.id } }" class="min-w-0 truncate font-medium hover:text-indigo-700">{{ p.title }}</RouterLink>
              <RouterLink :to="{ name: 'results', params: { id: p.id } }" class="btn-ghost shrink-0 px-2 py-1 text-xs"><ChartBarIcon class="h-4 w-4" /> Results</RouterLink>
            </div>
            <div class="mt-2 flex items-center gap-3">
              <Meter :value="p.votes" :max="p.eligible" tone="gold" />
              <span class="shrink-0 text-xs tabular-nums text-gray-600">{{ p.votes.toLocaleString() }} / {{ p.eligible.toLocaleString() }} · {{ percent(p.votes, p.eligible) }}%</span>
            </div>
            <p v-if="p.closes_at" class="mt-1 text-xs text-gray-500">Closes {{ formatDate(p.closes_at) }}</p>
          </li>
        </ul>
      </section>

      <section class="card p-5 lg:col-span-2">
        <h2 class="font-semibold">Votes, last 14 days</h2>
        <div class="mt-5 flex items-end gap-1.5" role="img" :aria-label="`${fortnight} votes in the last 14 days`">
          <div v-for="d in data.votes_by_day" :key="d.day" class="group flex flex-1 flex-col items-center gap-1.5">
            <div class="relative h-36 w-full">
              <div class="absolute inset-x-0 bottom-0 rounded-t bg-indigo-600 transition group-hover:bg-gold-400" :style="{ height: `${(d.votes / peak) * 100}%`, minHeight: d.votes ? '3px' : '0' }" :title="`${formatDate(d.day, false)}: ${d.votes} votes`" />
            </div>
            <span class="text-[0.65rem] text-gray-400">{{ dayLabel(d.day) }}</span>
          </div>
        </div>
      </section>
    </div>

    <template v-if="focus">
      <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">How {{ data.open_polls.length > 1 ? 'this poll' : `"${focus.title}"` }} is going</h2>
        <select v-if="data.open_polls.length > 1" v-model="focusId" class="input w-auto" aria-label="Poll">
          <option v-for="p in data.open_polls" :key="p.id" :value="p.id">{{ p.title }}</option>
        </select>
      </div>
      <div class="mt-4 grid items-start gap-6 lg:grid-cols-5">
        <TurnoutPanel :poll-id="focus.id" :class="focus.about === 'teachers' ? 'lg:col-span-3' : 'lg:col-span-5'" @pick="pickStream" />
        <TopTeachers v-if="focus.about === 'teachers'" :poll-id="focus.id" class="lg:col-span-2" />
      </div>
      <div ref="votersEl" class="mt-6 scroll-mt-4">
        <VotersList v-model:show="voterShow" v-model:cls="voterClass" v-model:stream="voterStream" :poll-id="focus.id" />
      </div>
    </template>

    <p v-if="data.last_sync?.status === 'failed'" class="mt-6 flex items-center gap-2 rounded-xl bg-gold-50 p-3 text-sm text-gold-700">
      <ArrowPathIcon class="h-4 w-4" /> The last SMIS sync failed: {{ data.last_sync.message }}
    </p>
  </template>
</template>
