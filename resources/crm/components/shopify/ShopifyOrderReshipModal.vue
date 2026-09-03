<script setup>
import { computed, reactive, watch } from "vue";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  lineItems: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "confirm"]);

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
    quantity: Number(li.quantity || 1),
    image_url: li.image_url || li.image || null,
  }));
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
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
  emit(
    "confirm",
    items.value.filter((it) => selected[it.id]).map((it) => it.id),
  );
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
          <span class="so-modal__icon so-modal__icon--reship" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M16 18a3 3 0 106-0 3 3 0 00-6 0zm3-2v2m0 0v2" />
            </svg>
          </span>
          <div>
            <h2 class="so-modal__title">Re-Ship Order</h2>
            <p class="so-modal__order-ref mb-0">{{ orderLabel }}</p>
          </div>
        </div>

        <p class="so-modal__lead">Select the items you want to re-ship.</p>

        <div class="so-item-table">
          <div class="so-item-table__head">
            <span>Item</span>
            <span>QTY</span>
          </div>
          <label v-for="it in items" :key="it.id" class="so-item-table__row">
            <input v-model="selected[it.id]" type="checkbox" class="form-check-input" :disabled="busy" />
            <span class="so-item-table__thumb">
              <img v-if="it.image_url" :src="it.image_url" alt="" />
              <span v-else class="so-item-table__thumb-fallback" />
            </span>
            <span class="so-item-table__meta min-w-0">
              <span class="so-item-table__name">{{ it.title }}</span>
              <span v-if="it.sku" class="so-item-table__sku">{{ it.sku }}</span>
            </span>
            <span class="so-item-table__qty">{{ it.quantity }}</span>
          </label>
        </div>

        <div class="so-info-banner">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          Inventory will be deducted once the order is shipped.
        </div>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">Cancel</button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="busy || !canSubmit"
            @click="onSubmit"
          >
            {{ busy ? "Creating…" : "Create Re-Shipment" }}
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
  max-width: 34rem;
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
.so-modal__icon--reship {
  background: #dbeafe;
  color: #1d4ed8;
}
.so-modal__title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}
.so-modal__order-ref {
  color: #6b7280;
  font-size: 0.9rem;
}
.so-modal__lead {
  margin: 0 0 1rem;
  color: #4b5563;
}
.so-item-table {
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  overflow: hidden;
  margin-bottom: 1rem;
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
}
.so-item-table__sku {
  display: block;
  font-size: 0.78rem;
  color: #6b7280;
}
.so-item-table__qty {
  font-weight: 600;
}
.so-info-banner {
  display: flex;
  gap: 0.55rem;
  padding: 0.75rem 0.9rem;
  border-radius: 0.55rem;
  background: #fffbeb;
  color: #92400e;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
</style>
