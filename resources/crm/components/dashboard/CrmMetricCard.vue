<script setup>
import { computed } from "vue";

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  changePct: { type: Number, default: null },
  /** e.g. "From last month" / "vs yesterday" */
  periodLabel: { type: String, default: "From last month" },
  /** When false, hide the % pill (e.g. insufficient baseline). */
  showChange: { type: Boolean, default: true },
});

const badgeClass = computed(() => {
  if (props.changePct === null || Number.isNaN(props.changePct)) {
    return "bg-gray-100 text-gray-600";
  }
  return props.changePct >= 0
    ? "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"
    : "bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-400";
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
  <div
    class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p
          class="text-sm font-medium text-gray-500 dark:text-gray-400"
        >
          {{ label }}
        </p>
        <p
          class="mt-2 truncate text-2xl font-bold tracking-tight text-gray-900 dark:text-white"
        >
          {{ value }}
        </p>
      </div>
      <div
        v-if="showChange"
        class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="badgeClass"
      >
        {{ badgeText }}
      </div>
    </div>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
      {{ periodLabel }}
    </p>
  </div>
</template>
