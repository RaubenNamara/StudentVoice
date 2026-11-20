<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChartBarIcon, PlusIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Modal from '@/components/Modal.vue'
import Meter from '@/components/Meter.vue'
import StatusChip from '@/components/StatusChip.vue'
import AudiencePicker from '@/components/AudiencePicker.vue'
import { api, ApiError } from '@/api'
import { formatDate, percent } from '@/feedback'
import type { PollListItem } from '@/types'

const route = useRoute()
const router = useRouter()
const polls = ref<PollListItem[] | null>(null)
const creating = ref(route.query.new === '1')
const form = ref({ title: '', intro: '', about: 'school' as 'school' | 'teachers', audience: [] as string[] })
const error = ref('')
const busy = ref(false)

onMounted(async () => (polls.value = await api.get<PollListItem[]>('/admin/polls')))

async function create() {
  error.value = ''
  busy.value = true
  try {
    const r = await api.post<{ id: number }>('/admin/polls', form.value)
    router.push({ name: 'poll', params: { id: r.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not create the poll.'
  } finally {
    busy.value = false
  }
}

const ABOUT = [
  { v: 'school' as const, t: 'The school', d: 'Each student answers once.' },
  { v: 'teachers' as const, t: 'Each teacher', d: 'Each student answers once for each of their teachers.' },
]
const audienceText = (a: string[] | null) => (a ? a.join(', ') : 'Every class')
</script>

<template>
  <PageHeader title="Polls" subtitle="Write the questions, choose who votes, then open the poll.">
    <button class="btn-primary" @click="creating = true"><PlusIcon class="h-4 w-4" /> New poll</button>
  </PageHeader>

  <div v-if="!polls" class="space-y-3">
    <div v-for="i in 3" :key="i" class="card h-24 animate-pulse" />
  </div>

  <div v-else-if="!polls.length" class="card px-6 py-14 text-center">
    <p class="font-medium">No polls yet</p>
    <p class="mt-1 text-sm text-gray-500">Create one and add your questions.</p>
    <button class="btn-primary mt-5" @click="creating = true"><PlusIcon class="h-4 w-4" /> New poll</button>
  </div>

  <div v-else class="space-y-3">
    <div v-for="p in polls" :key="p.id" class="card flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
      <RouterLink :to="{ name: 'poll', params: { id: p.id } }" class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="font-semibold hover:text-indigo-700">{{ p.title }}</h2>
          <StatusChip :status="p.status" :accepting="p.accepting" />
          <span v-if="p.about === 'teachers'" class="chip bg-gold-100 text-gold-700">Each teacher</span>
        </div>
        <p class="mt-1 text-sm text-gray-500">
          {{ p.questions }} questions · {{ audienceText(p.audience) }}
          <template v-if="p.closes_at"> · closes {{ formatDate(p.closes_at) }}</template>
        </p>
      </RouterLink>
      <div class="w-full shrink-0 sm:w-56">
        <div class="mb-1 flex justify-between text-xs text-gray-600">
          <span>{{ p.votes.toLocaleString() }} voted</span>
          <span class="tabular-nums">{{ percent(p.votes, p.eligible) }}% of {{ p.eligible.toLocaleString() }}</span>
        </div>
        <Meter :value="p.votes" :max="p.eligible" :tone="p.status === 'open' ? 'gold' : 'indigo'" />
      </div>
      <RouterLink v-if="p.votes" :to="{ name: p.about === 'teachers' ? 'teacher-results' : 'results', params: { id: p.id } }" class="btn-secondary shrink-0"><ChartBarIcon class="h-4 w-4" /> Results</RouterLink>
    </div>
  </div>

  <Modal v-if="creating" title="New poll" @close="creating = false">
    <form class="space-y-4" @submit.prevent="create">
      <div>
        <label class="label" for="t">Title</label>
        <input id="t" v-model="form.title" class="input" placeholder="e.g. School feeding survey, Term 3" required maxlength="190" />
      </div>
      <div>
        <label class="label" for="i">Note to students <span class="font-normal text-gray-500">(optional)</span></label>
        <textarea id="i" v-model="form.intro" class="input min-h-[5rem]" placeholder="What the poll is about and why their answers matter." />
      </div>
      <div>
        <span class="label">This poll is about</span>
        <div class="grid grid-cols-2 gap-2">
          <button v-for="o in ABOUT" :key="o.v" type="button" class="rounded-xl border p-3 text-left text-sm" :class="form.about === o.v ? 'border-indigo-700 bg-indigo-50 dark:bg-indigo-950/60' : 'border-line hover:border-indigo-300'" @click="form.about = o.v">
            <span class="font-semibold">{{ o.t }}</span><span class="mt-0.5 block text-xs text-gray-500">{{ o.d }}</span>
          </button>
        </div>
      </div>
      <div>
        <span class="label">Who votes</span>
        <AudiencePicker v-model="form.audience" />
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn-secondary" @click="creating = false">Cancel</button>
        <button class="btn-primary" :disabled="busy">Create and add questions</button>
      </div>
    </form>
  </Modal>
</template>
