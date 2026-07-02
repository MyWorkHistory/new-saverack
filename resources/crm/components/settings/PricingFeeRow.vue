<script setup>
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import { categoryBadgeClass, formatPrice } from "../../utils/pricingFeeUi.js";

const props = defineProps({
  fee: { type: Object, required: true },
  priceLabel: { type: String, default: "" },
  clickable: { type: Boolean, default: false },
});

const emit = defineEmits(["select"]);

function onClick() {
  if (!props.clickable) return;
  emit("select", props.fee);
}

function onKeydown(e) {
  if (!props.clickable) return;
  if (e.key === "Enter" || e.key === " ") {
    e.preventDefault();
    emit("select", props.fee);
  }
}
</script>

<template>
  <article
    class="pricing-fee-row staff-surface"
    :class="{ 'pricing-fee-row--clickable': clickable }"
    :role="clickable ? 'button' : undefined"
    :tabindex="clickable ? 0 : undefined"
    @click="onClick"
    @keydown="onKeydown"
  >
    <div
      class="pricing-fee-row__icon-wrap rounded d-flex align-items-center justify-content-center flex-shrink-0"
    >
      <img
        v-if="fee.icon_url"
        :src="resolvePublicUrl(fee.icon_url)"
        :alt="fee.name"
        class="pricing-fee-row__icon-img rounded"
      />
      <span v-else class="pricing-fee-row__icon-fallback text-secondary">
        <svg
          v-if="fee.category === 'fulfillment'"
          width="32"
          height="32"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.5"
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
          width="32"
          height="32"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.5"
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
          width="32"
          height="32"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.5"
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
          width="32"
          height="32"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.5"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0-4-4m4 4-4 4" />
        </svg>
        <svg
          v-else
          width="32"
          height="32"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.5"
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

    <div class="pricing-fee-row__body min-w-0 flex-grow-1">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h3 class="pricing-fee-row__name mb-0">{{ fee.name }}</h3>
        <span :class="categoryBadgeClass(fee.category)">
          {{ fee.category_label || fee.category }}
        </span>
      </div>
      <p v-if="fee.description" class="pricing-fee-row__description mb-0">
        {{ fee.description }}
      </p>
      <p v-else class="pricing-fee-row__description mb-0 fst-italic text-secondary">No description</p>
    </div>

    <div class="pricing-fee-row__price flex-shrink-0">
      {{ priceLabel || formatPrice(fee.amount) }}
    </div>
  </article>
</template>

<style scoped>
.pricing-fee-row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1rem 1.125rem;
  border: 1px solid var(--bs-border-color);
  border-radius: 0.5rem;
  background: var(--bs-body-bg, #fff);
}

.pricing-fee-row--clickable {
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.pricing-fee-row--clickable:hover {
  border-color: rgba(115, 103, 240, 0.35);
  box-shadow: 0 0.25rem 0.75rem rgba(47, 43, 61, 0.08);
}

.pricing-fee-row--clickable:focus-visible {
  outline: 2px solid rgba(115, 103, 240, 0.5);
  outline-offset: 2px;
}

.pricing-fee-row__icon-wrap {
  width: 4rem;
  height: 4rem;
  background: var(--bs-secondary-bg);
  overflow: hidden;
}

.pricing-fee-row__icon-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.pricing-fee-row__icon-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

.pricing-fee-row__name {
  font-size: 1rem;
  font-weight: 600;
  color: var(--bs-body-color);
}

.pricing-fee-row__description {
  font-size: 0.875rem;
  color: var(--bs-secondary-color);
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
}

.pricing-fee-row__price {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--bs-body-color);
  padding-top: 0.125rem;
  text-align: right;
  min-width: 4.5rem;
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

.settings-pricing-badge--wholesale {
  color: #1e3a8a;
  background: #dbeafe;
  border-color: #93c5fd;
}

@media (max-width: 575.98px) {
  .pricing-fee-row {
    flex-wrap: wrap;
  }

  .pricing-fee-row__price {
    width: 100%;
    text-align: left;
    padding-top: 0.5rem;
    border-top: 1px solid var(--bs-border-color-translucent, rgba(0, 0, 0, 0.08));
  }
}
</style>
