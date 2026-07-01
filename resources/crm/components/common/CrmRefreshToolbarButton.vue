<script setup>
defineProps({
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  label: { type: String, default: "Refresh" },
  title: { type: String, default: "Refresh" },
});

defineEmits(["click"]);
</script>

<template>
  <button
    type="button"
    class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2 flex-shrink-0"
    :disabled="disabled"
    :title="title"
    :aria-label="title"
    @click="$emit('click')"
  >
    <svg
      width="18"
      height="18"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
      />
    </svg>
    {{ loading ? "Refreshing…" : label }}
  </button>
</template>
