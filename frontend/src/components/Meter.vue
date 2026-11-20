<script setup lang="ts">
import { computed } from 'vue'
import { percent } from '@/feedback'

const props = withDefaults(defineProps<{ value: number; max: number; tone?: 'indigo' | 'gold' | 'green' }>(), { tone: 'indigo' })
const pct = computed(() => percent(props.value, props.max))
const bar = computed(() => ({ indigo: 'bg-indigo-600', gold: 'bg-gold-400', green: 'bg-emerald-600' })[props.tone])
</script>

<template>
  <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100" role="meter" :aria-valuenow="value" aria-valuemin="0" :aria-valuemax="max">
    <div class="h-full rounded-full transition-all duration-500" :class="bar" :style="{ width: `${Math.min(100, pct)}%` }" />
  </div>
</template>
