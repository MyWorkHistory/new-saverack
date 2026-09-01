<script setup>
import { computed, reactive, watch } from "vue";
import { SHOPIFY_ORDER_HOLD_REASONS } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "confirm"]);

const selected = reactive({});

function resetSelection() {
  SHOPIFY_ORDER_HOLD_REASONS.forEach((label) => {
    selected[label] = false;
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetSelection();
  },
);

const canSubmit = computed(() => SHOPIFY_ORDER_HOLD_REASONS.some((label) => selected[label]));

const title = computed(() =>
  props.orderCount > 1 ? `Hold ${props.orderCount} Orders` : "Hold Order",
);

function onBackdropClick() {
  if (props.busy) return;
  emit("close");
}

function onCloseClick() {
  if (props.busy) return;
  emit("close");
}

function onSubmit() {
  if (!canSubmit.value || props.busy) return;
  const reasons = SHOPIFY_ORDER_HOLD_REASONS.filter((label) => selected[label]);
  emit("confirm", reasons);
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="shopify-order-hold-modal-title"
      >
        <div
          class="crm-vx-modal-backdrop"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition
          name="modal-panel"
          appear
        >
          <div class="crm-vx-modal crm-vx-modal--sm">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
              @click="onCloseClick"
            >
              ×
            </button>
            <div class="crm-vx-modal__body">
              <h2
                id="shopify-order-hold-modal-title"
                class="crm-vx-modal__title mb-3"
              >
                {{ title }}
              </h2>
              <p class="small text-secondary mb-3">
                Select one or more hold reasons. The order will appear as On Hold in this list.
              </p>
              <div class="d-flex flex-column gap-2">
                <label
                  v-for="label in SHOPIFY_ORDER_HOLD_REASONS"
                  :key="label"
                  class="form-check mb-0"
                >
                  <input
                    v-model="selected[label]"
                    class="form-check-input"
                    type="checkbox"
                    :disabled="busy"
                  >
                  <span class="form-check-label">{{ label }}</span>
                </label>
              </div>
            </div>
            <div class="crm-vx-modal__footer">
              <button
                type="button"
                class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
                :disabled="busy"
                @click="onCloseClick"
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn btn-primary staff-page-primary fw-semibold"
                :disabled="busy || !canSubmit"
                @click="onSubmit"
              >
                {{ busy ? "Applying…" : "Apply Hold" }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
