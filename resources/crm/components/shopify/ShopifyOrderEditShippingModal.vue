<script setup>
import { computed, ref, watch } from "vue";
import ShopifyCarrierLogo from "./ShopifyCarrierLogo.vue";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const CARRIERS = [
  { value: "UPS", label: "UPS" },
  { value: "USPS", label: "USPS" },
  { value: "FEDEX", label: "FedEx" },
  { value: "DHL", label: "DHL" },
];

const SERVICES = {
  UPS: ["UPS Ground", "UPS 2nd Day Air", "UPS Next Day Air", "UPS SurePost"],
  USPS: ["USPS Ground Advantage", "USPS Priority Mail", "USPS Priority Mail Express", "USPS Media Mail"],
  FEDEX: ["FedEx Ground", "FedEx Express Saver", "FedEx 2Day", "FedEx Standard Overnight"],
  DHL: ["DHL Express Worldwide", "DHL Express 12:00", "DHL Ecommerce"],
};

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  shipping: { type: Object, default: null },
});

const emit = defineEmits(["close", "confirm"]);

const carrier = ref("UPS");
const service = ref("UPS Ground");
const carrierMenuOpen = ref(false);

const orderLabel = computed(() => formatShopifyOrderName(props.order?.name || props.order?.display_name) || "—");
const serviceOptions = computed(() => SERVICES[carrier.value] || []);
const selectedCarrier = computed(() => CARRIERS.find((c) => c.value === carrier.value) || CARRIERS[0]);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    carrierMenuOpen.value = false;
    const s = props.shipping || {};
    const c = String(s.carrier || "").toUpperCase();
    carrier.value = CARRIERS.some((x) => x.value === c) ? c : "UPS";
    const svc = String(s.service || s.requested || "").trim();
    const opts = SERVICES[carrier.value] || [];
    service.value = opts.includes(svc) ? svc : opts[0] || "";
  },
);

watch(carrier, (c) => {
  const opts = SERVICES[c] || [];
  if (!opts.includes(service.value)) service.value = opts[0] || "";
});

function pickCarrier(value) {
  carrier.value = value;
  carrierMenuOpen.value = false;
}

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onConfirm() {
  if (props.busy) return;
  emit("confirm", {
    carrier: carrier.value,
    service: service.value,
    price: props.shipping?.price ?? null,
  });
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="so-modal-overlay" role="dialog" aria-modal="true" @click.self="onClose">
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>
        <h2 class="so-modal__title mb-1">Edit Shipping Method</h2>
        <p class="so-modal__lead">Update the shipping method for Order #{{ orderLabel }}</p>

        <label class="form-label fw-semibold">Carrier</label>
        <div class="position-relative mb-3">
          <button
            type="button"
            class="so-carrier-select"
            :disabled="busy"
            @click="carrierMenuOpen = !carrierMenuOpen"
          >
            <ShopifyCarrierLogo :carrier="selectedCarrier.value" :size="28" />
            <span class="fw-semibold">{{ selectedCarrier.label }}</span>
            <svg class="ms-auto" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div v-if="carrierMenuOpen" class="so-carrier-menu">
            <button
              v-for="c in CARRIERS"
              :key="c.value"
              type="button"
              class="so-carrier-menu__item"
              :class="{ 'is-active': carrier === c.value }"
              @click="pickCarrier(c.value)"
            >
              <ShopifyCarrierLogo :carrier="c.value" :size="28" />
              <span>{{ c.label }}</span>
            </button>
          </div>
        </div>

        <label class="form-label fw-semibold">Service</label>
        <select v-model="service" class="form-select mb-4" :disabled="busy">
          <option v-for="s in serviceOptions" :key="s" :value="s">{{ s }}</option>
        </select>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-primary fw-semibold" :disabled="busy" @click="onClose">Cancel</button>
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold" :disabled="busy" @click="onConfirm">
            {{ busy ? "Updating…" : "Update Shipping Method" }}
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
.so-modal__title { margin: 0; font-size: 1.25rem; font-weight: 700; color: #111827; }
.so-modal__lead { margin: 0 0 1.15rem; color: #6b7280; font-size: 0.92rem; }
.so-modal__foot { display: flex; justify-content: flex-end; gap: 0.55rem; }
.so-carrier-select {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 0.5rem;
  padding: 0.55rem 0.75rem;
  text-align: left;
}
.so-carrier-select:hover { border-color: #93c5fd; }
.so-carrier-menu {
  position: absolute;
  z-index: 5;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
  overflow: hidden;
}
.so-carrier-menu__item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  border: 0;
  background: #fff;
  padding: 0.6rem 0.75rem;
  font-weight: 600;
  text-align: left;
}
.so-carrier-menu__item:hover,
.so-carrier-menu__item.is-active { background: #eff6ff; }
</style>
