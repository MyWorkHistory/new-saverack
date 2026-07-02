<script setup>
const props = defineProps({
  checked: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle"]);

function onClick() {
  if (props.disabled || props.loading) return;
  emit("toggle", !props.checked);
}
</script>

<template>
  <button
    type="button"
    class="portal-onboard-field-verify btn btn-link p-0 border-0 align-middle"
    :class="checked ? 'portal-onboard-field-verify--checked text-success' : 'text-secondary'"
    :disabled="disabled || loading"
    :aria-pressed="checked"
    :aria-label="checked ? 'Verified' : 'Mark as verified'"
    @click="onClick"
  >
    <svg
      class="portal-onboard-field-verify__icon"
      width="22"
      height="22"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="10" />
      <path
        v-if="checked"
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M8 12.5l2.5 2.5L16 9"
      />
    </svg>
  </button>
</template>

<style scoped>
.portal-onboard-field-verify {
  line-height: 1;
  text-decoration: none;
}

.portal-onboard-field-verify:hover:not(:disabled) {
  opacity: 0.85;
}

.portal-onboard-field-verify--checked .portal-onboard-field-verify__icon circle {
  stroke: currentColor;
}

.portal-onboard-field-verify:not(.portal-onboard-field-verify--checked) .portal-onboard-field-verify__icon circle {
  stroke: currentColor;
  fill: transparent;
}
</style>
