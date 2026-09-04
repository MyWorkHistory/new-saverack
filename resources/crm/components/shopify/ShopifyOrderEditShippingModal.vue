<script setup>
import { computed, ref, watch } from "vue";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const CARRIERS = [
  { value: "UPS", label: "UPS", color: "#351C15" },
  { value: "USPS", label: "USPS", color: "#333366" },
  { value: "FEDEX", label: "FedEx", color: "#4D148C" },
  { value: "DHL", label: "DHL", color: "#FFCC00" },
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

const orderLabel = computed(() => formatShopifyOrderName(props.order?.name || props.order?.display_name) || "—");
const serviceOptions = computed(() => SERVICES[carrier.value] || []);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
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

        <label class="form-label">Carrier</label>
        <select v-model="carrier" class="form-select mb-3" :disabled="busy">
          <option v-for="c in CARRIERS" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>

        <div class="so-carrier-icons mb-3">
          <button
            v-for="c in CARRIERS"
            :key="c.value"
            type="button"
            class="so-carrier-chip"
            :class="{ 'is-active': carrier === c.value }"
            :disabled="busy"
            @click="carrier = c.value"
          >
            <span class="so-carrier-chip__icon" :style="{ background: c.color }">{{ c.label.slice(0, 1) }}</span>
            <span>{{ c.label }}</span>
          </button>
        </div>

        <label class="form-label">Service</label>
        <select v-model="service" class="form-select mb-3" :disabled="busy">
          <option v-for="s in serviceOptions" :key="s" :value="s">{{ s }}</option>
        </select>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">Cancel</button>
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
.so-modal__title { margin: 0; font-size: 1.2rem; font-weight: 700; }
.so-modal__lead { margin: 0 0 1rem; color: #4b5563; font-size: 0.95rem; }
.so-modal__foot { display: flex; justify-content: flex-end; gap: 0.55rem; }
.so-carrier-icons { display: flex; flex-wrap: wrap; gap: 0.45rem; }
.so-carrier-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 0.5rem;
  padding: 0.35rem 0.55rem;
  font-size: 0.85rem;
  font-weight: 600;
}
.so-carrier-chip.is-active { border-color: #3b82f6; background: #eff6ff; }
.so-carrier-chip__icon {
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 0.25rem;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
</style>
