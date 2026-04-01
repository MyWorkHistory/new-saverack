<script setup>
import { RouterLink } from "vue-router";

defineProps({
  to: { type: String, required: true },
  label: { type: String, required: true },
});
</script>

<template>
  <RouterLink
    :to="to"
    class="inline-flex items-center justify-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/30 dark:border-gray-600 dark:bg-gray-900 dark:text-slate-100 dark:hover:bg-white/5"
  >
    <svg
      class="h-4 w-4 shrink-0"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M15 19l-7-7 7-7"
      />
    </svg>
    <span>{{ label }}</span>
  </RouterLink>
</template>
