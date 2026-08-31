<script setup>
import { computed, ref, watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { LEAD_REFERRALS, leadReferralLabel } from "../../constants/leads.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "submit"]);

defineExpose({
  setError(message) {
    errorMsg.value = String(message || "").trim();
  },
  clearError() {
    errorMsg.value = "";
  },
});

const text = ref("");
const referral = ref("bizy");
const errorMsg = ref("");

const isGoogle = computed(() => referral.value === "google");

const subtitle = computed(() =>
  isGoogle.value
    ? "Paste Google lead fields (Full Name, Company, Email, Phone, Website, requirements)."
    : "Paste Company, Website, Email, and Email Thread.",
);

const placeholder = computed(() =>
  isGoogle.value
    ? "Full Name\t:\tLast, First\nCompany Name\t:\t…\nEmail\t:\t…\nPhone Number\t:\t…\nStore Website URL\t:\t…\nTell us about any special requirements\t:\t…\n\nSubject: …"
    : "Paste company, website, email, and any email thread…",
);

const canSubmit = computed(() => String(text.value || "").trim() !== "" && !props.busy);

watch(
  () => props.open,
  (open) => {
    if (open) {
      text.value = "";
      referral.value = "bizy";
      errorMsg.value = "";
    }
  },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function submit() {
  if (!canSubmit.value) {
    errorMsg.value = "Paste lead details to continue.";
    return;
  }
  errorMsg.value = "";
  emit("submit", {
    text: String(text.value).trim(),
    referral: referral.value,
  });
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Quick Add"
    :subtitle="subtitle"
    :busy="busy"
    max-width="xl"
    form-id="lead-quick-add-form"
    @update:open="close"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <p v-if="errorMsg" class="alert alert-danger py-2 mb-0 small">{{ errorMsg }}</p>

      <div>
        <span class="form-label d-block">Referral</span>
        <div class="d-flex flex-wrap gap-3">
          <div
            v-for="key in LEAD_REFERRALS"
            :key="key"
            class="form-check"
          >
            <input
              :id="`lead-quick-add-referral-${key}`"
              v-model="referral"
              class="form-check-input"
              type="radio"
              name="lead-quick-add-referral"
              :value="key"
              :disabled="busy"
            />
            <label class="form-check-label" :for="`lead-quick-add-referral-${key}`">
              {{ leadReferralLabel(key) }}
            </label>
          </div>
        </div>
      </div>

      <div>
        <label class="form-label" for="lead-quick-add-text">Paste Lead Info</label>
        <textarea
          id="lead-quick-add-text"
          v-model="text"
          class="form-control font-monospace"
          rows="14"
          :disabled="busy"
          :placeholder="placeholder"
        />
      </div>
    </div>

    <template #footer>
      <div :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="lead-quick-add-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="!canSubmit"
        >
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          Create Lead
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
