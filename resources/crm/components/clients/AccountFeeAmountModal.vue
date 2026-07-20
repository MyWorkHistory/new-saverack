<script setup>
import { computed, ref, watch } from "vue";

const FORM_ID = "account-fee-amount-form";

const props = defineProps({
  open: { type: Boolean, default: false },
  fee: { type: Object, default: null },
  saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save"]);

const amount = ref("");

const isStorageFee = computed(
  () => String(props.fee?.category || "").toLowerCase() === "storage",
);

const amountStep = computed(() => (isStorageFee.value ? "0.001" : "0.01"));

const amountValid = computed(() => {
  const raw = String(amount.value ?? "").trim();
  if (raw === "") return true;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0;
});

const canSubmit = computed(() => amountValid.value);

function formatAmountForInput(value) {
  if (value == null || value === "") return "";
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  if (isStorageFee.value) {
    return n.toFixed(3);
  }
  // Keep existing precision up to 4 decimals without forcing trailing zeros.
  const fixed = n.toFixed(4).replace(/\.?0+$/, "");
  return fixed === "-0" ? "0" : fixed;
}

function resetForm() {
  const f = props.fee;
  amount.value = f?.amount != null && f.amount !== "" ? formatAmountForInput(f.amount) : "";
}

watch(
  () => [props.open, props.fee],
  () => {
    if (props.open) {
      resetForm();
    }
  },
  { immediate: true },
);

function submit() {
  if (!canSubmit.value) return;
  const raw = String(amount.value ?? "").trim();
  emit("save", {
    amount: raw === "" ? null : raw,
  });
}

function onBackdrop() {
  if (!props.saving) {
    emit("close");
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="account-fee-amount-modal-title"
        @click.self="onBackdrop"
      >
        <div class="crm-vx-modal crm-vx-modal--pricing-fee" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="saving"
            @click="$emit('close')"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 id="account-fee-amount-modal-title" class="crm-vx-modal__title">
              Edit Fee Price
            </h2>
          </header>

          <div class="crm-vx-modal__body text-start">
            <form :id="FORM_ID" @submit.prevent="submit">
              <div class="mb-3">
                <label class="form-label">Fee</label>
                <p class="mb-0 fw-semibold text-body">{{ fee?.name || "—" }}</p>
                <p v-if="fee?.category_label" class="small text-secondary mb-0 mt-1">
                  {{ fee.category_label }}
                </p>
              </div>
              <div class="mb-0">
                <label class="form-label" for="account-fee-amount">Price</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input
                    id="account-fee-amount"
                    v-model="amount"
                    type="number"
                    :step="amountStep"
                    min="0"
                    class="form-control"
                    :disabled="saving"
                  />
                </div>
                <p class="small text-secondary mt-1 mb-0">
                  <template v-if="isStorageFee">
                    Storage prices use 3 decimal places (e.g. 0.023). Leave blank to clear.
                  </template>
                  <template v-else>
                    Leave blank to clear the account price.
                  </template>
                </p>
              </div>
            </form>
          </div>

          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="saving"
              @click="$emit('close')"
            >
              Cancel
            </button>
            <button
              type="submit"
              :form="FORM_ID"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="saving || !canSubmit"
            >
              {{ saving ? "Saving…" : "Save Price" }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
