<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import { getPortalOnboardingSection } from "../../constants/portalOnboardingSections.js";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  sectionId: { type: String, default: "" },
  preferences: { type: Object, default: () => ({}) },
  brandLogoUrl: { type: String, default: "" },
  profile: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const logoUploading = ref(false);
const errorMsg = ref("");
const logoPreviewUrl = ref("");
const logoFile = ref(null);

const form = reactive({});

const section = computed(() => getPortalOnboardingSection(props.sectionId));

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
  emit("update:open", false);
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
    form.contact_email = String(props.profile?.email || "").trim();
  }
  logoPreviewUrl.value = props.brandLogoUrl || "";
  logoFile.value = null;
}

watch(
  () => [props.open, props.sectionId, props.preferences, props.brandLogoUrl, props.profile],
  ([open]) => {
    if (open) {
      document.addEventListener("keydown", onEsc);
      fillForm();
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

function onLogoChange(ev) {
  const file = ev?.target?.files?.[0];
  logoFile.value = file || null;
  if (file) {
    logoPreviewUrl.value = URL.createObjectURL(file);
  }
}

async function uploadLogoIfNeeded() {
  if (!logoFile.value || props.sectionId !== "branding_information") {
    return null;
  }
  logoUploading.value = true;
  try {
    const fd = new FormData();
    fd.append("logo", logoFile.value);
    const { data } = await api.post("/portal/onboarding/branding/logo", fd);
    return data;
  } finally {
    logoUploading.value = false;
  }
}

async function save() {
  if (saving.value || !section.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    if (props.sectionId === "branding_information" && logoFile.value) {
      await uploadLogoIfNeeded();
    }

    const payload = {};
    for (const field of section.value.fields) {
      if (field.type === "file") continue;
      if (!fieldVisible(field)) continue;
      payload[field.key] = form[field.key];
    }

    const { data } = await api.patch(
      `/portal/onboarding/preferences/${props.sectionId}`,
      payload,
    );
    toast.success("Saved.");
    emit("saved", data);
    close();
  } catch (e) {
    errorMsg.value = "Could not save. Check required fields.";
    toast.errorFrom(e, "Could not save preferences.");
  } finally {
    saving.value = false;
  }
}

const isCommunicationSection = computed(() => props.sectionId === "communication_preferences");

function setCommunicationMethod(value) {
  form.communication_method = value;
  if (value === "email" && String(form.contact_email || "").trim() === "") {
    form.contact_email = String(props.profile?.email || "").trim();
  }
}
</script>

<template>
  <PortalOnboardingModalShell
    :open="open && !!section"
    scrollable
    @update:open="emit('update:open', $event)"
  >
    <template v-if="section">
      <button
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
      <header class="crm-vx-modal__head">
        <h2 class="crm-vx-modal__title">{{ section.modalTitle }}</h2>
      </header>
      <div class="crm-vx-modal__body portal-onboard-modal__body">
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
              <label v-if="field.type !== 'file'" class="form-label fw-semibold" :for="`onboard-${field.key}`">
                {{ field.label }}
              </label>
              <label v-else class="form-label fw-semibold" :for="`onboard-${field.key}`">
                {{ field.label }}
              </label>

              <input
                v-if="field.type === 'text'"
                :id="`onboard-${field.key}`"
                v-model="form[field.key]"
                type="text"
                class="form-control"
                :required="field.required"
              />

              <select
                v-else-if="field.type === 'select'"
                :id="`onboard-${field.key}`"
                v-model="form[field.key]"
                class="form-select"
                :required="field.required"
              >
                <option value="" disabled>Select…</option>
                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>

              <textarea
                v-else-if="field.type === 'textarea'"
                :id="`onboard-${field.key}`"
                v-model="form[field.key]"
                class="form-control"
                rows="4"
                :required="field.required"
              />

              <div v-else-if="field.type === 'file'" class="portal-onboard-file-field">
                <div v-if="logoPreviewUrl" class="mb-2">
                  <img
                    :src="logoPreviewUrl"
                    alt=""
                    class="portal-onboard-logo-preview"
                  />
                </div>
                <input
                  :id="`onboard-${field.key}`"
                  type="file"
                  class="form-control"
                  accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                  @change="onLogoChange"
                />
              </div>

              <p v-if="field.help" class="small text-secondary mb-0 mt-2">
                {{ field.help }}
              </p>
              </div>
            </template>
      </div>
      <footer class="crm-vx-modal__footer">
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
          :disabled="saving || logoUploading"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="button"
          class="crm-vx-modal-btn crm-vx-modal-btn--primary"
          :disabled="saving || logoUploading"
          @click="save"
        >
          <CrmLoadingSpinner v-if="saving || logoUploading" small class="me-1" />
          Save
        </button>
      </footer>
    </template>
  </PortalOnboardingModalShell>
</template>

<style scoped>
.portal-onboard-logo-preview {
  max-height: 120px;
  max-width: 100%;
  object-fit: contain;
  border-radius: 0.375rem;
  border: 1px solid var(--bs-border-color);
}

.portal-billing-option {
  cursor: pointer;
}

.portal-billing-option:has(input:checked) {
  border-color: var(--bs-primary) !important;
  background: rgba(var(--bs-primary-rgb), 0.04);
}
</style>
