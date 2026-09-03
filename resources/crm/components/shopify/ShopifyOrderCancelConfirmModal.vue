<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "confirm"]);

const cancelInShopify = ref(false);

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) cancelInShopify.value = false;
  },
);

const title = computed(() =>
  props.orderCount > 1 ? `Cancel ${props.orderCount} Orders?` : "Cancel Order?",
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onConfirm() {
  if (props.busy) return;
  emit("confirm", { cancelInShopify: cancelInShopify.value });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shopify-cancel-title"
      @click.self="onClose"
    >
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <div class="so-modal__head">
          <span class="so-modal__icon so-modal__icon--cancel" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              <circle cx="18" cy="18" r="4" fill="#fee2e2" stroke="#dc2626" />
              <path d="M16.5 18h3" stroke="#dc2626" stroke-width="1.5" stroke-linecap="round" />
            </svg>
          </span>
          <div>
            <h2 id="shopify-cancel-title" class="so-modal__title">{{ title }}</h2>
          </div>
        </div>

        <p class="so-modal__lead">
          Canceling this order will cancel 3PL fulfillment of all items within the order.
        </p>

        <label class="so-cancel-shopify">
          <input v-model="cancelInShopify" type="checkbox" class="form-check-input" :disabled="busy" />
          <span>
            <strong>Cancel entire order in Shopify</strong>
            <span class="so-cancel-shopify__hint">This will also update the order status in your Shopify store.</span>
          </span>
        </label>

        <footer class="so-modal__foot">
          <button
            type="button"
            class="btn btn-outline-secondary orders-toolbar-outline-btn"
            :disabled="busy"
            @click="onClose"
          >
            Keep Order
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--danger"
            :disabled="busy"
            @click="onConfirm"
          >
            {{ busy ? "Canceling…" : "Cancel Order" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.so-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.so-modal {
  position: relative;
  width: 100%;
  max-width: 28rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal__close {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  width: 2rem;
  height: 2rem;
}
.so-modal__head {
  display: flex;
  gap: 0.85rem;
  align-items: center;
  margin-bottom: 0.85rem;
  padding-right: 1.5rem;
}
.so-modal__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.65rem;
  flex-shrink: 0;
}
.so-modal__icon--cancel {
  background: #fee2e2;
  color: #dc2626;
}
.so-modal__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
}
.so-modal__lead {
  margin: 0 0 1rem;
  font-size: 0.95rem;
  color: #4b5563;
}
.so-cancel-shopify {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.9rem 1rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  background: #f9fafb;
  margin-bottom: 1.15rem;
  cursor: pointer;
}
.so-cancel-shopify strong {
  display: block;
  color: #111827;
  font-size: 0.95rem;
}
.so-cancel-shopify__hint {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.82rem;
  color: #6b7280;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
</style>
