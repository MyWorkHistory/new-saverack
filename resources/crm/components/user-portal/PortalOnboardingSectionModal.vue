<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import {
  fieldRequiresAdminVerification,
  fieldTutorialUrl,
  getPortalOnboardingSection,
  sectionUsesAdminFieldVerification,
} from "../../constants/portalOnboardingSections.js";
import PortalOnboardingFieldVerifyToggle from "./PortalOnboardingFieldVerifyToggle.vue";
import PortalOnboardingTutorialLink from "./PortalOnboardingTutorialLink.vue";
import { useToast } from "../../composables/useToast";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  sectionId: { type: String, default: "" },
  preferences: { type: Object, default: () => ({}) },
  brandLogoUrl: { type: String, default: "" },
  profile: { type: Object, default: null },
  clientAccountId: { type: [String, Number], default: null },
  adminMode: { type: Boolean, default: false },
  taskId: { type: String, default: "" },
  taskVerified: { type: Boolean, default: false },
  verifying: { type: Boolean, default: false },
  taskVerificationFields: { type: Object, default: () => ({}) },
  verificationFieldsComplete: { type: Boolean, default: true },
  fieldVerifyingKey: { type: String, default: "" },
  pageMode: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "saved", "verify", "unverify", "toggle-field-verification"]);

function adminOnboardingBase() {
  if (!props.clientAccountId) return null;
  return `/client-accounts/${props.clientAccountId}/onboarding`;
}

const toast = useToast();
const saving = ref(false);
const logoUploading = ref(false);
const errorMsg = ref("");
const logoPreviewUrl = ref("");
const logoFile = ref(null);

const form = reactive({});

const section = computed(() => getPortalOnboardingSection(props.sectionId));

const logoPreviewDisplayUrl = computed(() => {
  const u = logoPreviewUrl.value;
  if (!u) return "";
  if (/^blob:/i.test(u)) return u;

  return resolvePublicUrl(u) || u;
});

const sectionPrefs = computed(() => {
  const prefs = props.preferences;
  if (!prefs || typeof prefs !== "object") return {};

  // The welcome page uses one shared lightbox for multiple “order handling” cards.
  // When that happens, `props.sectionId` is `order_handling_preferences`, but some
  // fields (out_of_stock_handling/address_verification/fraud_review_holds) live in
  // their own preference blocks in `props.preferences`.
  if (props.sectionId === "order_handling_preferences") {
    const orderHandling = prefs.order_handling_preferences;
    const outOfStock = prefs.out_of_stock_handling;
    const addressVerification = prefs.address_verification;
    const fraudReview = prefs.fraud_review_holds;

    return {
      ...(orderHandling && typeof orderHandling === "object" ? orderHandling : {}),
      ...(outOfStock && typeof outOfStock === "object" ? outOfStock : {}),
      ...(addressVerification && typeof addressVerification === "object" ? addressVerification : {}),
      ...(fraudReview && typeof fraudReview === "object" ? fraudReview : {}),
    };
  }

  const block = prefs[props.sectionId];
  return block && typeof block === "object" ? block : {};
});

function fieldVisible(field) {
  const rule = field.showWhen;
  if (!rule || typeof rule !== "object") return true;
  return String(form[rule.field] ?? "") === String(rule.value);
}

function close() {
  if (!props.pageMode) emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape") close();
}

function fillForm() {
  const fields = section.value?.fields || [];
  for (const f of fields) {
    if (f.type === "file") continue;
    form[f.key] = sectionPrefs.value[f.key] ?? "";
  }
  if (
    props.sectionId === "communication_preferences" &&
    String(form.communication_method || "").trim() === "email" &&
    String(form.contact_email || "").trim() === ""
  ) {
    form.contact_email = String(
      sectionPrefs.value.contact_email || props.profile?.email || "",
    ).trim();
  }
  logoPreviewUrl.value = props.brandLogoUrl || "";
  logoFile.value = null;
}

