<script setup lang="ts">
/** A short burst of brand-coloured confetti (CSS only), for finishing. Still under reduced motion. */
const COLOURS = ['#3730a3', '#4f46e5', '#d4a017', '#e8b931', '#818cf8', '#f0cc5c']
const pieces = Array.from({ length: 36 }, (_, i) => ({
  left: `${(i * 37) % 100}%`,
  delay: `${(i % 9) * 0.06}s`,
  colour: COLOURS[i % COLOURS.length],
  drift: `${((i * 53) % 120) - 60}px`,
  turn: `${(i * 97) % 720}deg`,
  round: i % 3 === 0,
}))
</script>

<template>
  <div class="pointer-events-none absolute inset-x-0 top-0 h-0 overflow-visible" aria-hidden="true">
    <i
      v-for="(p, i) in pieces"
      :key="i"
      class="confetti absolute top-0 block h-2.5 w-1.5"
      :class="p.round ? 'rounded-full !w-2 !h-2' : 'rounded-sm'"
      :style="{ left: p.left, background: p.colour, animationDelay: p.delay, '--drift': p.drift, '--turn': p.turn }"
    />
  </div>
</template>

<style scoped>
.confetti {
  animation: fall 1.6s cubic-bezier(0.2, 0.6, 0.4, 1) both;
}
@keyframes fall {
  0% { transform: translate(0, -20px) rotate(0); opacity: 1; }
  100% { transform: translate(var(--drift), 260px) rotate(var(--turn)); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .confetti { display: none; }
}
</style>
