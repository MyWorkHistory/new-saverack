<script setup>
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { ASN_SUMMARY_CARDS } from "../../constants/asnSummaryCards.js";

defineProps({
  loading: { type: Boolean, default: false },
  activeStatus: { type: String, default: "" },
  values: {
    type: Object,
    default: () => ({
      pending: 0,
      in_progress: 0,
      completed: 0,
      non_compliant: 0,
    }),
  },
});

const emit = defineEmits(["select"]);

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function cardValue(key, values) {
  return nf.format(Number(values?.[key] || 0));
}

function onSelect(status) {
  emit("select", status);
}
</script>

<template>
  <div v-if="loading" class="d-flex justify-content-center py-4">
    <CrmLoadingSpinner message="Loading summary…" />
  </div>
  <div v-else class="row g-3 asn-hub-summary-cards">
    <div v-for="card in ASN_SUMMARY_CARDS" :key="card.key" class="col-12 col-sm-6 col-xl-3">
      <button
        type="button"
        class="staff-stat-card billing-inv-summary-card h-100 text-start w-100"
        :class="{ 'asn-hub-summary-card--active': activeStatus === card.status }"
        @click="onSelect(card.status)"
      >
        <p class="staff-stat-card__label">{{ card.label }}</p>
        <p class="staff-stat-card__value">{{ cardValue(card.key, values) }}</p>
        <p class="staff-stat-card__sub">{{ card.sub }}</p>
        <div
          class="staff-stat-card__icon asn-hub-summary-card__icon"
          :style="card.iconStyle"
          aria-hidden="true"
        >
          <CrmMaterialIcon :name="card.icon" :size="23" />
        </div>
      </button>
    </div>
  </div>
</template>