watch(
  () => [props.open, props.pageMode, props.sectionId, props.preferences, props.brandLogoUrl, props.profile],
  ([open, pageMode]) => {
    if (open || pageMode) {
      if (!pageMode) document.addEventListener("keydown", onEsc);
      fillForm();
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
  { immediate: true },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

function logoUrlFromUploadResponse(data) {
  if (!data || typeof data !== "object") return null;
  if (data.brand_logo_url) return data.brand_logo_url;
  if (data.onboarding?.brand_logo_url) return data.onboarding.brand_logo_url;

  return null;
}

function onboardingPayloadFromResponse(data) {
  if (!data || typeof data !== "object") return null;
  if (Array.isArray(data.tasks)) return data;
  if (data.onboarding && typeof data.onboarding === "object") return data.onboarding;

  return null;
}

async function uploadLogoFile(file) {
  if (!file || props.sectionId !== "branding_information") {
    return null;
  }
  logoUploading.value = true;
  errorMsg.value = "";
  try {
    const fd = new FormData();
    fd.append("logo", file);
    const base = adminOnboardingBase();
    const { data } = base
      ? await api.post(`${base}/branding/logo`, fd)
      : await api.post("/portal/onboarding/branding/logo", fd);
    const url = logoUrlFromUploadResponse(data);
    if (url) {
      logoPreviewUrl.value = url;
    }
    return data;
  } finally {
    logoUploading.value = false;
  }
}

async function onLogoChange(ev) {
  const file = ev?.target?.files?.[0];
  logoFile.value = file || null;
  if (!file) {
    return;
  }
  logoPreviewUrl.value = URL.createObjectURL(file);
  if (props.adminMode && adminOnboardingBase()) {
    try {
      const data = await uploadLogoFile(file);
      logoFile.value = null;
      const onboarding = onboardingPayloadFromResponse(data);
      if (onboarding) {
        emit("saved", onboarding);
        toast.success("Logo uploaded.");
      } else if (data?.brand_logo_url) {
        emit("saved", { brand_logo_url: data.brand_logo_url });
        toast.success("Logo uploaded.");
      }
    } catch (e) {
      errorMsg.value = "Could not upload logo.";
      toast.errorFrom(e, "Could not upload logo.");
    }
  }
}

async function save() {
  if (saving.value || !section.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    let resultPayload = null;
    let logoData = null;

    if (props.sectionId === "branding_information" && logoFile.value) {
      logoData = await uploadLogoFile(logoFile.value);
      logoFile.value = null;
      resultPayload = onboardingPayloadFromResponse(logoData);
    }

    const payload = {};
    for (const field of section.value.fields) {
      if (field.type === "file") continue;
      if (!fieldVisible(field)) continue;
      payload[field.key] = form[field.key];
    }

    const base = adminOnboardingBase();
    const shouldPatch = Object.keys(payload).length > 0 || !base;
    if (shouldPatch) {
      const { data } = base
        ? await api.patch(`${base}/preferences/${props.sectionId}`, payload)
        : await api.patch(`/portal/onboarding/preferences/${props.sectionId}`, payload);
      resultPayload = data;
    }

    if (!resultPayload && logoData) {
      resultPayload = onboardingPayloadFromResponse(logoData);
    }

    if (!resultPayload) {
      throw new Error("Nothing to save.");
    }

    toast.success("Saved.");
    emit("saved", resultPayload);
    if (!props.pageMode) close();
  } catch (e) {
    errorMsg.value = "Could not save. Check required fields.";
    toast.errorFrom(e, "Could not save preferences.");
  } finally {
    saving.value = false;
  }
}

const isCommunicationSection = computed(() => props.sectionId === "communication_preferences");

const isBrandingSection = computed(() => props.sectionId === "branding_information");

function setCommunicationMethod(value) {
  form.communication_method = value;
  if (value === "email" && String(form.contact_email || "").trim() === "") {
    form.contact_email = String(
      sectionPrefs.value.contact_email || props.profile?.email || "",
    ).trim();
  }
}

const usesFieldVerification = computed(
  () => props.adminMode && sectionUsesAdminFieldVerification(props.sectionId),
);

const canVerifySection = computed(() => {
  if (!usesFieldVerification.value) return true;
  return props.verificationFieldsComplete;
});

function showAdminFieldVerification(field) {
  return props.adminMode && fieldRequiresAdminVerification(props.sectionId, field.key);
}

function isFieldVerified(fieldKey) {
  return !!props.taskVerificationFields?.[fieldKey];
}

function toggleFieldVerification(fieldKey, checked) {
  emit("toggle-field-verification", {
    fieldKey,
    checked: typeof checked === "boolean" ? checked : !isFieldVerified(fieldKey),
  });
}
</script>

<template>
  <component
    :is="pageMode ? 'div' : PortalOnboardingModalShell"
    v-bind="pageMode ? { class: 'portal-section-page-panel' } : { open: open && !!section, scrollable: true }"
    @update:open="emit('update:open', $event)"
  >
    <template v-if="section">
      <button
        v-if="!pageMode"
        type="button"
        class="crm-vx-modal__close"
        aria-label="Close"
        :disabled="saving || logoUploading"
        @click="close"
      >
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
      <header v-if="!pageMode" class="crm-vx-modal__head">
        <h2 class="crm-vx-modal__title">{{ section.modalTitle }}</h2>
      </header>
      <div
        class="crm-vx-modal__body portal-onboard-modal__body"
        :class="{ 'portal-section-page-panel__body': pageMode }"
      >
            <p v-if="errorMsg" class="text-danger small">{{ errorMsg }}</p>

            <template v-if="isCommunicationSection">
              <p class="text-secondary mb-4">
                {{ section.intro }}
              </p>

              <div class="portal-billing-options d-flex flex-column gap-3">
                <label
                  v-for="opt in section.communicationOptions || []"
                  :key="opt.value"
                  class="portal-billing-option border rounded p-3 mb-0"
                >
                  <input
                    :checked="form.communication_method === opt.value"
                    type="radio"
                    name="portal-communication-method"
                    class="form-check-input me-2"
                    :value="opt.value"
                    @change="setCommunicationMethod(opt.value)"
                  />
                  <span class="fw-semibold">{{ opt.title }}</span>
                  <p class="small text-secondary mb-0 mt-2 ms-4">
                    {{ opt.description }}
                  </p>
                </label>
              </div>

              <div
                v-if="form.communication_method === 'whatsapp'"
                class="portal-billing-detail mt-4 p-3 rounded bg-light"
              >
                <label class="form-label fw-semibold" for="onboard-whatsapp-phone">
                  Phone Number
                </label>
                <input
                  id="onboard-whatsapp-phone"
                  v-model="form.whatsapp_phone"
                  type="text"
                  class="form-control"
                  placeholder="Enter Phone Number"
                  required
                />
              </div>

              <div
                v-else-if="form.communication_method === 'slack'"
                class="portal-billing-detail mt-4 p-3 rounded bg-light"
              >
                <label class="form-label fw-semibold" for="onboard-slack-email">
                  Email Address
                </label>
                <input
                  id="onboard-slack-email"
                  v-model="form.slack_email"
                  type="email"
                  class="form-control"
                  placeholder="Enter Email Address"
                  required
                />
              </div>

              <div
                v-else-if="form.communication_method === 'email'"
                class="portal-billing-detail mt-4 p-3 rounded bg-light"
              >
                <label class="form-label fw-semibold" for="onboard-contact-email">
                  Enter Your Email
                </label>
                <input
                  id="onboard-contact-email"
                  v-model="form.contact_email"
                  type="email"
                  class="form-control"
                  placeholder="Enter Email"
                  required
                />
              </div>
            </template>

            <template v-else>
              <div
                v-for="field in section.fields"
                v-show="fieldVisible(field)"
                :key="field.key"
                class="mb-4"
              >
              <div
                class="d-flex align-items-center gap-2 mb-1"
              >
                <label
                  v-if="field.type !== 'file'"
                  class="form-label fw-semibold mb-0"
                  :for="`onboard-${field.key}`"
                >
                  {{ field.label }}
                </label>
                <label
                  v-else
                  class="form-label fw-semibold mb-0"
                  :for="`onboard-${field.key}`"
                >
                  {{ field.label }}
                </label>
                <PortalOnboardingTutorialLink
                  v-if="showAdminFieldVerification(field)"
                  :href="fieldTutorialUrl(sectionId, field.key)"
                  :label="`Tutorial: ${field.label}`"
                />
              </div>

              <div
                v-if="field.type === 'text'"
                class="portal-onboard-field-input-row"
                :class="{ 'd-flex align-items-center gap-2': showAdminFieldVerification(field) }"
              >
                <input
                  :id="`onboard-${field.key}`"
                  v-model="form[field.key]"
                  type="text"
                  class="form-control"
                  :class="{ 'flex-grow-1 min-w-0': showAdminFieldVerification(field) }"
                  :required="field.required"
                />
                <PortalOnboardingFieldVerifyToggle
                  v-if="showAdminFieldVerification(field)"
                  class="flex-shrink-0"
                  :checked="isFieldVerified(field.key)"
                  :loading="fieldVerifyingKey === field.key"
                  :disabled="!!fieldVerifyingKey && fieldVerifyingKey !== field.key"
                  @toggle="(checked) => toggleFieldVerification(field.key, checked)"
                />
              </div>

              <div
                v-else-if="field.type === 'select'"
                class="portal-onboard-field-input-row"
                :class="{ 'd-flex align-items-center gap-2': showAdminFieldVerification(field) }"
              >
                <select
                  :id="`onboard-${field.key}`"
                  v-model="form[field.key]"
                  class="form-select"
                  :class="{ 'flex-grow-1 min-w-0': showAdminFieldVerification(field) }"
                  :required="field.required"
                >
                  <option value="" disabled>Select…</option>
                  <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
                <PortalOnboardingFieldVerifyToggle
                  v-if="showAdminFieldVerification(field)"
                  class="flex-shrink-0"
                  :checked="isFieldVerified(field.key)"
                  :loading="fieldVerifyingKey === field.key"
                  :disabled="!!fieldVerifyingKey && fieldVerifyingKey !== field.key"
                  @toggle="(checked) => toggleFieldVerification(field.key, checked)"
                />
              </div>

              <div
                v-else-if="field.type === 'textarea'"
                class="portal-onboard-field-input-row"
                :class="{ 'd-flex align-items-start gap-2': showAdminFieldVerification(field) }"
              >
                <textarea
                  :id="`onboard-${field.key}`"
                  v-model="form[field.key]"
                  class="form-control"
                  :class="{ 'flex-grow-1 min-w-0': showAdminFieldVerification(field) }"
                  rows="4"
                  :required="field.required"
                />
                <PortalOnboardingFieldVerifyToggle
                  v-if="showAdminFieldVerification(field)"
                  class="flex-shrink-0 mt-1"
                  :checked="isFieldVerified(field.key)"
                  :loading="fieldVerifyingKey === field.key"
                  :disabled="!!fieldVerifyingKey && fieldVerifyingKey !== field.key"
                  @toggle="(checked) => toggleFieldVerification(field.key, checked)"
                />
              </div>

              <div v-else-if="field.type === 'file'" class="portal-onboard-file-field">
                <div v-if="logoPreviewUrl" class="mb-2">
                  <img
                    :src="logoPreviewDisplayUrl"
                    alt=""
                    class="portal-onboard-logo-preview"
                  />
                </div>
                <p
                  v-else-if="isBrandingSection"
                  class="small text-secondary mb-2 portal-onboard-logo-empty"
                >
                  No logo uploaded yet.
                </p>
                <input
                  :id="`onboard-${field.key}`"
                  type="file"
                  class="form-control"
                  accept="image/jpeg,image/png,image/jpg,image/webp,.jpg,.jpeg,.png,.webp"
                  @change="onLogoChange"
                />
              </div>

              <p v-if="field.help" class="small text-secondary mb-0 mt-2">
                {{ field.help }}
              </p>
              </div>
            </template>
      </div>
      <footer
        class="crm-vx-modal__footer flex-wrap gap-2"
        :class="{ 'portal-section-page-panel__footer': pageMode }"
      >
        <p
          v-if="adminMode && usesFieldVerification && !verificationFieldsComplete"
          class="small text-secondary mb-0 me-auto align-self-center"
        >
          Check each item before verifying this section.
        </p>
        <button
          v-if="adminMode && taskVerified"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
          :class="{ 'ms-auto': !(usesFieldVerification && !verificationFieldsComplete) }"
          :disabled="saving || logoUploading || verifying"
          @click="emit('unverify')"
        >
          <CrmLoadingSpinner v-if="verifying" small class="me-1" />
          Remove Verification
        </button>
        <button
          v-else-if="adminMode"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
          :class="{ 'ms-auto': !(usesFieldVerification && !verificationFieldsComplete) }"
          :disabled="saving || logoUploading || verifying || !canVerifySection"
          @click="emit('verify')"
        >
          <CrmLoadingSpinner v-if="verifying" small class="me-1" />
          Verify
        </button>
        <button
          v-if="!pageMode"
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="saving || logoUploading || verifying"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--primary"
          :class="{ 'ms-auto': pageMode }"
          :disabled="saving || logoUploading || verifying"
          @click="save"
        >
          <template v-if="saving || logoUploading">
            <CrmLoadingSpinner small class="me-1" />
            Saving…
          </template>
          <template v-else>Save</template>
        </button>
      </footer>
    </template>
  </component>
</template>

<style scoped>
.portal-onboard-logo-preview {
  max-height: 200px;
  max-width: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  border-radius: 0.375rem;
  border: 1px solid var(--bs-border-color);
  background: #fff;
  padding: 0.25rem;
}

.portal-onboard-logo-empty {
  border: 1px dashed var(--bs-border-color);
  border-radius: 0.375rem;
  padding: 0.75rem 1rem;
  background: var(--bs-light, #f8f9fa);
}

.portal-billing-option {
  cursor: pointer;
}

.portal-billing-option:has(input:checked) {
  border-color: var(--bs-primary) !important;
  background: rgba(var(--bs-primary-rgb), 0.04);
}

.portal-section-page-panel__body,
.portal-section-page-panel__footer {
  padding-left: 1.25rem;
  padding-right: 1.25rem;
}

.portal-section-page-panel__body {
  padding-top: 1.25rem;
  padding-bottom: 1rem;
}

.portal-section-page-panel__footer {
  padding-top: 0.75rem;
  padding-bottom: 1.25rem;
  border-top: 1px solid var(--bs-border-color, #e5e7eb);
}
</style>
