<script setup>
import { computed } from "vue";
import PricingFeeRow from "./PricingFeeRow.vue";
import { groupFeesByCategory } from "../../utils/pricingFeeUi.js";

const props = defineProps({
  fees: { type: Array, default: () => [] },
  clickable: { type: Boolean, default: false },
  priceLabelFor: { type: Function, default: null },
});

const emit = defineEmits(["select"]);

const sections = computed(() => groupFeesByCategory(props.fees));

function priceLabel(fee) {
  if (typeof props.priceLabelFor === "function") {
    return props.priceLabelFor(fee) || "";
  }
  return "";
}

function onSelect(fee) {
  emit("select", fee);
}
</script>

<template>
  <div class="pricing-fee-list">
    <section
      v-for="section in sections"
      :key="section.category"
      class="pricing-fee-list__section"
    >
      <header class="pricing-fee-list__section-head">
        <div
          class="pricing-fee-list__section-icon rounded d-flex align-items-center justify-content-center flex-shrink-0"
          :style="{ background: section.meta.headerBg, color: section.meta.accent }"
          aria-hidden="true"
        >
          <svg
            v-if="section.category === 'fulfillment'"
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
            />
          </svg>
          <svg
            v-else-if="section.category === 'returns'"
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M3 10h10a4 4 0 014 4v2M3 10l4-4m-4 4l4 4"
            />
          </svg>
          <svg
            v-else
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M9 7h6m-6 5h6m-6 5h3M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2Z"
            />
          </svg>
        </div>
        <div class="min-w-0">
          <h2
            class="pricing-fee-list__section-title mb-0"
            :style="{ color: section.meta.accent }"
          >
            {{ section.meta.label.toUpperCase() }}
          </h2>
          <p v-if="section.meta.subtitle" class="pricing-fee-list__section-sub mb-0">
            {{ section.meta.subtitle }}
          </p>
        </div>
      </header>

      <div class="pricing-fee-list__rows d-flex flex-column gap-2">
        <PricingFeeRow
          v-for="fee in section.fees"
          :key="fee.id"
          :fee="fee"
          :price-label="priceLabel(fee)"
          :clickable="clickable"
          @select="onSelect"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.pricing-fee-list {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

.pricing-fee-list__section-head {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  margin-bottom: 0.875rem;
}

.pricing-fee-list__section-icon {
  width: 2.25rem;
  height: 2.25rem;
}

.pricing-fee-list__section-title {
  font-size: 0.8125rem;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.pricing-fee-list__section-sub {
  font-size: 0.8125rem;
  color: var(--bs-secondary-color);
  margin-top: 0.125rem;
}
</style>
