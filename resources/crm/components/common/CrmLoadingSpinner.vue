<script setup>
defineProps({
  /** Shown next to the spinner ring (e.g. "Loading...", "Loading users…"). */
  message: { type: String, default: "Loading..." },
  /** When true, the block is centered in its container (use for full-page shells). */
  center: { type: Boolean, default: false },
});
</script>

<template>
  <div
    :class="[
      'inline-flex items-center gap-3 text-gray-600 dark:text-gray-400',
      center ? 'justify-center' : '',
    ]"
    role="status"
    aria-live="polite"
  >
    <div
      class="h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-gray-200 border-t-blue-600 dark:border-gray-600 dark:border-t-blue-500"
      aria-hidden="true"
    />
    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{
      message
    }}</span>
  </div>
</template>
