<script setup>
import { computed } from "vue";
import { categoryBadgeClass, formatPrice } from "../../utils/pricingFeeUi.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  fee: { type: Object, default: null },
});

const emit = defineEmits(["update:open"]);

const title = computed(() => String(props.fee?.name || "Fee").trim() || "Fee");
const categoryLabel = computed(
  () => String(props.fee?.category_label || props.fee?.category || "").trim(),
);
const price = computed(() =>
  formatPrice(props.fee?.amount, props.fee?.category),
);
const description = computed(() => String(props.fee?.description || "").trim());
const iconUrl = computed(() => {
  const raw = String(props.fee?.icon_url || "").trim();
  return raw ? resolvePublicUrl(raw) || raw : "";
});

function close() {
  emit("update:open", false);
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open && fee"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pricing-fee-info-title"
        @click.self="close"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            @click="close"
          >
            <span aria-hidden="true">&times;</span>
          </button>
          <header class="crm-vx-modal__head">
            <h2 id="pricing-fee-info-title" class="crm-vx-modal__title">{{ title }}</h2>
          </header>
          <div class="crm-vx-modal__body text-start">
            <div class="d-flex align-items-start gap-3 mb-3">
              <div
                v-if="iconUrl"
                class="pricing-fee-info-modal__icon rounded flex-shrink-0 overflow-hidden"
              >
                <img :src="iconUrl" :alt="title" class="w-100 h-100 object-fit-contain" />
              </div>
              <div class="min-w-0">
                <span
                  v-if="categoryLabel"
                  :class="categoryBadgeClass(fee.category)"
                  class="d-inline-block mb-2"
                >
                  {{ categoryLabel }}
                </span>
                <p class="pricing-fee-info-modal__price mb-0">{{ price }}</p>
              </div>
            </div>
            <p v-if="description" class="mb-0 text-body notes-pre-wrap">{{ description }}</p>
            <p v-else class="mb-0 text-secondary fst-italic">No description</p>
          </div>
          <footer class="crm-vx-modal__footer d-flex justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" @click="close">
              Close
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.pricing-fee-info-modal__icon {
  width: 3.5rem;
  height: 3.5rem;
  background: #f8fafc;
}

.pricing-fee-info-modal__price {
  font-size: 1.5rem;
  font-weight: 700;
  color: #2563eb;
  line-height: 1.2;
}

.notes-pre-wrap {
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
