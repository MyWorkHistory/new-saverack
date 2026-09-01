<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "confirm"]);

const title = computed(() =>
  props.orderCount > 1 ? `Cancel ${props.orderCount} Orders` : "Cancel Order",
);

function onBackdropClick() {
  if (props.busy) return;
  emit("close");
}

function onCloseClick() {
  if (props.busy) return;
  emit("close");
}

function onConfirm() {
  if (props.busy) return;
  emit("confirm");
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
        aria-labelledby="shopify-order-cancel-modal-title"
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
                id="shopify-order-cancel-modal-title"
                class="crm-vx-modal__title mb-3"
              >
                {{ title }}
              </h2>
              <p class="small text-secondary mb-0">
                This will cancel the entire order and update the Shopify store.
              </p>
            </div>
            <div class="crm-vx-modal__footer">
              <button
                type="button"
                class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
                :disabled="busy"
                @click="onCloseClick"
              >
                Keep Order
              </button>
              <button
                type="button"
                class="btn btn-danger fw-semibold"
                :disabled="busy"
                @click="onConfirm"
              >
                {{ busy ? "Canceling…" : "Cancel Order" }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
