<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  profile: { type: Object, default: null },
  manualInstructions: { type: Object, default: null },
  clientAccountId: { type: [String, Number], default: null },
  adminMode: { type: Boolean, default: false },
  taskId: { type: String, default: "billing_information" },
  taskVerified: { type: Boolean, default: false },
  verifying: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "saved", "verify", "unverify"]);

function adminOnboardingBase() {
  if (!props.clientAccountId) return null;
  return `/client-accounts/${props.clientAccountId}/onboarding`;
}

const toast = useToast();
const method = ref("");
const formLoading = ref(false);
const manualSaving = ref(false);
const errorMsg = ref("");

const manual = computed(() => {
  const m = props.manualInstructions;
  if (!m || typeof m !== "object") {
    return {
      company: "Save Rack LLC",
      street: "3135 Drane Field Rd #20",
      city_state_zip: "Lakeland, FL 33811",
      routing: "063107513",
      account: "1157249176",
      wire: "121000248",
      zelle: "audi@saverack.com",
      apple_pay: "727-255-4885",
    };
  }
  return m;
});

const BILLING_METHOD_LABELS = {
  credit_card: "Credit Card (3.5% Processing Fee)",
  ach: "ACH / Bank Transfer (No Fee)",
  manual: "Manual Payments",
};

const BILLING_STATUS_LABELS = {
  completed: "Completed",
  processing: "Processing",
  not_started: "Not started",
  failed: "Failed",
};

const savedBillingMethod = computed(() => {
  const raw = String(props.profile?.onboarding_billing_method || "").trim();
  return raw in BILLING_METHOD_LABELS ? raw : "";
});

const savedBillingMethodLabel = computed(() =>
  savedBillingMethod.value ? BILLING_METHOD_LABELS[savedBillingMethod.value] : "",
);

const savedBillingStatusLabel = computed(() => {
  const raw = String(props.profile?.onboarding_billing_status || "").trim();
  return BILLING_STATUS_LABELS[raw] || "";
});

const busy = computed(() => formLoading.value || manualSaving.value);

function fillFromProfile() {
  const saved = String(props.profile?.onboarding_billing_method || "").trim();
  method.value = saved in BILLING_METHOD_LABELS ? saved : "";
}

