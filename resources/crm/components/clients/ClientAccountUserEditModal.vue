<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import {
  allValidationMessages,
  fieldValidationErrors,
} from "../../utils/apiError.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccountId: { type: String, default: "" },
  userId: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "saved"]);

const loading = ref(false);
const saving = ref(false);
const errorMsg = ref("");
const fieldErrors = ref({});
const showPassword = ref(false);

const form = reactive({
  name: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
});

const isPrimary = ref(false);

function reset() {
  errorMsg.value = "";
  fieldErrors.value = {};
  form.name = "";
  form.email = "";
  form.phone = "";
  form.password = "";
  form.password_confirmation = "";
  showPassword.value = false;
  isPrimary.value = false;
}

function clearFieldError(key) {
  if (!fieldErrors.value[key]) return;
  const next = { ...fieldErrors.value };
  delete next[key];
  fieldErrors.value = next;
}

function generatePassword() {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*";
  const bytes = new Uint8Array(14);
  crypto.getRandomValues(bytes);
  const password = Array.from(bytes, (b) => chars[b % chars.length]).join("");
  form.password = password;
  form.password_confirmation = password;
  showPassword.value = true;
  clearFieldError("password");
  clearFieldError("password_confirmation");
}

async function load() {
  if (!props.clientAccountId || !props.userId) return;
  loading.value = true;
  errorMsg.value = "";
  fieldErrors.value = {};
  reset();
  try {
    const { data } = await api.get(
      `/client-accounts/${props.clientAccountId}/account-users/${props.userId}`,
    );
    form.name = data.name || "";
    form.email = data.email || "";
    form.phone = data.phone || "";
    isPrimary.value = !!data.is_account_primary;
  } catch {
    errorMsg.value = "Could not load user.";
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.clientAccountId, props.userId],
  ([isOpen]) => {
    if (isOpen && props.clientAccountId && props.userId) {
      load();
    }
  },
);

function close() {
  if (!saving.value) emit("update:open", false);
}

function onBackdropClick() {
  if (!saving.value) close();
}

async function onSubmit() {
  if (!props.clientAccountId || !props.userId) return;
  saving.value = true;
  errorMsg.value = "";
  fieldErrors.value = {};
  try {
    const payload = {
      name: form.name.trim(),
      phone: String(form.phone || "").trim() || null,
    };
    if (!isPrimary.value) {
      payload.email = form.email.trim();
    }
    const pw = form.password.trim();
    if (pw !== "") {
      payload.password = pw;
      payload.password_confirmation = form.password_confirmation.trim();
    }
    await api.patch(
      `/client-accounts/${props.clientAccountId}/account-users/${props.userId}`,
      payload,
    );
    emit("saved");
    emit("update:open", false);
    reset();
  } catch (e) {
    fieldErrors.value = fieldValidationErrors(e);
    errorMsg.value = allValidationMessages(e, "Could not save.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="cau-edit-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="onBackdropClick" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm">
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="saving"
              @click="close"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.75"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>

            <header class="crm-vx-modal__head">
              <h2 id="cau-edit-title" class="crm-vx-modal__title">
                Personal Information
              </h2>
              <p class="crm-vx-modal__subtitle">
                Update name, email, phone, or set a new password.
              </p>
            </header>

            <div class="crm-vx-modal__body">
              <p v-if="errorMsg" class="small text-danger mb-3 text-center">
                {{ errorMsg }}
              </p>
              <div v-if="loading" class="d-flex justify-content-center py-4">
                <CrmLoadingSpinner message="Loading…" />
              </div>
              <form
                v-else
                id="cau-edit-form"
                class="text-start"
                @submit.prevent="onSubmit"
              >
                <div class="mb-3">
                  <label class="form-label small">Full Name</label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': fieldErrors.name }"
                    autocomplete="name"
                    @input="clearFieldError('name')"
                  />
                  <p v-if="fieldErrors.name" class="small text-danger mb-0 mt-1">
                    {{ fieldErrors.name }}
                  </p>
                </div>
                <div class="mb-3">
                  <label class="form-label small">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': fieldErrors.email }"
                    :disabled="isPrimary"
                    :required="!isPrimary"
                    autocomplete="email"
                    @input="clearFieldError('email')"
                  />
                  <p v-if="fieldErrors.email" class="small text-danger mb-0 mt-1">
                    {{ fieldErrors.email }}
                  </p>
                  <p v-else-if="isPrimary" class="small text-secondary mb-0 mt-1">
                    Primary admin email matches the client account; it cannot be changed here.
                  </p>
                </div>
                <div class="mb-3">
                  <label class="form-label small">Phone</label>
                  <input
                    v-model="form.phone"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': fieldErrors.phone }"
                    autocomplete="tel"
                    placeholder="Optional"
                    @input="clearFieldError('phone')"
                  />
                  <p v-if="fieldErrors.phone" class="small text-danger mb-0 mt-1">
                    {{ fieldErrors.phone }}
                  </p>
                </div>
                <div class="mb-0">
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="form-label small mb-0">New Password (optional)</label>
                    <button
                      type="button"
                      class="btn btn-link btn-sm p-0"
                      :disabled="saving"
                      @click="generatePassword"
                    >
                      Generate Password
                    </button>
                  </div>
                  <div class="position-relative">
                    <input
                      v-model="form.password"
                      :type="showPassword ? 'text' : 'password'"
                      class="form-control pe-5"
                      :class="{ 'is-invalid': fieldErrors.password }"
                      autocomplete="new-password"
                      placeholder="Leave blank to keep current password"
                      @input="clearFieldError('password')"
                    />
                    <button
                      type="button"
                      class="btn btn-link btn-sm position-absolute end-0 top-50 translate-middle-y me-1 py-0"
                      @click="showPassword = !showPassword"
                    >
                      {{ showPassword ? "Hide" : "Show" }}
                    </button>
                  </div>
                  <p v-if="fieldErrors.password" class="small text-danger mb-0 mt-1">
                    {{ fieldErrors.password }}
                  </p>
                </div>
                <div v-if="form.password.trim() !== ''" class="mb-0 mt-3">
                  <label class="form-label small">Confirm New Password</label>
                  <input
                    v-model="form.password_confirmation"
                    :type="showPassword ? 'text' : 'password'"
                    class="form-control"
                    :class="{ 'is-invalid': fieldErrors.password_confirmation }"
                    autocomplete="new-password"
                    @input="clearFieldError('password_confirmation')"
                  />
                  <p
                    v-if="fieldErrors.password_confirmation"
                    class="small text-danger mb-0 mt-1"
                  >
                    {{ fieldErrors.password_confirmation }}
                  </p>
                </div>
              </form>
            </div>

            <footer v-if="!loading" :class="CRM_DIALOG_FOOTER_CLASS">
              <button type="button" :class="CRM_BTN_SECONDARY" :disabled="saving" @click="close">
                Cancel
              </button>
              <button
                type="submit"
                form="cau-edit-form"
                :class="CRM_BTN_PRIMARY"
                :disabled="saving"
              >
                {{ saving ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}
.modal-panel-enter-active,
.modal-panel-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
</style>
