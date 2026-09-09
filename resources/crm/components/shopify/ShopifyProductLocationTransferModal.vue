<script setup>
import { computed, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  fromName: { type: String, default: "" },
  fromLocationId: { type: [String, Number], default: "" },
  available: { type: Number, default: 0 },
  toLocationId: { type: [String, Number], default: "" },
  quantity: { type: [String, Number], default: "1" },
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

const qtyNum = computed(() => Math.max(0, Number(props.quantity) || 0));
const availableLabel = computed(() => {
  const n = Math.max(0, Number(props.available) || 0);
  return `${n.toLocaleString("en-US")} unit${n === 1 ? "" : "s"} available`;
});

function bumpQty(delta) {
  if (props.busy) return;
  const max = Math.max(0, Number(props.available) || 0);
  const next = Math.min(max, Math.max(1, qtyNum.value + delta));
  emit("update:quantity", String(next));
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen && !String(props.reason || "").trim() && props.reasons.length) {
      emit("update:reason", String(props.reasons[0]));
    }
  },
);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm sid-xfer-modal" @click.stop>
        <button
          type="button"
          class="crm-vx-modal__close"
          aria-label="Close"
          :disabled="busy"
          @click="emit('close')"
        >
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <header class="crm-vx-modal__head sid-xfer-modal__head">
          <h2 class="crm-vx-modal__title">Transfer Inventory</h2>
          <p class="sid-xfer-modal__sub">Move inventory between warehouse locations.</p>
        </header>

        <div class="crm-vx-modal__body sid-xfer-modal__body">
          <label class="form-label" for="sid-xfer-from">Transfer From</label>
          <select id="sid-xfer-from" class="form-select mb-3" disabled :value="String(fromLocationId || '')">
            <option :value="String(fromLocationId || '')">{{ fromName || "—" }}</option>
          </select>

          <div class="sid-xfer-modal__arrow" aria-hidden="true">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0 0l-5.25-5.25M12 19.5l5.25-5.25" />
            </svg>
          </div>

          <label class="form-label" for="sid-xfer-to">Transfer To</label>
          <select
            id="sid-xfer-to"
            class="form-select mb-3"
            :value="toLocationId"
            :disabled="busy"
            @change="emit('update:toLocationId', $event.target.value)"
          >
            <option value="">Select destination location</option>
            <option v-for="loc in locations" :key="loc.id" :value="String(loc.id)">
              {{ loc.name }}
            </option>
          </select>

          <label class="form-label" for="sid-xfer-qty">QTY</label>
          <div class="sid-xfer-modal__qty-row mb-1">
            <div class="sid-xfer-modal__stepper">
              <button type="button" class="sid-xfer-modal__step" :disabled="busy || qtyNum <= 1" aria-label="Decrease" @click="bumpQty(-1)">
                −
              </button>
              <input
                id="sid-xfer-qty"
                class="form-control sid-xfer-modal__qty-input"
                type="number"
                min="1"
                :max="Math.max(1, Number(available) || 1)"
                :value="quantity"
                :disabled="busy"
                @input="emit('update:quantity', $event.target.value)"
              >
              <button
                type="button"
                class="sid-xfer-modal__step"
                :disabled="busy || qtyNum >= Number(available || 0)"
                aria-label="Increase"
                @click="bumpQty(1)"
              >
                +
              </button>
            </div>
            <button type="button" class="btn sid-xfer-modal__all" :disabled="busy" @click="emit('all')">
              Transfer All
            </button>
          </div>
          <p class="sid-xfer-modal__avail">{{ availableLabel }}</p>

          <label class="form-label" for="sid-xfer-reason">Reason</label>
          <select
            id="sid-xfer-reason"
            class="form-select"
            :value="reason"
            :disabled="busy"
            @change="emit('update:reason', $event.target.value)"
          >
            <option value="">Select reason</option>
            <option v-for="r in reasons" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <footer class="crm-vx-modal__footer justify-content-end">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">
            Cancel
          </button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="emit('submit')">
            {{ busy ? "Please Wait…" : "Transfer Inventory" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sid-xfer-modal__head {
  text-align: left;
  padding-bottom: 0.35rem;
}
.sid-xfer-modal__sub {
  margin: 0.2rem 0 0;
  font-size: 0.875rem;
  color: #64748b;
}
.sid-xfer-modal__body {
  padding-top: 0.35rem;
}
.sid-xfer-modal__arrow {
  display: flex;
  justify-content: center;
  color: #2563eb;
  margin: 0.15rem 0 0.85rem;
}
.sid-xfer-modal__qty-row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}
.sid-xfer-modal__stepper {
  display: inline-flex;
  align-items: stretch;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  overflow: hidden;
  background: #fff;
  flex: 1 1 auto;
  max-width: 11rem;
}
.sid-xfer-modal__step {
  width: 2.35rem;
  border: 0;
  background: #f8fafc;
  color: #0f172a;
  font-size: 1.1rem;
  font-weight: 600;
  line-height: 1;
}
.sid-xfer-modal__step:disabled {
  opacity: 0.45;
}
.sid-xfer-modal__qty-input {
  border: 0;
  border-left: 1px solid #e2e8f0;
  border-right: 1px solid #e2e8f0;
  border-radius: 0;
  text-align: center;
  box-shadow: none;
  min-width: 0;
}
.sid-xfer-modal__all {
  flex-shrink: 0;
  border: 1px solid #bfdbfe;
  background: #eff6ff;
  color: #2563eb;
  font-weight: 600;
  border-radius: 0.5rem;
  padding: 0.45rem 0.85rem;
}
.sid-xfer-modal__all:hover:not(:disabled) {
  background: #dbeafe;
  color: #1d4ed8;
}
.sid-xfer-modal__avail {
  margin: 0 0 1rem;
  font-size: 0.8125rem;
  color: #94a3b8;
}
</style>
