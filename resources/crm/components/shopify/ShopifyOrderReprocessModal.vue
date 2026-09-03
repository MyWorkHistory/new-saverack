<script setup>
import { computed } from "vue";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "confirm"]);

const orderLabel = computed(() => {
  if (props.orderCount > 1) return `${props.orderCount} orders`;
  const n = formatShopifyOrderName(props.order?.name || props.order?.display_name);
  return n ? `Order #${n}` : "Order";
});

function onClose() {
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
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      @click.self="onClose"
    >
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>

        <div class="so-modal__head">
          <span class="so-modal__icon so-modal__icon--reprocess" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
            </svg>
          </span>
          <div>
            <h2 class="so-modal__title">Reprocess Order?</h2>
            <p class="so-modal__order-ref mb-0">{{ orderLabel }}</p>
          </div>
        </div>

        <p class="so-modal__lead">This will restart processing for this order.</p>

        <ul class="so-reprocess-list">
          <li>Order will be reprocessed</li>
          <li>Inventory allocations will be reassigned</li>
          <li>The order will go through all automations again</li>
        </ul>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">Cancel</button>
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold" :disabled="busy" @click="onConfirm">
            {{ busy ? "Reprocessing…" : "Reprocess Order" }}
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
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 1.4rem;
}
.so-modal__head {
  display: flex;
  gap: 0.85rem;
  margin-bottom: 0.65rem;
}
.so-modal__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.65rem;
}
.so-modal__icon--reprocess {
  background: #dbeafe;
  color: #1d4ed8;
}
.so-modal__title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}
.so-modal__order-ref {
  color: #2563eb;
  font-weight: 600;
  font-size: 0.9rem;
}
.so-modal__lead {
  margin: 0 0 1rem;
  color: #4b5563;
}
.so-reprocess-list {
  list-style: none;
  margin: 0 0 1.15rem;
  padding: 0.9rem 1rem;
  border-radius: 0.65rem;
  background: #eff6ff;
}
.so-reprocess-list li {
  position: relative;
  padding-left: 1.6rem;
  margin-bottom: 0.55rem;
  font-size: 0.92rem;
  color: #1e3a8a;
  font-weight: 600;
}
.so-reprocess-list li:last-child {
  margin-bottom: 0;
}
.so-reprocess-list li::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0.15rem;
  width: 1.1rem;
  height: 1.1rem;
  border-radius: 50%;
  background: #2563eb;
  mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3'%3E%3Cpath stroke-linecap='round' d='M4.5 12.75l6 6 9-13.5'/%3E%3C/svg%3E") center / 0.7rem no-repeat;
  -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3'%3E%3Cpath stroke-linecap='round' d='M4.5 12.75l6 6 9-13.5'/%3E%3C/svg%3E") center / 0.7rem no-repeat;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
</style>
