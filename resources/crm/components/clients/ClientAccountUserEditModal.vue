<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
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
const showPassword = ref(false);

const form = reactive({
  name: "",
  email: "",
  status: "active",
  password: "",
  password_confirmation: "",
});

const isPrimary = ref(false);

function reset() {
  errorMsg.value = "";
  form.name = "";
  form.email = "";
  form.status = "active";
  form.password = "";
  form.password_confirmation = "";
  showPassword.value = false;
  isPrimary.value = false;
}

async function load() {
  if (!props.clientAccountId || !props.userId) return;
  loading.value = true;
  errorMsg.value = "";
  reset();
  try {
    const { data } = await api.get(
      `/client-accounts/${props.clientAccountId}/account-users/${props.userId}`,
    );
    form.name = data.name || "";
    form.email = data.email || "";
    form.status = data.status || "active";
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
  try {
    const payload = {
      name: form.name.trim(),
      status: form.status,
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
    close();
    reset();
  } catch (e) {
    const errs = e.response?.data?.errors;
    if (errs && typeof errs === "object") {
      errorMsg.value = Object.values(errs).flat().join(" ");
    } else {
      errorMsg.value =
        typeof e.response?.data?.message === "string"
          ? e.response.data.message
          : "Could not save.";
    }
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
              <h2 id="cau-edit-title" class="crm-vx-modal__title">Edit portal user</h2>
              <p class="crm-vx-modal__subtitle">
                Update name, email, status, or set a new password.
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
                  <label class="form-label small">Full name</label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="form-control"
                    autocomplete="name"
                  />
                </div>
                <div class="mb-3">
                  <label class="form-label small">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :disabled="isPrimary"
                    :required="!isPrimary"
                    autocomplete="email"
                  />
                  <p v-if="isPrimary" class="small text-secondary mb-0 mt-1">
                    Primary admin email matches the client account; it cannot be changed here.
                  </p>
                </div>
                <div class="mb-3">
                  <label class="form-label small">Status</label>
                  <select v-model="form.status" class="form-select" required>
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
                <div class="mb-0">
                  <label class="form-label small">New password (optional)</label>
                  <div class="position-relative">
                    <input
                      v-model="form.password"
                      :type="showPassword ? 'text' : 'password'"
                      class="form-control pe-5"
                      autocomplete="new-password"
                      placeholder="Leave blank to keep current password"
                    />
                    <button
                      type="button"
                      class="btn btn-link btn-sm position-absolute end-0 top-50 translate-middle-y me-1 py-0"
                      @click="showPassword = !showPassword"
                    >
                      {{ showPassword ? "Hide" : "Show" }}
                    </button>
                  </div>
                </div>
                <div v-if="form.password.trim() !== ''" class="mb-0 mt-3">
                  <label class="form-label small">Confirm new password</label>
                  <input
                    v-model="form.password_confirmation"
                    :type="showPassword ? 'text' : 'password'"
                    class="form-control"
                    autocomplete="new-password"
                  />
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
