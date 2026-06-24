<script setup>
import { computed, reactive, watch } from "vue";

const HOLD_ROWS = [
  { key: "fraud_hold", label: "Fraud" },
  { key: "address_hold", label: "Address" },
  { key: "payment_hold", label: "Payment" },
  { key: "client_hold", label: "User Hold" },
];

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "confirm"]);

const selected = reactive({
  fraud_hold: false,
  address_hold: false,
  payment_hold: false,
  client_hold: false,
});

function resetSelection() {
  HOLD_ROWS.forEach(({ key }) => {
    selected[key] = false;
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetSelection();
  },
);

const canSubmit = computed(() => HOLD_ROWS.some(({ key }) => selected[key]));

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
  emit("confirm", {
    fraud_hold: !!selected.fraud_hold,
    address_hold: !!selected.address_hold,
    payment_hold: !!selected.payment_hold,
    client_hold: !!selected.client_hold,
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
        aria-labelledby="orders-place-hold-modal-title"
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
              <h2 id="orders-place-hold-modal-title" class="crm-vx-modal__title">Place Hold</h2>
            </header>
            <div class="crm-vx-modal__body pt-0">
              <p class="small text-secondary mb-3">
                Select a hold type below. Orders placed on User Hold can only be removed by you
              </p>
              <div
                v-for="row in HOLD_ROWS"
                :key="row.key"
                class="form-check mb-2"
                :class="{ 'mb-0': row.key === 'client_hold' }"
              >
                <input
                  :id="`place-hold-${row.key}`"
                  v-model="selected[row.key]"
                  class="form-check-input"
                  type="checkbox"
                  :disabled="busy"
                />
                <label class="form-check-label" :for="`place-hold-${row.key}`">{{ row.label }}</label>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 justify-content-end align-items-center">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="busy"
                @click="onCloseClick"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy || !canSubmit"
                @click="onSubmit"
              >
                {{ busy ? "Placing Hold…" : "Place Hold" }}
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
