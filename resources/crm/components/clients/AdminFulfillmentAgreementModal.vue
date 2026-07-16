<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "../user-portal/PortalOnboardingModalShell.vue";
import FulfillmentAgreementSignLightbox from "../user-portal/FulfillmentAgreementSignLightbox.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { todayInputDate } from "../../constants/fulfillmentAgreementSignatures.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccountId: { type: [String, Number], required: true },
  agreement: { type: Object, default: null },
  task: { type: Object, default: null },
  verifying: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "saved", "unverify"]);

const toast = useToast();
const busy = ref(false);
const counterSignOpen = ref(false);
const viewing = ref(false);

const accepted = computed(() => props.agreement?.status === "completed");
const hasSignedPdf = computed(() => !!props.agreement?.has_signed_pdf || accepted.value);
const taskVerified = computed(() => !!props.task?.verified);
const bodyHtml = computed(() => props.agreement?.body || "");
const hasBody = computed(
  () => !!(bodyHtml.value && String(bodyHtml.value).replace(/<[^>]+>/g, "").trim()),
);
const canVerify = computed(() => accepted.value && !taskVerified.value);

const basePath = computed(
  () => `/client-accounts/${props.clientAccountId}/onboarding/fulfillment-agreement`,
);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) counterSignOpen.value = false;
  },
);

function close() {
  if (!busy.value && !props.verifying) emit("update:open", false);
}

function startVerify() {
  if (!canVerify.value || busy.value || props.verifying) return;
  counterSignOpen.value = true;
}

async function viewSigned() {
  if (viewing.value) return;
  viewing.value = true;
  try {
    await openApiPdfBlob(api, `${basePath.value}/signed.pdf`);
  } catch (e) {
    toast.errorFrom(e, "Could not open signed agreement.");
  } finally {
    viewing.value = false;
  }
}

async function onCounterSign(payload) {
  if (busy.value) return;
  busy.value = true;
  try {
    const { data } = await api.post(`${basePath.value}/verify`, payload);
    counterSignOpen.value = false;
    emit("saved", data);
    toast.success("Fulfillment Agreement verified.");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not verify agreement.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" lg scrollable @update:open="close">
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">Fulfillment Agreement</h2>
      <p class="crm-vx-modal__subtitle mb-0">
        <template v-if="!accepted">
          Client has not completed this agreement yet. Once they upload or e-sign, you can verify here.
        </template>
        <template v-else-if="taskVerified">
          This agreement is verified. You can view the signed PDF or remove verification.
        </template>
        <template v-else>
          Review the agreement, then click Verify to counter-sign for Save Rack LLC.
        </template>
      </p>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div
        v-if="hasBody"
        class="admin-fa-modal__body"
        v-html="bodyHtml"
      />
      <p v-else class="text-secondary mb-0">Agreement body is not available.</p>
    </div>

    <footer class="crm-vx-modal__footer flex-wrap gap-2">
      <button
        v-if="taskVerified"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
        :disabled="busy || verifying"
        @click="emit('unverify')"
      >
        <CrmLoadingSpinner v-if="verifying" small class="me-1" />
        Remove Verification
      </button>
      <button
        v-else
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--primary me-auto"
        :disabled="busy || verifying || !canVerify"
        :title="
          accepted
            ? 'Counter-sign and verify this agreement'
            : 'Client must complete the agreement before you can verify'
        "
        @click="startVerify"
      >
        <CrmLoadingSpinner v-if="busy || verifying" small class="me-1" />
        Verify
      </button>

      <button
        v-if="hasSignedPdf"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
        :disabled="viewing || busy"
        @click="viewSigned"
      >
        {{ viewing ? "Opening…" : "View Signed PDF" }}
      </button>

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

  <FulfillmentAgreementSignLightbox
    v-model:open="counterSignOpen"
    :saving="busy || verifying"
    title="Verify Fulfillment Agreement"
    subtitle="Counter-sign for Save Rack LLC. Prefills Audi Kowalski — choose a cursive style and Sign Here."
    :show-company="false"
    initial-rep-name="Audi Kowalski"
    :initial-signed-at="todayInputDate()"
    @submit="onCounterSign"
  />
</template>

<style scoped>
.admin-fa-modal__body {
  font-size: 0.925rem;
  line-height: 1.55;
  max-height: min(55vh, 420px);
  overflow-y: auto;
}
.admin-fa-modal__body :deep(p) {
  margin: 0 0 0.65rem;
}
.admin-fa-modal__body :deep(ul),
.admin-fa-modal__body :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.admin-fa-modal__body :deep(h2),
.admin-fa-modal__body :deep(h3),
.admin-fa-modal__body :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
</style>
