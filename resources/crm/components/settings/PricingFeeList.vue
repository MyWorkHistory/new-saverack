<script setup>
import { computed } from "vue";
import PricingFeeRow from "./PricingFeeRow.vue";
import { groupFeesByCategory } from "../../utils/pricingFeeUi.js";

const props = defineProps({
  fees: { type: Array, default: () => [] },
  clickable: { type: Boolean, default: false },
  /**
   * default — Settings / Portal (soft section headers, descriptions on rows)
   * schedule — Account / Lead fees (banner headers, compact rows)
   */
  variant: {
    type: String,
    default: "default",
    validator: (v) => ["default", "schedule"].includes(v),
  },
  /** Only used when variant is default; schedule always hides list descriptions. */
  showDescription: { type: Boolean, default: undefined },
  priceLabelFor: { type: Function, default: null },
  /** Override category sort order (e.g. portal: Fulfillment last). */
  categoryOrder: { type: Array, default: null },
});

const emit = defineEmits(["select"]);

const isSchedule = computed(() => props.variant === "schedule");

const sections = computed(() => groupFeesByCategory(props.fees, props.categoryOrder));

const rowShowDescription = computed(() => {
  if (isSchedule.value) return false;
  if (props.showDescription !== undefined) return props.showDescription;
  return true;
});

function priceLabel(fee) {
  if (typeof props.priceLabelFor === "function") {
    return props.priceLabelFor(fee) || "";
  }
  return "";
}

function onSelect(fee) {
  emit("select", fee);
}

function categoryIcon(category) {
  switch (category) {
    case "fulfillment":
      return "fulfillment";
    case "returns":
      return "returns";
    case "storage":
      return "storage";
    case "receiving":
      return "receiving";
    default:
      return "other";
  }
}
</script>

<template>
  <div class="pricing-fee-list" :class="{ 'pricing-fee-list--schedule': isSchedule }">
    <section
      v-for="section in sections"
      :key="section.category"
      class="pricing-fee-list__section"
    >
      <!-- Settings / Portal: soft section head -->
      <header
        v-if="!isSchedule"
        class="pricing-fee-list__section-head"
      >
        <div
          class="pricing-fee-list__section-icon rounded d-flex align-items-center justify-content-center flex-shrink-0"
          :style="{ background: section.meta.headerBg, color: section.meta.accent }"
          aria-hidden="true"
        >
          <svg
            v-if="categoryIcon(section.category) === 'fulfillment'"
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
            v-else-if="categoryIcon(section.category) === 'returns'"
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
            v-else-if="categoryIcon(section.category) === 'storage'"
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
              d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9A2.5 2.5 0 0117.5 19h-11A2.5 2.5 0 014 16.5v-9Z"
            />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5" />
          </svg>
          <svg
            v-else-if="categoryIcon(section.category) === 'receiving'"
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-4-4m4 4-4 4" />
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

      <!-- Account / Lead schedule: colored banners -->
      <header
        v-else
        class="pricing-fee-list__banner"
        :style="{ background: section.meta.accent }"
      >
        <div
          class="pricing-fee-list__banner-icon rounded d-flex align-items-center justify-content-center flex-shrink-0"
          aria-hidden="true"
        >
          <svg
            v-if="categoryIcon(section.category) === 'fulfillment'"
            width="20"
            height="20"
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
            v-else-if="categoryIcon(section.category) === 'returns'"
            width="20"
            height="20"
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
            v-else-if="categoryIcon(section.category) === 'storage'"
            width="20"
            height="20"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9A2.5 2.5 0 0117.5 19h-11A2.5 2.5 0 014 16.5v-9Z"
            />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5" />
          </svg>
          <svg
            v-else-if="categoryIcon(section.category) === 'receiving'"
            width="20"
            height="20"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.75"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-4-4m4 4-4 4" />
          </svg>
          <svg
            v-else
            width="20"
            height="20"
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
          <h2 class="pricing-fee-list__banner-title mb-0">
            {{ section.meta.label.toUpperCase() }} FEES
          </h2>
          <p v-if="section.meta.subtitle" class="pricing-fee-list__banner-sub mb-0">
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
          :show-description="rowShowDescription"
          :emphasize-price="isSchedule"
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

.pricing-fee-list__banner {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 1rem 1.15rem;
  border-radius: 0.65rem;
  color: #fff;
  margin-bottom: 0.75rem;
  position: relative;
  overflow: hidden;
}

.pricing-fee-list__banner::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(
    115deg,
    transparent 45%,
    rgba(255, 255, 255, 0.08) 45%,
    rgba(255, 255, 255, 0.08) 55%,
    transparent 55%
  );
  pointer-events: none;
}

.pricing-fee-list__banner-icon {
  width: 2.5rem;
  height: 2.5rem;
  background: rgba(255, 255, 255, 0.18);
  color: #fff;
  position: relative;
  z-index: 1;
}

.pricing-fee-list__banner-title {
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  position: relative;
  z-index: 1;
}

.pricing-fee-list__banner-sub {
  font-size: 0.8125rem;
  opacity: 0.92;
  margin-top: 0.15rem;
  position: relative;
  z-index: 1;
}
</style>
