<script setup>
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import { categoryBadgeClass, excerpt, formatPrice } from "../../utils/pricingFeeUi.js";

defineProps({
  fee: { type: Object, required: true },
  priceLabel: { type: String, default: "" },
});
</script>

<template>
  <article class="card h-100 staff-surface border-0 shadow-sm">
    <div class="card-body d-flex flex-column">
      <div class="d-flex align-items-start gap-3 mb-2">
        <div
          class="settings-pricing-card__icon-wrap rounded border bg-light d-flex align-items-center justify-content-center flex-shrink-0"
        >
          <img
            v-if="fee.icon_url"
            :src="resolvePublicUrl(fee.icon_url)"
            :alt="fee.name"
            class="rounded"
            style="width: 44px; height: 44px; object-fit: contain"
          />
          <span v-else class="settings-pricing-card__icon-fallback text-secondary text-center px-1">
            <svg
              v-if="fee.category === 'fulfillment'"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
              />
            </svg>
            <svg
              v-else-if="fee.category === 'returns'"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 10h10a4 4 0 014 4v2M3 10l4-4m-4 4l4 4"
              />
            </svg>
            <svg
              v-else-if="fee.category === 'storage'"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v9A2.5 2.5 0 0117.5 19h-11A2.5 2.5 0 014 16.5v-9Z"
              />
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5" />
            </svg>
            <svg
              v-else-if="fee.category === 'receiving'"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-4-4m4 4-4 4" />
            </svg>
            <svg
              v-else
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 7h6m-6 5h6m-6 5h3M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2Z"
              />
            </svg>
          </span>
        </div>
        <div class="flex-grow-1 min-w-0">
          <h2 class="h6 fw-semibold mb-1 text-truncate">{{ fee.name }}</h2>
          <span :class="categoryBadgeClass(fee.category)">{{ fee.category_label || fee.category }}</span>
        </div>
      </div>
      <p v-if="fee.description" class="small text-secondary mb-2 flex-grow-1">
        {{ excerpt(fee.description) }}
      </p>
      <p v-else class="small text-secondary mb-2 flex-grow-1 fst-italic">No description</p>
      <div class="d-flex align-items-center justify-content-between mt-auto pt-2 border-top">
        <span class="fw-semibold text-body">{{ priceLabel || formatPrice(fee.amount) }}</span>
        <slot name="actions" />
      </div>
    </div>
  </article>
</template>

<style scoped>
.settings-pricing-card__icon-wrap {
  width: 48px;
  height: 48px;
  overflow: hidden;
}

.settings-pricing-card__icon-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

.settings-pricing-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 9999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid transparent;
}

.settings-pricing-badge--fulfillment {
  color: #1d4ed8;
  background: #dbeafe;
  border-color: #bfdbfe;
}

.settings-pricing-badge--returns {
  color: #b45309;
  background: #fef3c7;
  border-color: #fde68a;
}

.settings-pricing-badge--storage {
  color: #0f766e;
  background: #ccfbf1;
  border-color: #99f6e4;
}

.settings-pricing-badge--receiving {
  color: #7c2d12;
  background: #ffedd5;
  border-color: #fdba74;
}

.settings-pricing-badge--custom {
  color: #6b21a8;
  background: #f3e8ff;
  border-color: #e9d5ff;
}
</style>
