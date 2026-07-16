<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import FulfillmentAgreementSignLightbox from "./FulfillmentAgreementSignLightbox.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  agreement: { type: Object, default: null },
  /** Prefill company for e-sign when available from profile */
  defaultCompany: { type: String, default: "" },
  defaultRepName: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "accepted"]);

const toast = useToast();
const busy = ref(false);
const downloading = ref(false);
const esignOpen = ref(false);
const fileInput = ref(null);

const accepted = computed(() => props.agreement?.status === "completed");
const bodyHtml = computed(() => props.agreement?.body || "");
const hasBody = computed(
  () => !!(bodyHtml.value && String(bodyHtml.value).replace(/<[^>]+>/g, "").trim()),
);

function close() {
  if (!busy.value) emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape" && !esignOpen.value) close();
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      window.addEventListener("keydown", onEsc);
    } else {
      window.removeEventListener("keydown", onEsc);
      esignOpen.value = false;
    }
  },
);

onUnmounted(() => {
  window.removeEventListener("keydown", onEsc);
});

async function downloadBlank() {
  if (downloading.value) return;
  downloading.value = true;
  try {
    await openApiPdfBlob(api, "/portal/onboarding/fulfillment-agreement.pdf");
  } catch (e) {
    toast.errorFrom(e, "Could not download agreement PDF.");
  } finally {
    downloading.value = false;
  }
}

async function viewSigned() {
  if (downloading.value) return;
  downloading.value = true;
  try {
    await openApiPdfBlob(api, "/portal/onboarding/fulfillment-agreement/signed.pdf");
  } catch (e) {
    toast.errorFrom(e, "Could not open signed agreement.");
  } finally {
    downloading.value = false;
  }
}

function triggerUpload() {
  fileInput.value?.click();
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
    const { data } = await api.post("/portal/onboarding/fulfillment-agreement/upload", form);
    emit("accepted", data);
    toast.success("Fulfillment Agreement uploaded.");
    emit("update:open", false);
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
    const { data } = await api.post("/portal/onboarding/fulfillment-agreement/esign", payload);
    esignOpen.value = false;
    emit("accepted", data);
    toast.success("Fulfillment Agreement signed.");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not e-sign agreement.");
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
        <template v-if="accepted">
          This agreement is complete. You can view the signed PDF below.
        </template>
        <template v-else>
          Review the agreement, download a printable copy, then upload a signed PDF or e-sign online.
        </template>
      </p>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div
        v-if="hasBody"
        class="portal-fulfillment-agreement-modal__body"
        v-html="bodyHtml"
      />
      <p v-else class="text-secondary mb-0">
        The fulfillment agreement is not available yet. Please contact support.
      </p>
    </div>

    <footer class="crm-vx-modal__footer portal-fa-modal__footer">
      <button
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary portal-fa-download-btn"
        :disabled="downloading || busy"
        @click="downloadBlank"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M5 20h14v-2H5v2zm7-18v10.17l3.59-3.58L17 10l-5 5-5-5 1.41-1.41L11 12.17V2h1z"
          />
        </svg>
        <span>{{ downloading ? "Downloading…" : "Download" }}</span>
      </button>

      <div class="portal-fa-modal__footer-actions">
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="busy"
          @click="close"
        >
          {{ accepted ? "Close" : "Cancel" }}
        </button>
        <template v-if="accepted">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="downloading"
            @click="viewSigned"
          >
            {{ downloading ? "Opening…" : "View Signed PDF" }}
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy || !hasBody"
            @click="triggerUpload"
          >
            Upload Agreement
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || !hasBody"
            @click="esignOpen = true"
          >
            E-Sign Agreement
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
</template>

<style scoped>
.portal-fulfillment-agreement-modal__body {
  font-size: 0.925rem;
  line-height: 1.55;
}
.portal-fulfillment-agreement-modal__body :deep(p) {
  margin: 0 0 0.65rem;
}
.portal-fulfillment-agreement-modal__body :deep(ul),
.portal-fulfillment-agreement-modal__body :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.portal-fulfillment-agreement-modal__body :deep(h2),
.portal-fulfillment-agreement-modal__body :deep(h3),
.portal-fulfillment-agreement-modal__body :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
.portal-fa-modal__footer {
  justify-content: space-between !important;
  gap: 0.75rem;
}
.portal-fa-download-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-width: 0;
  margin-right: auto;
}
.portal-fa-modal__footer-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
