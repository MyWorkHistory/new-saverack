<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "../user-portal/PortalOnboardingModalShell.vue";
import FulfillmentAgreementSignLightbox from "../user-portal/FulfillmentAgreementSignLightbox.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import ConfirmModal from "../common/ConfirmModal.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { todayInputDate } from "../../constants/fulfillmentAgreementSignatures.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccountId: { type: [String, Number], required: true },
  agreement: { type: Object, default: null },
  task: { type: Object, default: null },
  verifying: { type: Boolean, default: false },
  defaultCompany: { type: String, default: "" },
  defaultRepName: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "saved", "unverify"]);

const toast = useToast();
const busy = ref(false);
const downloading = ref(false);
const counterSignOpen = ref(false);
const esignOpen = ref(false);
const viewing = ref(false);
const clearConfirmOpen = ref(false);
const fileInput = ref(null);

const accepted = computed(() => props.agreement?.status === "completed");
const isUploadAgreement = computed(() => String(props.agreement?.method || "") === "upload");
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
    if (!isOpen) {
      counterSignOpen.value = false;
      esignOpen.value = false;
    }
  },
);

function close() {
  if (!busy.value && !props.verifying) emit("update:open", false);
}

async function startVerify() {
  if (!canVerify.value || busy.value || props.verifying) return;

  // Uploaded wet-ink PDFs are the agreement of record — view and mark verified, no e-sign.
  if (isUploadAgreement.value) {
    await verifyUploadAgreement();
    return;
  }

  counterSignOpen.value = true;
}

async function verifyUploadAgreement() {
  if (busy.value) return;
  busy.value = true;
  try {
    if (hasSignedPdf.value) {
      try {
        await openApiPdfBlob(api, `${basePath.value}/signed.pdf`);
      } catch (e) {
        toast.errorFrom(e, "Could not open uploaded agreement.");
        return;
      }
    }
    const { data } = await api.post(`${basePath.value}/verify`, {});
    emit("saved", data);
    toast.success("Fulfillment Agreement verified.");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not verify agreement.");
  } finally {
    busy.value = false;
  }
}

function triggerUpload() {
  fileInput.value?.click();
}

