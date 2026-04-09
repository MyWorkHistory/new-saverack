<script setup>
import { computed, onUnmounted, watch } from "vue";
import UserFormFields from "./UserFormFields.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useUserForm } from "../../composables/useUserForm";

const props = defineProps({
  open: { type: Boolean, default: false },
  userId: { type: String, default: "" },
  /** Keys passed to UserFormFields: identity, access, contact, address, employment, bio */
  sectionKeys: {
    type: Array,
    default: () => [],
  },
  title: { type: String, default: "Edit" },
  subtitle: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "saved"]);

const {
  loading,
  saving,
  errorMsg,
  form,
  roles,
  pendingAvatarFile,
  profileAvatarUrl,
  loadRoles,
  loadUser,
  submit,
  uploadAvatarFile,
  deleteAvatarFile,
  toggleRole,
  clearFieldError,
  firstError,
} = useUserForm();

const visibleRoles = computed(() =>
  Array.isArray(roles.value)
    ? roles.value.filter((r) => String(r?.name || "").toLowerCase() !== "client")
    : [],
);

async function hydrate() {
  const id = props.userId;
  if (!id) return;
  loading.value = true;
  errorMsg.value = "";
  try {
    const needsRoles = props.sectionKeys.includes("access");
    await Promise.all([
      needsRoles ? loadRoles() : Promise.resolve(),
      loadUser(id),
    ]);
  } catch {
    errorMsg.value = "Could Not Load User.";
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.userId, props.sectionKeys],
  ([isOpen]) => {
    if (!isOpen || !props.userId) return;
    hydrate();
  },
);

function close() {
  emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape" && props.open && !saving.value) {
    e.preventDefault();
    close();
  }
}

watch(
  () => props.open,
  (o) => {
    if (o) {
      document.addEventListener("keydown", onEsc);
    } else {
      document.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

async function onSubmit() {
  const id = props.userId;
  if (!id) return;
  const ok = await submit({ isEdit: true, userId: id });
  if (ok) {
    emit("saved");
    close();
  }
}

function onBackdropClick() {
  if (!saving.value) close();
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
        :aria-labelledby="'user-section-modal-title'"
      >
        <div
          class="crm-vx-modal-backdrop"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal">
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
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>

            <header class="crm-vx-modal__head">
              <h2 id="user-section-modal-title" class="crm-vx-modal__title">
                {{ title }}
              </h2>
              <p v-if="subtitle" class="crm-vx-modal__subtitle">
                {{ subtitle }}
              </p>
            </header>

            <div class="crm-vx-modal__body">
              <p
                v-if="errorMsg"
                class="small text-danger mb-3 text-center"
              >
                {{ errorMsg }}
              </p>

              <form
                v-if="!loading && userId"
                id="user-section-modal-form"
                @submit.prevent="onSubmit"
              >
                <UserFormFields
                  v-model:pending-avatar-file="pendingAvatarFile"
                  :form="form"
                  :roles="visibleRoles"
                  :is-edit="true"
                  :user-id="userId"
                  :avatar-url="profileAvatarUrl"
                  :upload-avatar="(f) => uploadAvatarFile(userId, f)"
                  :delete-avatar="() => deleteAvatarFile(userId)"
                  :saving="saving"
                  :first-error="firstError"
                  :clear-field-error="clearFieldError"
                  :toggle-role="toggleRole"
                  :sections="sectionKeys"
                  :show-avatar="false"
                  :show-section-titles="false"
                />
              </form>
              <div v-else-if="loading" class="d-flex justify-content-center py-5">
                <CrmLoadingSpinner message="Loading User…" />
              </div>
            </div>

            <footer
              v-if="!loading && userId"
              class="crm-vx-modal__footer"
            >
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="user-section-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
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
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
