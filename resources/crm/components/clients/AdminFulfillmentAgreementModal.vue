<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "../user-portal/PortalOnboardingModalShell.vue";
import FulfillmentAgreementSignLightbox from "../user-portal/FulfillmentAgreementSignLightbox.vue";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { todayInputDate } from "../../constants/fulfillmentAgreementSignatures.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

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
const counterSigned = computed(() => !!props.agreement?.staff_counter_signed);
const taskVerified = computed(() => !!props.task?.verified);
const bodyHtml = computed(() => props.agreement?.body || "");
const hasBody = computed(
  () => !!(bodyHtml.value && String(bodyHtml.value).replace(/<[^>]+>/g, "").trim()),
);

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
        Review the client agreement and counter-sign for Save Rack LLC to verify this task.
      </p>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <span
          class="badge rounded-pill"
          :class="accepted ? 'portal-onboard-status--completed' : 'portal-onboard-status--pending'"
        >
          {{ accepted ? "Client Completed" : "Client Not Completed" }}
        </span>
        <span
          class="badge rounded-pill"
          :class="taskVerified ? 'portal-onboard-status--verified' : 'portal-onboard-status--not-verified'"
        >
          {{ taskVerified ? "Verified" : "Not Verified" }}
        </span>
      </div>

      <dl v-if="accepted" class="row small mb-3">
        <dt class="col-sm-4 text-secondary">Method</dt>
        <dd class="col-sm-8">{{ agreement?.method === "upload" ? "Upload" : "E-Sign" }}</dd>
        <dt class="col-sm-4 text-secondary">Company</dt>
        <dd class="col-sm-8">{{ agreement?.company || "—" }}</dd>
        <dt class="col-sm-4 text-secondary">Representative</dt>
        <dd class="col-sm-8">{{ agreement?.rep_name || "—" }}</dd>
        <template v-if="counterSigned">
          <dt class="col-sm-4 text-secondary">Save Rack Rep</dt>
          <dd class="col-sm-8">{{ agreement?.staff_rep_name || "—" }}</dd>
        </template>
      </dl>

      <div
        v-if="hasBody"
        class="admin-fa-modal__body"
        v-html="bodyHtml"
      />
      <p v-else class="text-secondary mb-0">Agreement body is not available.</p>
    </div>

    <footer :class="[CRM_DIALOG_FOOTER_CLASS, 'flex-wrap gap-2']">
      <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy || verifying" @click="close">
        Close
      </button>
      <button
        v-if="accepted"
        type="button"
        :class="CRM_BTN_SECONDARY"
        :disabled="viewing || busy"
        @click="viewSigned"
      >
        {{ viewing ? "Opening…" : "View Signed PDF" }}
      </button>
      <button
        v-if="taskVerified"
        type="button"
        :class="CRM_BTN_SECONDARY"
        :disabled="busy || verifying"
        @click="emit('unverify')"
      >
        {{ verifying ? "Updating…" : "Remove Verification" }}
      </button>
      <button
        v-else-if="accepted"
        type="button"
        :class="CRM_BTN_PRIMARY"
        :disabled="busy || verifying"
        @click="counterSignOpen = true"
      >
        Verify
      </button>
    </footer>
  </PortalOnboardingModalShell>

  <FulfillmentAgreementSignLightbox
    v-model:open="counterSignOpen"
    :saving="busy || verifying"
    title="Verify Fulfillment Agreement"
    subtitle="Counter-sign for Save Rack LLC to verify this onboarding task."
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
  max-height: min(40vh, 320px);
  overflow-y: auto;
}
.admin-fa-modal__body :deep(p) {
  margin: 0 0 0.65rem;
}
</style>
