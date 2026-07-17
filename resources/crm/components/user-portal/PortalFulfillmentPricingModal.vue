<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import PricingFeeList from "../settings/PricingFeeList.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { normalizeAccountFeeItems } from "../../utils/accountFees.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  pricing: { type: Object, default: null },
  /** When set, downloads from admin onboarding PDF route */
  clientAccountId: { type: [String, Number], default: null },
  adminMode: { type: Boolean, default: false },
  taskVerified: { type: Boolean, default: false },
  verifying: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "accepted", "verify", "unverify"]);

const toast = useToast();
const busy = ref(false);
const downloading = ref(false);
const agreed = ref(false);

const approved = computed(() => !!props.pricing?.approved);
const accepted = computed(() => props.pricing?.status === "completed" || !!props.pricing?.accepted_at);
const feeItems = computed(() => {
  if (!approved.value) return [];
  return normalizeAccountFeeItems({ fees: { items: props.pricing?.fees || [] } });
});

const downloadPath = computed(() => {
  if (props.adminMode && props.clientAccountId) {
    return `/client-accounts/${props.clientAccountId}/onboarding/fulfillment-pricing.pdf`;
  }
  return "/portal/onboarding/fulfillment-pricing.pdf";
});

const showAgree = computed(
  () => !props.adminMode && approved.value && !accepted.value,
);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      agreed.value = false;
    }
  },
);

function close() {
  if (!busy.value && !props.verifying) emit("update:open", false);
}

async function downloadPdf() {
  if (downloading.value) return;
  downloading.value = true;
  try {
    await openApiPdfBlob(api, downloadPath.value);
  } catch (e) {
    toast.errorFrom(e, "Could not download pricing PDF.");
  } finally {
    downloading.value = false;
  }
}

async function onAgree() {
  if (!showAgree.value || !agreed.value || busy.value) return;
  busy.value = true;
  try {
    const { data } = await api.post("/portal/onboarding/fulfillment-pricing/accept");
    emit("accepted", data);
    toast.success("Fulfillment Pricing accepted.");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not accept pricing.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" lg scrollable @update:open="close">
    <header class="crm-vx-modal__head d-flex align-items-start justify-content-between gap-3">
      <div class="min-w-0">
        <h2 class="crm-vx-modal__title mb-0">Fulfillment Pricing</h2>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 flex-shrink-0"
        :disabled="downloading || busy || verifying"
        aria-label="Download"
        @click="downloadPdf"
      >
        <CrmLoadingSpinner v-if="downloading" small />
        <svg
          v-else
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            d="M12 16l-5-5h3V4h4v7h3l-5 5zm-7 2h14v2H5v-2z"
          />
        </svg>
        <span>Download</span>
      </button>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div
        v-if="!approved"
        class="portal-fulfillment-pricing-modal__empty text-center text-secondary py-5 px-3"
      >
        Quoted pricing has not been set for this account
      </div>
      <PricingFeeList
        v-else
        :fees="feeItems"
        :clickable="false"
      />
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

      <template v-if="showAgree">
        <label class="portal-fulfillment-pricing-modal__agree form-check d-flex align-items-start gap-2 me-auto mb-0">
          <input
            v-model="agreed"
            class="form-check-input mt-1 flex-shrink-0"
            type="checkbox"
            :disabled="busy"
          />
          <span class="form-check-label small text-secondary">
            By checking this box and clicking "I Agree," I acknowledge that I have reviewed and
            accepted Save Rack's current pricing schedule and fee structure. I understand that
            pricing may be updated from time to time in accordance with the Fulfillment Services
            Agreement.
          </span>
        </label>
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--primary"
          :disabled="!agreed || busy"
          @click="onAgree"
        >
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          I Agree
        </button>
      </template>

      <button
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
        :disabled="busy || verifying"
        @click="close"
      >
        Close
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>

<style scoped>
.portal-fulfillment-pricing-modal__agree {
  max-width: min(36rem, 100%);
  flex: 1 1 16rem;
}

.portal-fulfillment-pricing-modal__empty {
  font-size: 0.95rem;
}
</style>
