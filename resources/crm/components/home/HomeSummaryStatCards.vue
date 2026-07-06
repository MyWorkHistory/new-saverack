<script setup>
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { HOME_SUMMARY_CARDS } from "../../constants/homeSummaryCards.js";

const props = defineProps({
  totals: { type: Object, default: () => ({}) },
  sections: { type: Object, default: () => ({}) },
  restockActiveCount: { type: Number, default: 0 },
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function cardValue(card) {
  if (card.valueSource === "restock_active_count") {
    return Number(props.restockActiveCount || 0);
  }
  if (card.valueSource === "sections") {
    const section = props.sections?.[card.valueKey];
    return Number(section?.total_count || 0);
  }
  return Number(props.totals?.[card.valueKey] || 0);
}

function formatValue(card) {
  return nf.format(cardValue(card));
}
</script>

<template>
  <div class="row g-3 home-summary-stat-cards mb-4">
    <div
      v-for="card in HOME_SUMMARY_CARDS"
      :key="card.key"
      class="col-6 col-md-4 col-xl-2"
    >
      <RouterLink
        :to="card.to"
        class="staff-datatable-card staff-datatable-card--white home-stat-card h-100 text-decoration-none text-body"
      >
        <div class="home-stat-card__icon" :style="card.iconStyle" aria-hidden="true">
          <CrmMaterialIcon :name="card.icon" :size="24" />
        </div>
        <p class="home-stat-card__value" :style="{ color: card.titleColor }">
          {{ formatValue(card) }}
        </p>
        <p class="home-stat-card__label" :style="{ color: card.titleColor }">
          {{ card.label }}
        </p>
        <p class="home-stat-card__sub">{{ card.sub }}</p>
        <span class="home-stat-card__chevron text-secondary" aria-hidden="true">
          <CrmMaterialIcon name="chevronRight" :size="20" />
        </span>
      </RouterLink>
    </div>
  </div>
</template>
