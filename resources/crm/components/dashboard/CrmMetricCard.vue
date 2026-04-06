<script setup>
import { computed } from "vue";

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  changePct: { type: Number, default: null },
  periodLabel: { type: String, default: "From last month" },
  showChange: { type: Boolean, default: true },
});

const badgeClass = computed(() => {
  if (props.changePct === null || Number.isNaN(props.changePct)) {
    return "bg-body-secondary text-body-secondary";
  }
  return props.changePct >= 0
    ? "bg-success-subtle text-success"
    : "bg-danger-subtle text-danger";
});

const badgeText = computed(() => {
  if (props.changePct === null || Number.isNaN(props.changePct)) {
    return "—";
  }
  const n = props.changePct;
  return `${n >= 0 ? "+" : ""}${n}%`;
});
</script>

<template>
  <div class="vx-card p-4 h-100">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div class="min-w-0 flex-grow-1">
        <p class="small fw-medium text-secondary mb-0">
          {{ label }}
        </p>
        <p class="mt-2 mb-0 fs-3 fw-bold text-body text-truncate">
          {{ value }}
        </p>
      </div>
      <div
        v-if="showChange"
        class="flex-shrink-0 rounded-pill px-2 py-1 small fw-semibold"
        :class="badgeClass"
      >
        {{ badgeText }}
      </div>
    </div>
    <p class="mt-2 mb-0 small text-secondary">
      {{ periodLabel }}
    </p>
  </div>
</template>