async function downloadBlank() {
  if (downloading.value) return;
  downloading.value = true;
  try {
    await openApiPdfBlob(api, `${basePath.value}.pdf`);
  } catch (e) {
    toast.errorFrom(e, "Could not download agreement PDF.");
  } finally {
    downloading.value = false;
  }
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

async function onFileSelected(event) {
  const input = event?.target;
  const file = input?.files?.[0];
  if (input) input.value = "";
  if (!file || busy.value || accepted.value) return;

  if (!String(file.type || "").includes("pdf") && !/\.pdf$/i.test(file.name || "")) {
    toast.error("Please upload a PDF file.");
    return;
  }

  busy.value = true;
  try {
    const form = new FormData();
    form.append("file", file);
    if (props.defaultCompany) form.append("company", props.defaultCompany);
    if (props.defaultRepName) form.append("rep_name", props.defaultRepName);
    const { data } = await api.post(`${basePath.value}/upload`, form);
    emit("saved", data);
    toast.success("Fulfillment Agreement uploaded. You can Verify now.");
  } catch (e) {
    toast.errorFrom(e, "Could not upload agreement.");
  } finally {
    busy.value = false;
  }
}

async function onEsignSubmit(payload) {
  if (busy.value || accepted.value) return;
  busy.value = true;
  try {
    const { data } = await api.post(`${basePath.value}/esign`, payload);
    esignOpen.value = false;
    emit("saved", data);
    toast.success("Fulfillment Agreement signed. You can Verify now.");
  } catch (e) {
    toast.errorFrom(e, "Could not e-sign agreement.");
  } finally {
    busy.value = false;
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

async function clearAgreement() {
  if (busy.value || props.verifying) return;
  busy.value = true;
  try {
    const { data } = await api.delete(basePath.value);
    clearConfirmOpen.value = false;
    emit("saved", data);
    toast.success("Client signature cleared. The agreement is Not Completed.");
  } catch (e) {
    toast.errorFrom(e, "Could not clear the fulfillment agreement.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" xl scrollable @update:open="close">
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">Fulfillment Agreement</h2>
      <p class="crm-vx-modal__subtitle mb-0">
        <template v-if="!accepted">
          Download a printable copy, then Upload Agreement or E-Sign Agreement. After that, Verify.
        </template>
        <template v-else-if="taskVerified">
          This agreement is verified. You can view the
          {{ isUploadAgreement ? "uploaded" : "signed" }} PDF or remove verification.
        </template>
        <template v-else-if="isUploadAgreement">
          An agreement PDF was uploaded. Review the file, then click Verify — no e-sign needed.
        </template>
        <template v-else>
          Client side is complete. Click Verify to counter-sign for Save Rack LLC.
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

    <footer class="crm-vx-modal__footer admin-fa-modal__footer">
      <button
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary admin-fa-download-btn"
        :disabled="downloading || busy || verifying"
        @click="downloadBlank"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M5 20h14v-2H5v2zm7-18v10.17l3.59-3.58L17 10l-5 5-5-5 1.41-1.41L11 12.17V2h1z"
          />
        </svg>
        <span>{{ downloading ? "Downloading…" : "Download" }}</span>
      </button>

      <div class="admin-fa-modal__footer-actions">
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="busy || verifying"
          @click="close"
        >
          Close
        </button>

        <template v-if="!accepted">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy || verifying || !hasBody"
            @click="triggerUpload"
          >
            Upload Agreement
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || verifying || !hasBody"
            @click="esignOpen = true"
          >
            E-Sign Agreement
          </button>
        </template>

        <template v-else>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--danger"
            :disabled="busy || verifying"
            @click="clearConfirmOpen = true"
          >
            Clear Signature
          </button>
          <button
            v-if="hasSignedPdf"
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="viewing || busy"
            @click="viewSigned"
          >
            {{ viewing ? "Opening…" : isUploadAgreement ? "View Uploaded PDF" : "View Signed PDF" }}
          </button>
          <button
            v-if="taskVerified"
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy || verifying"
            @click="emit('unverify')"
          >
            <CrmLoadingSpinner v-if="verifying" small class="me-1" />
            Remove Verification
          </button>
          <button
            v-else
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || verifying || !canVerify"
            @click="startVerify"
          >
            <CrmLoadingSpinner v-if="busy || verifying" small class="me-1" />
            Verify
          </button>
        </template>
      </div>
    </footer>

    <input
      ref="fileInput"
      type="file"
      class="d-none"
      accept="application/pdf,.pdf"
      @change="onFileSelected"
    />
  </PortalOnboardingModalShell>

  <FulfillmentAgreementSignLightbox
    v-model:open="esignOpen"
    :saving="busy"
    title="E-Sign Agreement"
    :initial-company="defaultCompany || agreement?.company || ''"
    :initial-rep-name="defaultRepName || agreement?.rep_name || ''"
    @submit="onEsignSubmit"
  />

  <FulfillmentAgreementSignLightbox
    v-if="!isUploadAgreement"
    v-model:open="counterSignOpen"
    :saving="busy || verifying"
    title="Verify Fulfillment Agreement"
    subtitle="Counter-sign for Save Rack LLC. Prefills Audi Kowalski — choose a cursive style and Sign Here."
    :show-company="false"
    initial-rep-name="Audi Kowalski"
    :initial-signed-at="todayInputDate()"
    @submit="onCounterSign"
  />

  <ConfirmModal
    :open="clearConfirmOpen"
    title="Clear Client Signature"
    message="Clear this agreement? This removes the client and Save Rack signatures and returns the task to Not Completed."
    confirm-label="Clear Signature"
    :busy="busy"
    @close="clearConfirmOpen = false"
    @confirm="clearAgreement"
  />
</template>

<style scoped>
.admin-fa-modal__body {
  font-size: 0.925rem;
  line-height: 1.55;
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
.admin-fa-modal__footer {
  display: flex !important;
  flex-wrap: nowrap !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 0.75rem;
}
.admin-fa-download-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-width: 0 !important;
  flex: 0 0 auto;
  margin-right: auto;
  white-space: nowrap;
}
.admin-fa-modal__footer-actions {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  flex: 0 1 auto;
  min-width: 0;
}
.admin-fa-modal__footer-actions :deep(.crm-vx-modal-btn),
.admin-fa-modal__footer .crm-vx-modal-btn {
  min-width: 0;
  padding-inline: 1rem;
  white-space: nowrap;
  flex: 0 0 auto;
}
</style>
