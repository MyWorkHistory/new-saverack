<script setup>
import { onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  profile: { type: Object, default: null },
  clientAccountId: { type: [String, Number], default: null },
  adminMode: { type: Boolean, default: false },
  taskId: { type: String, default: "account_information" },
  taskVerified: { type: Boolean, default: false },
  verifying: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "saved", "verify", "unverify"]);

function adminOnboardingBase() {
  if (!props.clientAccountId) return null;
  return `/client-accounts/${props.clientAccountId}/onboarding`;
}

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  name: "",
  email: "",
  company_name: "",
  phone: "",
  street: "",
  city: "",
  state: "",
  zip: "",
  country: "",
});

function close() {
  emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape") close();
}

function fillFromProfile(data) {
  if (!data || typeof data !== "object") return;
  form.name = data.name || data.contact_full_name || "";
  form.email = data.email || "";
  form.company_name = data.company_name || "";
  form.phone = data.phone || "";
  form.street = data.street || "";
  form.city = data.city || "";
  form.state = data.state || "";
  form.zip = data.zip || "";
  form.country = data.country || "";
}

watch(
  () => [props.open, props.profile],
  ([open]) => {
    if (open) {
      document.addEventListener("keydown", onEsc);
      fillFromProfile(props.profile);
      errorMsg.value = "";
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));

async function save() {
  if (saving.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    const payload = {
      name: form.name.trim(),
      email: form.email.trim(),
      company_name: form.company_name.trim(),
      phone: form.phone.trim(),
      street: form.street.trim(),
      city: form.city.trim(),
      state: form.state.trim(),
      zip: form.zip.trim(),
      country: form.country.trim(),
    };
    const base = adminOnboardingBase();
    const { data } = base
      ? await api.patch(`${base}/profile`, payload)
      : await api.patch("/portal/profile", payload);
    toast.success("Saved.");
    emit("saved", base ? data : data);
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" scrollable @update:open="emit('update:open', $event)">
    <button
      type="button"
      class="crm-vx-modal__close"
      aria-label="Close"
      :disabled="saving"
      @click="close"
    >
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">Add Account Information</h2>
    </header>
    <form
      id="portal-onboard-account-form"
      class="crm-vx-modal__body portal-onboard-modal__body"
      @submit.prevent="save"
    >
            <p v-if="errorMsg" class="text-danger small">{{ errorMsg }}</p>

            <h3 class="h6 fw-semibold mb-3">Personal Information</h3>
            <div class="mb-3">
              <label class="form-label" for="onboard-company">Company</label>
              <input
                id="onboard-company"
                v-model="form.company_name"
                type="text"
                class="form-control"
                required
                autocomplete="organization"
              />
            </div>
            <div class="mb-3">
              <label class="form-label" for="onboard-email">Email</label>
              <input
                id="onboard-email"
                v-model="form.email"
                type="email"
                class="form-control"
                required
                autocomplete="email"
              />
            </div>
            <div class="mb-3">
              <label class="form-label" for="onboard-name">Name</label>
              <input
                id="onboard-name"
                v-model="form.name"
                type="text"
                class="form-control"
                required
                autocomplete="name"
              />
            </div>
            <div class="mb-4">
              <label class="form-label" for="onboard-phone">Phone Number</label>
              <input
                id="onboard-phone"
                v-model="form.phone"
                type="tel"
                class="form-control"
                required
                autocomplete="tel"
              />
            </div>

            <h3 class="h6 fw-semibold mb-3">Address</h3>
            <div class="mb-3">
              <label class="form-label" for="onboard-street">Street</label>
              <input
                id="onboard-street"
                v-model="form.street"
                type="text"
                class="form-control"
                required
                autocomplete="street-address"
              />
            </div>
            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <label class="form-label" for="onboard-city">City</label>
                <input
                  id="onboard-city"
                  v-model="form.city"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="address-level2"
                />
              </div>
              <div class="col-md-3">
                <label class="form-label" for="onboard-state">State</label>
                <input
                  id="onboard-state"
                  v-model="form.state"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="address-level1"
                />
              </div>
              <div class="col-md-3">
                <label class="form-label" for="onboard-zip">ZIP</label>
                <input
                  id="onboard-zip"
                  v-model="form.zip"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="postal-code"
                />
              </div>
              <div class="col-12">
                <label class="form-label" for="onboard-country">Country</label>
                <input
                  id="onboard-country"
                  v-model="form.country"
                  type="text"
                  class="form-control"
                  required
                  autocomplete="country-name"
                />
              </div>
            </div>

    </form>
    <footer class="crm-vx-modal__footer flex-wrap gap-2">
      <button
        v-if="adminMode && taskVerified"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
        :disabled="saving || verifying"
        @click="emit('unverify')"
      >
        <CrmLoadingSpinner v-if="verifying" small class="me-1" />
        Remove Verification
      </button>
      <button
        v-else-if="adminMode"
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary me-auto"
        :disabled="saving || verifying"
        @click="emit('verify')"
      >
        <CrmLoadingSpinner v-if="verifying" small class="me-1" />
        Verify
      </button>
      <button
        type="button"
        class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
        :disabled="saving || verifying"
        @click="close"
      >
        Cancel
      </button>
      <button
        type="submit"
        form="portal-onboard-account-form"
        class="crm-vx-modal-btn crm-vx-modal-btn--primary"
        :disabled="saving || verifying"
      >
        <CrmLoadingSpinner v-if="saving" small class="me-1" />
        Save
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>