function close() {
  emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape") close();
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      document.addEventListener("keydown", onEsc);
      fillFromProfile();
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

async function openPaymentMethodForm(billingMethod) {
  if (formLoading.value) return;
  formLoading.value = true;
  errorMsg.value = "";
  try {
    const base = adminOnboardingBase();
    const { data } = base
      ? await api.post(`${base}/billing/payment-method-link`, { method: billingMethod })
      : await api.post("/portal/onboarding/billing/payment-method-link", {
          method: billingMethod,
        });
    const url = String(data?.url || "").trim();
    if (url === "") {
      throw new Error("Payment method form URL missing.");
    }
    emit("saved", data?.onboarding || null);
    window.open(url, "_blank", "noopener,noreferrer");
    toast.success("Complete the form in the new tab, then return here.");
  } catch (e) {
    errorMsg.value = "Could not open payment method form.";
    toast.errorFrom(e, "Could not open payment method form.");
  } finally {
    formLoading.value = false;
  }
}

async function confirmManual() {
  if (manualSaving.value) return;
  manualSaving.value = true;
  errorMsg.value = "";
  try {
    const base = adminOnboardingBase();
    const { data } = base
      ? await api.post(`${base}/billing`, { method: "manual" })
      : await api.post("/portal/onboarding/billing/manual");
    toast.success("Saved.");
    emit("saved", base ? data : data);
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save.");
  } finally {
    manualSaving.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" scrollable @update:open="emit('update:open', $event)">
    <button
      type="button"
      class="crm-vx-modal__close"
      aria-label="Close"
      :disabled="busy"
      @click="close"
    >
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">Select Your Payment Method</h2>
    </header>
    <div class="crm-vx-modal__body portal-onboard-modal__body">
            <p v-if="errorMsg" class="text-danger small">{{ errorMsg }}</p>

            <div
              v-if="savedBillingMethodLabel"
              class="portal-billing-current border rounded p-3 mb-4 bg-light"
            >
              <div class="small text-secondary text-uppercase fw-semibold mb-1">Current Selection</div>
              <div class="fw-semibold text-body">{{ savedBillingMethodLabel }}</div>
              <div v-if="savedBillingStatusLabel" class="small text-secondary mt-1">
                Status: {{ savedBillingStatusLabel }}
              </div>
            </div>

            <p class="text-secondary mb-4">
              Choose how you would like to pay your fulfillment invoices:
            </p>

            <div class="portal-billing-options d-flex flex-column gap-3">
              <label class="portal-billing-option border rounded p-3 mb-0">
                <input
                  v-model="method"
                  type="radio"
                  name="portal-billing-method"
                  class="form-check-input me-2"
                  value="credit_card"
                />
                <span class="fw-semibold">Credit Card (3.5% Processing Fee)</span>
                <p class="small text-secondary mb-0 mt-2 ms-4">
                  Pay invoices automatically using a debit or credit card saved securely on file.
                  Helps avoid shipment delays from unpaid invoices.
                </p>
              </label>

              <label class="portal-billing-option border rounded p-3 mb-0">
                <input
                  v-model="method"
                  type="radio"
                  name="portal-billing-method"
                  class="form-check-input me-2"
                  value="ach"
                />
                <span class="fw-semibold">ACH / Bank Transfer (No Fee)</span>
                <p class="small text-secondary mb-0 mt-2 ms-4">
                  Pay invoices automatically from your bank account saved securely on file.
                  Helps avoid shipment delays from unpaid invoices.
                </p>
              </label>

              <label class="portal-billing-option border rounded p-3 mb-0">
                <input
                  v-model="method"
                  type="radio"
                  name="portal-billing-method"
                  class="form-check-input me-2"
                  value="manual"
                />
                <span class="fw-semibold">Manual Payments</span>
                <p class="small text-secondary mb-0 mt-2 ms-4">
                  Manually pay invoices through bank transfer or other approved payment methods.
                  If using this option, please ensure payments are made on time to avoid fulfillment
                  delays or paused shipments due to unpaid invoices.
                </p>
              </label>
            </div>

            <div
              v-if="method === 'credit_card'"
              class="portal-billing-detail mt-4 p-3 rounded bg-light"
            >
              <p class="mb-3">
                Continue to securely save your credit card on file for future billing. Card details
                are collected on a secure Save Rack form and never stored in this app.
              </p>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="formLoading"
                @click="openPaymentMethodForm('credit_card')"
              >
                <CrmLoadingSpinner v-if="formLoading" small class="me-1" />
                Continue to Payment Form
              </button>
            </div>

            <div
              v-else-if="method === 'ach'"
              class="portal-billing-detail mt-4 p-3 rounded bg-light"
            >
              <p class="mb-3">
                Continue to securely save your bank account on file for future billing. Bank details
                are collected on a secure Save Rack form and never stored in this app.
              </p>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="formLoading"
                @click="openPaymentMethodForm('ach')"
              >
                <CrmLoadingSpinner v-if="formLoading" small class="me-1" />
                Continue to Payment Form
              </button>
            </div>

            <div
              v-else-if="method === 'manual'"
              class="portal-billing-detail mt-4 p-3 rounded bg-light"
            >
              <p class="mb-2">
                For manual payments, please send payment using one of the methods below:
              </p>
              <p class="mb-1 fw-semibold">{{ manual.company }}</p>
              <p class="mb-1">{{ manual.street }}</p>
              <p class="mb-3">{{ manual.city_state_zip }}</p>
              <p class="mb-1">Routing #: {{ manual.routing }}</p>
              <p class="mb-1">Account #: {{ manual.account }}</p>
              <p v-if="manual.wire" class="mb-3">Wire #: {{ manual.wire }}</p>
              <p class="mb-1">Zelle: {{ manual.zelle }}</p>
              <p class="mb-3">Apple Pay: {{ manual.apple_pay }}</p>
              <p class="small text-secondary mb-3">
                Please ensure payments are submitted on-time to avoid fulfillment delays or paused
                shipments.
              </p>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="manualSaving"
                @click="confirmManual"
              >
                <CrmLoadingSpinner v-if="manualSaving" small class="me-1" />
                Confirm Manual Payments
              </button>
            </div>

    </div>
    <footer class="crm-vx-modal__footer flex-wrap gap-2">
      <button
        v-if="adminMode && taskVerified"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
        :disabled="busy || verifying"
        @click="emit('unverify')"
      >
        <CrmLoadingSpinner v-if="verifying" small class="me-1" />
        Remove Verification
      </button>
      <button
        v-else-if="adminMode"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
        :disabled="busy || verifying"
        @click="emit('verify')"
      >
        <CrmLoadingSpinner v-if="verifying" small class="me-1" />
        Verify
      </button>
      <button
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
        :disabled="busy || verifying"
        @click="close"
      >
        Cancel
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>

<style scoped>
.portal-billing-option {
  cursor: pointer;
}

.portal-billing-option:has(input:checked) {
  border-color: var(--bs-primary) !important;
  background: rgba(var(--bs-primary-rgb), 0.04);
}
</style>
