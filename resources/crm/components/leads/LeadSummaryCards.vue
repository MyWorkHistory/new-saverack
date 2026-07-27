<script setup>
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { LEAD_SUMMARY_CARDS } from "../../constants/leadSummaryCards.js";

defineProps({
  activeStatus: { type: String, default: "" },
  values: {
    type: Object,
    default: () => ({
      open: 0,
      contacted: 0,
      interested: 0,
      future_opportunity: 0,
      follow_up: 0,
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
  <div class="row g-3 client-accounts-summary-cards mb-4">
    <div
      v-for="card in LEAD_SUMMARY_CARDS"
      :key="card.key"
      class="col-12 col-sm-6 col-xl"
    >
      <button
        type="button"
        class="client-accounts-summary-card h-100 w-100"
        :class="{ 'client-accounts-summary-card--active': activeStatus === card.status }"
        @click="onSelect(card.status)"
      >
        <div class="client-accounts-summary-card__icon" :style="card.iconStyle" aria-hidden="true">
          <CrmMaterialIcon :name="card.icon" :size="22" />
        </div>
        <div class="client-accounts-summary-card__body min-w-0">
          <p class="client-accounts-summary-card__title" :style="{ color: card.titleColor }">
            {{ card.titleUpper }}
          </p>
          <p class="client-accounts-summary-card__sub">{{ card.sub }}</p>
        </div>
        <p class="client-accounts-summary-card__value" :style="{ color: card.titleColor }">
          {{ cardValue(card.key, values) }}
        </p>
      </button>
    </div>
  </div>
</template>
