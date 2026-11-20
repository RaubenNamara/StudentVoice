<script setup lang="ts">
import { computed } from 'vue'
import { SCALE } from '@/types'

/**
 * One 100% bar of agree/disagree answers, Strongly disagree (left, red) to Strongly agree (right,
 * St Mark's navy), grey "Not sure" between. Segments wide enough show their percentage; every
 * segment has a tooltip with the count. Colours are the lk-1..5 theme steps (light and dark).
 */
const props = withDefaults(defineProps<{ counts: number[]; tall?: boolean; labels?: boolean }>(), { tall: false, labels: true })

const total = computed(() => props.counts.reduce((s, n) => s + n, 0))
const segments = computed(() =>
  props.counts.map((n, i) => ({
    n,
    i,
    pct: total.value ? (n / total.value) * 100 : 0,
    label: SCALE[i].label,
  })),
)
const FILL = ['bg-lk-1 text-white', 'bg-lk-2 text-ink', 'bg-lk-3 text-ink', 'bg-lk-4 text-ink', 'bg-lk-5 text-white']
</script>

<template>
  <div
    class="flex w-full gap-[2px] overflow-hidden rounded"
    :class="tall ? 'h-7' : 'h-5'"
    role="img"
    :aria-label="segments.map((s) => `${s.label} ${Math.round(s.pct)}%`).join(', ')"
  >
    <div
      v-for="s in segments.filter((x) => x.n > 0)"
      :key="s.i"
      class="flex min-w-0 items-center justify-center first:rounded-l last:rounded-r"
      :class="FILL[s.i]"
      :style="{ width: `${s.pct}%` }"
      :title="`${s.label}: ${Math.round(s.pct)}% (${s.n} of ${total})`"
    >
      <span v-if="labels && s.pct >= (tall ? 7 : 14)" class="whitespace-nowrap px-0.5 text-[0.68rem] font-semibold tabular-nums leading-none" :class="tall ? 'text-xs' : ''">{{ Math.round(s.pct) }}%</span>
    </div>
    <div v-if="!total" class="h-full w-full rounded bg-gray-100" />
  </div>
</template>
