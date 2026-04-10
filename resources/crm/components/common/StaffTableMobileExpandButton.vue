<script setup>
defineProps({
  ariaLabel: { type: String, default: "Show row details" },
});

defineEmits(["click"]);
</script>

<template>
  <button
    type="button"
    class="staff-table-mobile-expand-btn"
    :aria-label="ariaLabel"
    @click="$emit('click')"
  >
    <svg
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="currentColor"
      aria-hidden="true"
    >
      <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
    </svg>
  </button>
</template>
