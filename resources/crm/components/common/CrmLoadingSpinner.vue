<script setup>
defineProps({
  message: { type: String, default: "Loading…" },
  center: { type: Boolean, default: false },
});
</script>

<template>
  <div
    :class="[
      'd-inline-flex align-items-center gap-3 text-secondary',
      center ? 'justify-content-center' : '',
    ]"
    role="status"
    aria-live="polite"
  >
    <div class="spinner-border spinner-border-sm text-primary" aria-hidden="true" />
    <span class="small fw-medium text-body">{{ message }}</span>
  </div>
</template>
