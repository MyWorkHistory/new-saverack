<script setup>
import { HOLD_SECTIONS } from "../../constants/holdSummaryCards.js";

const props = defineProps({
  getTotalCount: { type: Function, required: true },
});

const emit = defineEmits(["select"]);

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function formatCount(key) {
  return nf.format(Number(props.getTotalCount(key) || 0));
}

function onSelect(key) {
  emit("select", key);
}
</script>

<template>
  <div class="row g-3 on-hold-summary-cards mb-4">
    <div
      v-for="section in HOLD_SECTIONS"
      :key="section.key"
      class="col-6 col-md-4 col-xl-2"
    >
      <button
        type="button"
        class="staff-stat-card on-hold-summary-card h-100 w-100"
        :style="{ '--on-hold-card-accent': section.cardStyle.color, '--on-hold-card-tint': section.cardStyle.background }"
        @click="onSelect(section.key)"
      >
        <p class="on-hold-summary-card__label">{{ section.label }}</p>
        <p class="on-hold-summary-card__value">{{ formatCount(section.key) }}</p>
      </button>
    </div>
  </div>
</template>
