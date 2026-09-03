<script setup>
import { computed, reactive, ref, watch } from "vue";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  lineItems: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "confirm"]);

const trackingNumber = ref("");
const selected = reactive({});

const items = computed(() => {
  const list = Array.isArray(props.lineItems) && props.lineItems.length
    ? props.lineItems
    : Array.isArray(props.order?.line_items)
      ? props.order.line_items
      : [];
  return list.map((li) => ({
    id: li.id,
    title: li.title || li.name || "Item",
    sku: li.sku || "",
    quantity: Number(li.quantity || li.fulfillable_quantity || 1),
    image_url: li.image_url || li.image || null,
  }));
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    trackingNumber.value = "";
    items.value.forEach((it) => {
      selected[it.id] = true;
    });
  },
);

const orderLabel = computed(() => {
  const n = formatShopifyOrderName(props.order?.name || props.order?.display_name);
  return n ? `Order #${n}` : "Order";
});

const canSubmit = computed(() => items.value.some((it) => selected[it.id]));

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onSubmit() {
  if (!canSubmit.value || props.busy) return;
  emit("confirm", {
    trackingNumber: trackingNumber.value.trim(),
    deductLineIds: items.value.filter((it) => selected[it.id]).map((it) => it.id),
  });
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
      <div class="so-modal so-modal--lg" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>

        <div class="so-modal__head">
          <span class="so-modal__icon so-modal__icon--fulfill" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              <circle cx="18" cy="18" r="4" fill="#16a34a" stroke="#16a34a" />
              <path d="M16.2 18l1.2 1.2 2.4-2.4" stroke="#fff" stroke-width="1.5" stroke-linecap="round" />
            </svg>
          </span>
          <div>
            <h2 class="so-modal__title">Mark Order as Fulfilled?</h2>
            <p class="so-modal__order-ref mb-0">{{ orderLabel }}</p>
          </div>
        </div>

        <p class="so-modal__lead">This will mark all items in this order as fulfilled.</p>

        <label class="form-label fw-semibold" for="so-tracking">Tracking number (optional)</label>
        <div class="so-tracking-wrap mb-1">
          <svg class="so-tracking-wrap__icon" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#9ca3af" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <input
            id="so-tracking"
            v-model="trackingNumber"
            type="text"
            class="form-control so-tracking-wrap__input"
            placeholder="Enter tracking number"
            :disabled="busy"
          />
        </div>
        <p class="small text-secondary mb-3">
          Add a tracking number so shipment progress can be shared with the customer.
        </p>

        <p class="fw-semibold mb-2">Deduct Inventory from the following items:</p>
        <div class="so-item-table">
          <div class="so-item-table__head">
            <span>Item</span>
            <span>QTY</span>
          </div>
          <label
            v-for="it in items"
            :key="it.id"
            class="so-item-table__row"
          >
            <input v-model="selected[it.id]" type="checkbox" class="form-check-input" :disabled="busy" />
            <span class="so-item-table__thumb" aria-hidden="true">
              <img v-if="it.image_url" :src="it.image_url" alt="" />
              <span v-else class="so-item-table__thumb-fallback" />
            </span>
            <span class="so-item-table__meta min-w-0">
              <span class="so-item-table__name">{{ it.title }}</span>
              <span v-if="it.sku" class="so-item-table__sku">{{ it.sku }}</span>
            </span>
            <span class="so-item-table__qty">{{ it.quantity }}</span>
          </label>
          <div v-if="!items.length" class="p-3 small text-secondary">No line items on this order.</div>
        </div>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">Cancel</button>
          <button
            type="button"
            class="btn fw-semibold so-btn-fulfill"
            :disabled="busy || !canSubmit"
            @click="onSubmit"
          >
            {{ busy ? "Fulfilling…" : "Mark as Fulfilled" }}
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
  max-width: 30rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal--lg {
  max-width: 34rem;
}
.so-modal__close {
  position: absolute;
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 1.4rem;
  line-height: 1;
}
.so-modal__head {
  display: flex;
  gap: 0.85rem;
  margin-bottom: 0.65rem;
  padding-right: 1.5rem;
}
.so-modal__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.65rem;
}
.so-modal__icon--fulfill {
  background: #dcfce7;
  color: #15803d;
}
.so-modal__title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}
.so-modal__order-ref {
  color: #2563eb;
  font-size: 0.9rem;
  font-weight: 600;
}
.so-modal__lead {
  margin: 0 0 1rem;
  color: #4b5563;
  font-size: 0.95rem;
}
.so-tracking-wrap {
  position: relative;
}
.so-tracking-wrap__icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
}
.so-tracking-wrap__input {
  padding-left: 2.4rem;
}
.so-item-table {
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  overflow: hidden;
  margin-bottom: 1.15rem;
}
.so-item-table__head {
  display: flex;
  justify-content: space-between;
  padding: 0.45rem 0.9rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #9ca3af;
  text-transform: uppercase;
  border-bottom: 1px solid #f3f4f6;
}
.so-item-table__row {
  display: grid;
  grid-template-columns: auto auto 1fr auto;
  gap: 0.65rem;
  align-items: center;
  padding: 0.7rem 0.9rem;
  border-bottom: 1px solid #f3f4f6;
  margin: 0;
  cursor: pointer;
}
.so-item-table__row:last-child {
  border-bottom: 0;
}
.so-item-table__thumb {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #f3f4f6;
}
.so-item-table__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.so-item-table__thumb-fallback {
  display: block;
  width: 100%;
  height: 100%;
  background: #e5e7eb;
}
.so-item-table__name {
  display: block;
  font-weight: 700;
  font-size: 0.9rem;
  color: #111827;
}
.so-item-table__sku {
  display: block;
  font-size: 0.78rem;
  color: #6b7280;
}
.so-item-table__qty {
  font-weight: 600;
  color: #111827;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
.so-btn-fulfill {
  background: #15803d;
  border-color: #15803d;
  color: #fff !important;
}
.so-btn-fulfill:hover:not(:disabled) {
  background: #166534;
  border-color: #166534;
  color: #fff !important;
}
</style>
