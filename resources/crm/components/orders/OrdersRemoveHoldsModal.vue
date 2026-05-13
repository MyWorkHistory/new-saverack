<script setup>
import { computed, reactive, ref, watch } from "vue";

const HOLD_ROWS = [
  { key: "fraud_hold", label: "Fraud Hold" },
  { key: "address_hold", label: "Address Hold" },
  { key: "payment_hold", label: "Payment Hold" },
  { key: "client_hold", label: "Client Hold" },
];

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  /** `single`: one order, show only holds that are on (checked = remove). `bulk`: all types, checked by default. */
  variant: { type: String, default: "single" },
  /** Normalized hold flags for the current order (single mode). */
  activeHolds: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["close", "confirm"]);

const selected = reactive({
  fraud_hold: false,
  address_hold: false,
  payment_hold: false,
  client_hold: false,
});

const paymentReasonLocal = ref("");

function syncFromProps() {
  paymentReasonLocal.value = "";
  HOLD_ROWS.forEach(({ key }) => {
    selected[key] = false;
  });
  if (props.variant === "bulk") {
    HOLD_ROWS.forEach(({ key }) => {
      selected[key] = true;
    });
    return;
  }
  HOLD_ROWS.forEach(({ key }) => {
    selected[key] = !!props.activeHolds?.[key];
  });
}

watch(
  () => props.open,
  (o) => {
    if (o) syncFromProps();
  },
);

const visibleRows = computed(() => {
  if (props.variant === "bulk") {
    return HOLD_ROWS;
  }
  return HOLD_ROWS.filter(({ key }) => !!props.activeHolds?.[key]);
});

const canSubmit = computed(() => visibleRows.value.some(({ key }) => selected[key]));

const showPaymentNote = computed(() => !!selected.payment_hold);

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
  const holds_to_clear = visibleRows.value.filter(({ key }) => selected[key]).map(({ key }) => key);
  if (!holds_to_clear.length) return;
  emit("confirm", {
    holds_to_clear,
    payment_hold_reason: paymentReasonLocal.value.trim() || undefined,
  });
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
        aria-labelledby="orders-remove-holds-modal-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="onBackdropClick" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
              @click="onCloseClick"
            >
              <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <header class="crm-vx-modal__head">
              <h2 id="orders-remove-holds-modal-title" class="crm-vx-modal__title">Remove Holds</h2>
            </header>
            <div class="crm-vx-modal__body pt-0">
              <p v-if="variant === 'bulk'" class="small text-secondary mb-3">
                Selected hold types are cleared on each order that has them. Orders with only an operator hold are
                skipped. Shipping method holds are not available via this API; clear those in ShipHero.
              </p>
              <p v-else class="small text-secondary mb-3">
                Checked holds are removed in ShipHero. Uncheck a hold to leave it on the order. Operator holds cannot be
                removed here. Shipping method holds must be cleared in ShipHero.
              </p>
              <div v-if="!visibleRows.length" class="small text-secondary">No removable holds on this order.</div>
              <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2">
                <li v-for="row in visibleRows" :key="row.key" class="form-check">
                  <input
                    :id="`remove-hold-${row.key}`"
                    v-model="selected[row.key]"
                    class="form-check-input"
                    type="checkbox"
                    :disabled="busy"
                  />
                  <label class="form-check-label" :for="`remove-hold-${row.key}`">{{ row.label }}</label>
                </li>
              </ul>
              <div v-if="showPaymentNote" class="mt-3">
                <label class="form-label small mb-1" for="orders-remove-holds-payment-note">Payment hold note (optional)</label>
                <input
                  id="orders-remove-holds-payment-note"
                  v-model="paymentReasonLocal"
                  type="text"
                  class="form-control form-control-sm"
                  maxlength="500"
                  placeholder="Defaults to User Clear Payment Hold if empty"
                  :disabled="busy"
                  autocomplete="off"
                />
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="onCloseClick">
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy || !canSubmit || !visibleRows.length"
                @click="onSubmit"
              >
                {{ busy ? "Removing…" : "Remove Holds" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
