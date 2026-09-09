<script setup>
import { computed, watch } from "vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  productTitle: { type: String, default: "" },
  sku: { type: String, default: "" },
  imageUrl: { type: String, default: "" },
  fromName: { type: String, default: "" },
  available: { type: Number, default: 0 },
  toLocationId: { type: [String, Number], default: "" },
  quantity: { type: [String, Number], default: "0" },
  reason: { type: String, default: "" },
  locations: { type: Array, default: () => [] },
  reasons: { type: Array, default: () => [] },
});

const emit = defineEmits([
  "close",
  "submit",
  "all",
  "update:toLocationId",
  "update:quantity",
  "update:reason",
]);

const locationOptions = computed(() =>
  (props.locations || []).map((loc) => ({
    id: Number(loc.id),
    name: String(loc.name || loc.id),
  })),
);

const toModel = computed({
  get: () => (props.toLocationId === "" || props.toLocationId == null ? "" : String(props.toLocationId)),
  set: (v) => emit("update:toLocationId", v == null ? "" : String(v)),
});

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen && !String(props.reason || "").trim() && props.reasons.length) {
      const restock = props.reasons.find((r) => r === "Restock");
      emit("update:reason", String(restock || props.reasons[0]));
    }
  },
);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm shopify-xfer-modal" @click.stop>
        <button type="button" class="crm-vx-modal__close" aria-label="Close" :disabled="busy" @click="emit('close')">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <header class="crm-vx-modal__head shopify-xfer-modal__head">
          <h2 class="crm-vx-modal__title shopify-xfer-modal__title">Transfer Inventory</h2>
        </header>
        <div class="crm-vx-modal__body shopify-xfer-modal__body">
          <div class="shopify-xfer-product">
            <div class="shopify-xfer-product__thumb" aria-hidden="true">
              <img v-if="imageUrl" :src="imageUrl" alt="" />
              <span v-else class="shopify-xfer-product__thumb-empty" />
            </div>
            <div class="min-w-0">
              <div class="shopify-xfer-product__name">{{ productTitle || "—" }}</div>
              <div class="shopify-xfer-product__sku">SKU: {{ sku || "—" }}</div>
            </div>
          </div>

          <section class="shopify-xfer-from">
            <h3 class="shopify-xfer-from__title">Transfer From</h3>
            <p class="shopify-xfer-from__sub">Current location and quantity</p>
            <div class="shopify-xfer-from__info">
              <div>
                <div class="shopify-xfer-from__info-label">Location</div>
                <div class="shopify-xfer-from__info-loc">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                  </svg>
                  <span>{{ fromName || "—" }}</span>
                </div>
              </div>
              <div class="shopify-xfer-from__info-right">
                <div class="shopify-xfer-from__info-label">Current QTY</div>
                <div class="shopify-xfer-from__info-qty">{{ Number(available || 0).toLocaleString("en-US") }}</div>
              </div>
            </div>
          </section>

          <div class="shopify-xfer-arrow" aria-hidden="true">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m0 0l-5-5m5 5l5-5" />
            </svg>
          </div>

          <section class="shopify-xfer-card">
            <div class="shopify-xfer-card__icon shopify-xfer-card__icon--to" aria-hidden="true">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
              </svg>
            </div>
            <div class="min-w-0 flex-grow-1">
              <h3 class="shopify-xfer-card__title">Transfer To</h3>
              <p class="shopify-xfer-card__sub">Select a new location and quantity</p>
              <label class="shopify-xfer-card__label">Location</label>
              <CrmSearchableSelect
                v-model="toModel"
                class="mb-3"
                appearance="staff"
                aria-label="Select destination location"
                :options="locationOptions"
                :disabled="busy"
                :allow-empty="true"
                placeholder="Select location"
                empty-label="Select location"
                search-placeholder="Search locations…"
                teleport-panel
              />
              <label class="shopify-xfer-card__label" for="shopify-xfer-qty">Quantity</label>
              <div class="d-flex gap-2 mb-3">
                <input
                  id="shopify-xfer-qty"
                  class="form-control"
                  type="number"
                  min="1"
                  :value="quantity"
                  :disabled="busy"
                  @input="emit('update:quantity', $event.target.value)"
                />
                <button
                  type="button"
                  class="btn shopify-xfer-all"
                  :disabled="busy"
                  @click="emit('all')"
                >
                  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                  All
                </button>
              </div>
              <label class="shopify-xfer-card__label" for="shopify-xfer-reason">Reason</label>
              <select
                id="shopify-xfer-reason"
                class="form-select"
                :value="reason"
                :disabled="busy"
                @change="emit('update:reason', $event.target.value)"
              >
                <option value="">Select reason</option>
                <option v-for="r in reasons" :key="r" :value="r">{{ r }}</option>
              </select>
            </div>
          </section>
        </div>
        <footer class="crm-vx-modal__footer shopify-xfer-modal__footer">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">
            Cancel
          </button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="emit('submit')">
            {{ busy ? "Please Wait…" : "Transfer" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.shopify-xfer-modal :deep(.crm-vx-modal__head),
.shopify-xfer-modal__head {
  text-align: left;
  padding: 1.35rem 1.5rem 0.5rem;
}
.shopify-xfer-modal__title {
  color: #0f172a;
  font-size: 1.15rem;
}
.shopify-xfer-modal__body {
  padding: 0.75rem 1.5rem 0.5rem;
}
.shopify-xfer-product {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 0.75rem;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 0.75rem;
  background: #f8fafc;
}
.shopify-xfer-product__thumb {
  width: 48px;
  height: 48px;
  border-radius: 0.5rem;
  overflow: hidden;
  background: #e2e8f0;
  flex-shrink: 0;
}
.shopify-xfer-product__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.shopify-xfer-product__thumb-empty {
  display: block;
  width: 100%;
  height: 100%;
  background: #cbd5e1;
}
.shopify-xfer-product__name {
  font-weight: 700;
  color: #0f172a;
}
.shopify-xfer-product__sku {
  font-size: 0.8125rem;
  color: #64748b;
}
.shopify-xfer-from {
  margin-bottom: 0.25rem;
}
.shopify-xfer-from__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
}
.shopify-xfer-from__sub {
  margin: 0.15rem 0 0.65rem;
  font-size: 0.8rem;
  color: #64748b;
}
.shopify-xfer-from__info {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.9rem 1rem;
  border-radius: 0.65rem;
  background: #eff6ff;
  border: 1px solid #dbeafe;
}
.shopify-xfer-from__info-label {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}
.shopify-xfer-from__info-loc {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 700;
  color: #0f172a;
}
.shopify-xfer-from__info-loc svg {
  color: #2563eb;
  flex-shrink: 0;
}
.shopify-xfer-from__info-right {
  text-align: right;
}
.shopify-xfer-from__info-qty {
  font-size: 1.45rem;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.1;
}
.shopify-xfer-card {
  display: flex;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 0.85rem;
  background: #fff;
}
.shopify-xfer-card__icon {
  width: 40px;
  height: 40px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: #fff;
}
.shopify-xfer-card__icon--to {
  background: #16a34a;
}
.shopify-xfer-card__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
}
.shopify-xfer-card__sub {
  margin: 0 0 0.75rem;
  font-size: 0.8rem;
  color: #64748b;
}
.shopify-xfer-card__label {
  font-size: 0.75rem;
  color: #64748b;
  margin-bottom: 0.2rem;
  display: block;
}
.shopify-xfer-arrow {
  width: 36px;
  height: 36px;
  margin: -0.35rem auto;
  border-radius: 999px;
  background: #dcfce7;
  color: #16a34a;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  z-index: 1;
}
.shopify-xfer-all {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
  border: 1px solid rgba(37, 99, 235, 0.35);
  color: #2563eb;
  font-weight: 700;
  background: #fff;
}
.shopify-xfer-modal__footer {
  justify-content: flex-end;
  padding: 1rem 1.5rem 1.35rem;
}
</style>
