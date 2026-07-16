<script setup>
import { computed, ref, watch } from "vue";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import FulfillmentSignatureStylePicker from "./FulfillmentSignatureStylePicker.vue";
import {
  renderFulfillmentSignaturePng,
  todayInputDate,
} from "../../constants/fulfillmentAgreementSignatures.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  title: { type: String, default: "E-Sign Agreement" },
  subtitle: {
    type: String,
    default: "Enter your company details and apply a cursive signature.",
  },
  showCompany: { type: Boolean, default: true },
  initialCompany: { type: String, default: "" },
  initialRepName: { type: String, default: "" },
  initialSignedAt: { type: String, default: "" },
  defaultSignatureStyle: { type: String, default: "dancing_script" },
});

const emit = defineEmits(["update:open", "submit"]);

const company = ref("");
const repName = ref("");
const signedAt = ref("");
const signatureStyle = ref("dancing_script");
const formError = ref("");

const signaturePreviewText = computed(() => String(repName.value || "").trim());

function resetForm() {
  company.value = props.initialCompany || "";
  repName.value = props.initialRepName || "";
  signedAt.value = props.initialSignedAt || todayInputDate();
  signatureStyle.value = props.defaultSignatureStyle || "dancing_script";
  formError.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetForm();
  },
);

function close() {
  if (!props.saving) emit("update:open", false);
}

async function submit() {
  formError.value = "";
  const companyVal = String(company.value || "").trim();
  const repVal = String(repName.value || "").trim();
  if (props.showCompany && !companyVal) {
    formError.value = "Company Name is required.";
    return;
  }
  if (!repVal) {
    formError.value = "Authorized Representative is required.";
    return;
  }
  try {
    const signatureImage = await renderFulfillmentSignaturePng(repVal, signatureStyle.value);
    emit("submit", {
      company: companyVal,
      rep_name: repVal,
      signed_at: signedAt.value || todayInputDate(),
      signature_style: signatureStyle.value,
      signature_text: repVal,
      signature_image: signatureImage,
    });
  } catch (e) {
    formError.value = "Could not render signature. Try again.";
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" @update:open="close">
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">{{ title }}</h2>
      <p class="crm-vx-modal__subtitle mb-0">{{ subtitle }}</p>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div class="row g-3">
        <div v-if="showCompany" class="col-12">
          <label class="form-label" for="fa-esign-company">Company Name</label>
          <input
            id="fa-esign-company"
            v-model="company"
            type="text"
            class="form-control"
            autocomplete="organization"
            :disabled="saving"
          />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="fa-esign-rep">Authorized Representative</label>
          <input
            id="fa-esign-rep"
            v-model="repName"
            type="text"
            class="form-control"
            autocomplete="name"
            :disabled="saving"
          />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="fa-esign-date">Date</label>
          <input
            id="fa-esign-date"
            v-model="signedAt"
            type="date"
            class="form-control"
            :disabled="saving"
          />
        </div>
        <div class="col-12">
          <label class="form-label">Signature</label>
          <FulfillmentSignatureStylePicker
            v-model="signatureStyle"
            :signature-text="signaturePreviewText"
          />
        </div>
      </div>
      <p v-if="formError" class="text-danger small mt-3 mb-0">{{ formError }}</p>
    </div>

    <footer :class="CRM_DIALOG_FOOTER_CLASS">
      <button type="button" :class="CRM_BTN_SECONDARY" :disabled="saving" @click="close">
        Cancel
      </button>
      <button type="button" :class="CRM_BTN_PRIMARY" :disabled="saving" @click="submit">
        {{ saving ? "Saving…" : "Save Signature" }}
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>
