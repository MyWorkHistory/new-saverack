<script setup>
defineProps({
  label: { type: String, default: "Edit" },
});
defineEmits(["click"]);
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center justify-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/30 dark:border-gray-600 dark:bg-gray-900 dark:text-slate-100 dark:hover:bg-white/5"
    @click="$emit('click')"
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
        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
      />
    </svg>
    <span>{{ label }}</span>
  </button>
</template>
