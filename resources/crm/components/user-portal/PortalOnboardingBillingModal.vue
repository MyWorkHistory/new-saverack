<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  manualInstructions: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const method = ref("");
const stripeLoading = ref(false);
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
      method.value = "";
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

async function startStripeCheckout(billingMethod) {
  if (stripeLoading.value) return;
  stripeLoading.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.post("/portal/onboarding/billing/stripe-checkout", {
      method: billingMethod,
    });
    const url = String(data?.checkout_url || "").trim();
    if (url === "") {
      throw new Error("Checkout URL missing.");
    }
    emit("saved", data?.onboarding || null);
    window.open(url, "_blank", "noopener,noreferrer");
    toast.info("Complete payment setup in the new tab, then return here.");
  } catch (e) {
    errorMsg.value = "Could not start checkout.";
    toast.errorFrom(e, "Could not start checkout.");
  } finally {
    stripeLoading.value = false;
  }
}

async function confirmManual() {
  if (manualSaving.value) return;
  manualSaving.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.post("/portal/onboarding/billing/manual");
    toast.success("Saved.");
    emit("saved", data);
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
  <Teleport to="body">
    <div
      v-if="open"
      class="modal fade show d-block"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      @click.self="close"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header border-bottom">
            <h2 class="modal-title h5 mb-0">Select Your Payment Method</h2>
            <button
              type="button"
              class="btn-close"
              aria-label="Close"
              @click="close"
            />
          </div>
          <div class="modal-body">
            <p v-if="errorMsg" class="text-danger small">{{ errorMsg }}</p>
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
                Please click the link below to submit your authorization deposit and securely save
                your credit card on file for future billing. The $5 authorization deposit will be
                credited toward your next invoice.
              </p>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="stripeLoading"
                @click="startStripeCheckout('credit_card')"
              >
                <CrmLoadingSpinner v-if="stripeLoading" small class="me-1" />
                Continue to Stripe
              </button>
            </div>

            <div
              v-else-if="method === 'ach'"
              class="portal-billing-detail mt-4 p-3 rounded bg-light"
            >
              <p class="mb-3">
                Please click the link below to submit your authorization deposit and securely save
                your bank account on file for future billing. When completing the setup, please
                select <strong>Bank Transfer (ACH)</strong> as your payment option. The $5
                authorization deposit will be credited toward your next invoice.
              </p>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="stripeLoading"
                @click="startStripeCheckout('ach')"
              >
                <CrmLoadingSpinner v-if="stripeLoading" small class="me-1" />
                Continue to Stripe
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

            <div class="d-flex justify-content-end gap-2 pt-4">
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="stripeLoading || manualSaving"
                @click="close"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-if="open" class="modal-backdrop fade show" />
  </Teleport>
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
