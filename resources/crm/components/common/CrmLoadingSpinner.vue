<script setup>
import { computed } from "vue";

const props = defineProps({
  message: { type: String, default: "Loading…" },
  center: { type: Boolean, default: false },
  /** Spinner only (no “Loading…” label) for buttons */
  inline: { type: Boolean, default: false },
  /** @deprecated use inline — kept for existing modal buttons */
  small: { type: Boolean, default: false },
});

const showLabel = computed(() => !(props.inline || props.small));
</script>

<template>
  <div
    :class="[
      'd-inline-flex align-items-center text-secondary',
      showLabel ? 'gap-3' : '',
      center ? 'justify-content-center' : '',
    ]"
    role="status"
    aria-live="polite"
  >
    <div class="spinner-border spinner-border-sm text-primary" aria-hidden="true" />
    <span v-if="showLabel" class="small fw-medium text-body">{{ message }}</span>
  </div>
</template>
