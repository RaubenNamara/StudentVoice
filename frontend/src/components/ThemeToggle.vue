<script setup lang="ts">
import { ref } from 'vue'
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline'

const dark = ref(document.documentElement.classList.contains('dark'))

function toggle() {
  dark.value = !dark.value
  document.documentElement.classList.toggle('dark', dark.value)
  try {
    localStorage.setItem('sv-theme', dark.value ? 'dark' : 'light')
  } catch {
    // private window: the choice lasts until the page closes
  }
}
</script>

<template>
  <button class="btn-ghost px-2" :aria-label="dark ? 'Use light mode' : 'Use dark mode'" :title="dark ? 'Light mode' : 'Dark mode'" @click="toggle">
    <SunIcon v-if="dark" class="h-5 w-5" />
    <MoonIcon v-else class="h-5 w-5" />
  </button>
</template>
