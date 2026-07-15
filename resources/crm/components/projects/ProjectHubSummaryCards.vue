<script setup>
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { PROJECT_SUMMARY_CARDS } from "../../constants/projectSummaryCards.js";

defineProps({
  loading: { type: Boolean, default: false },
  activeStatus: { type: String, default: "" },
  values: {
    type: Object,
    default: () => ({
      pending: 0,
      in_progress: 0,
      completed: 0,
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
    <div
      v-for="card in PROJECT_SUMMARY_CARDS"
      :key="card.key"
      class="col-12 col-sm-6 col-xl-4"
    >
      <button
        type="button"
        class="asn-summary-card h-100 w-100"
        :class="{ 'asn-summary-card--active': activeStatus === card.status }"
        @click="onSelect(card.status)"
      >
        <div class="asn-summary-card__icon" :style="card.iconStyle" aria-hidden="true">
          <CrmMaterialIcon :name="card.icon" :size="22" />
        </div>
        <div class="asn-summary-card__body min-w-0">
          <p class="asn-summary-card__title" :style="{ color: card.titleColor }">
            {{ card.titleUpper }}
          </p>
          <p class="asn-summary-card__sub">{{ card.sub }}</p>
        </div>
        <p class="asn-summary-card__value" :style="{ color: card.titleColor }">
          {{ cardValue(card.key, values) }}
        </p>
      </button>
    </div>
  </div>
</template>
