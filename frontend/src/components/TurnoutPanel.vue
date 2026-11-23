<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Meter from '@/components/Meter.vue'
import { api } from '@/api'
import { percent } from '@/feedback'

/** Turnout for one poll: the whole school, each class, and (on click) each class's streams */
interface Group { class: string; stream?: string; eligible: number; voted: number }
interface Turnout {
  poll: { id: number; title: string; about: string; status: string }
  eligible: number
  voted: number
  classes: Group[]
  streams: Group[]
}

const props = defineProps<{ pollId: number }>()
const emit = defineEmits<{ pick: [cls: string, stream: string] }>()
const data = ref<Turnout | null>(null)
const open = ref('')

watch(() => props.pollId, async (id) => {
  data.value = null
  data.value = await api.get<Turnout>(`/admin/polls/${id}/turnout`)
}, { immediate: true })

/** One decimal under 10% (1 of 678 is 0.1%, not 0%) */
const pct = (part: number, whole: number) => (!whole ? 0 : (part / whole) * 100 < 10 ? Math.round((part / whole) * 1000) / 10 : Math.round((part / whole) * 100))
const streamsOf = (cls: string) => data.value?.streams.filter((s) => s.class === cls) ?? []
const tone = (g: { voted: number; eligible: number }) => (percent(g.voted, g.eligible) >= 75 ? 'green' : percent(g.voted, g.eligible) >= 40 ? 'gold' : 'indigo')
/** The class with the lowest turnout (to chase first) */
const lowest = computed(() => [...(data.value?.classes ?? [])].filter((c) => c.eligible).sort((a, b) => a.voted / a.eligible - b.voted / b.eligible)[0])
</script>

<template>
  <section class="card p-5">
    <div v-if="!data" class="h-48 animate-pulse" />
    <template v-else>
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="font-semibold">Turnout</h2>
          <p class="text-xs text-gray-500">{{ data.poll.title }}<template v-if="data.poll.about === 'teachers'"> · a student counts from their first rating</template></p>
        </div>
        <div class="text-right">
          <p class="text-3xl font-semibold tabular-nums">{{ pct(data.voted, data.eligible) }}%</p>
          <p class="text-xs text-gray-500 tabular-nums">{{ data.voted.toLocaleString() }} of {{ data.eligible.toLocaleString() }} students</p>
        </div>
      </div>
      <div class="mt-3"><Meter :value="data.voted" :max="data.eligible" :tone="tone(data)" /></div>
      <p v-if="lowest && data.classes.length > 1" class="mt-2 text-xs text-gray-500">Lowest: <strong>{{ lowest.class }}</strong> at {{ pct(lowest.voted, lowest.eligible) }}%</p>

      <ul class="mt-5 divide-y divide-line">
        <li v-for="c in data.classes" :key="c.class" class="py-2.5">
          <button type="button" class="flex w-full items-center gap-3 text-left" :aria-expanded="open === c.class" @click="open = open === c.class ? '' : c.class">
            <span class="w-12 shrink-0 font-semibold">{{ c.class }}</span>
            <Meter :value="c.voted" :max="c.eligible" :tone="tone(c)" />
            <span class="w-28 shrink-0 text-right text-xs tabular-nums text-gray-600">{{ c.voted }} / {{ c.eligible }} · <strong>{{ pct(c.voted, c.eligible) }}%</strong></span>
          </button>
          <ul v-if="open === c.class" class="mt-2 space-y-1.5 border-l-2 border-line pl-4">
            <li v-for="s in streamsOf(c.class)" :key="s.stream" class="flex items-center gap-3">
              <button type="button" class="w-12 shrink-0 text-left text-sm font-medium text-indigo-700 hover:underline dark:text-crest-300" :title="`Show ${c.class}${s.stream} students`" @click="emit('pick', c.class, s.stream ?? '')">{{ c.class }}{{ s.stream }}</button>
              <Meter :value="s.voted" :max="s.eligible" :tone="tone(s)" />
              <span class="w-28 shrink-0 text-right text-xs tabular-nums text-gray-600">{{ s.voted }} / {{ s.eligible }} · {{ pct(s.voted, s.eligible) }}%</span>
            </li>
          </ul>
        </li>
      </ul>
      <p class="mt-3 text-xs text-gray-400">Tap a class to see its streams; tap a stream to list its students below.</p>
    </template>
  </section>
</template>
