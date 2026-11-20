<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 36 })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 64 64" aria-hidden="true">
    <rect width="64" height="64" rx="14" fill="#3730a3" />
    <path d="M14 20a6 6 0 0 1 6-6h24a6 6 0 0 1 6 6v16a6 6 0 0 1-6 6H30l-9 8v-8h-1a6 6 0 0 1-6-6z" fill="#fff" />
    <path d="m24 28 5 5 11-11" fill="none" stroke="#d4a017" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>
