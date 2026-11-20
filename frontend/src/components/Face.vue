<script setup lang="ts">
import { computed } from 'vue'

/** A simple drawn face for the 1-5 scale: frown (1) to big smile (5). Drawn, not an emoji. */
const props = defineProps<{ value: number }>()
const mouth = computed(() => {
  // control point of the mouth curve: below the corners smiles, above frowns
  const y = { 1: 15, 2: 17.5, 3: 20, 4: 22.5, 5: 25 }[props.value] ?? 20
  return props.value === 3 ? 'M8.5 20h11' : `M8.5 20Q14 ${2 * y - 20} 19.5 20`
})
</script>

<template>
  <svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
    <circle cx="14" cy="14" r="12" />
    <circle cx="10" cy="11" r="1.2" fill="currentColor" stroke="none" />
    <circle cx="18" cy="11" r="1.2" fill="currentColor" stroke="none" />
    <path :d="mouth" />
  </svg>
</template>
