<script setup>
import { onUnmounted, watch } from "vue";
import UserFormFields from "./UserFormFields.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useUserForm } from "../../composables/useUserForm";

const props = defineProps({
  open: { type: Boolean, default: false },
  userId: { type: String, default: "" },
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

async function hydrate() {
  const id = props.userId;
  if (!id) return;
  loading.value = true;
  errorMsg.value = "";
  try {
    await loadRoles();
    await loadUser(id);
  } catch {
    errorMsg.value = "Could Not Load User.";
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.userId],
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
        class="fixed inset-0 z-[220] flex items-center justify-center p-4 sm:p-6"
        aria-modal="true"
        role="dialog"
        aria-labelledby="user-edit-modal-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px] dark:bg-black/55"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="modal-panel" appear>
          <div
            class="relative z-10 flex max-h-[min(90dvh,860px)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2
                id="user-edit-modal-title"
                class="text-lg font-semibold text-gray-900 dark:text-white"
              >
                Edit User
              </h2>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Close"
                :disabled="saving"
                @click="close"
              >
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </header>

            <div
              class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-5 py-4 [scrollbar-gutter:stable]"
            >
              <p
                v-if="errorMsg"
                class="mb-4 text-sm text-red-600 dark:text-red-400"
              >
                {{ errorMsg }}
              </p>

              <form
                v-if="!loading && userId"
                id="user-edit-modal-form"
                class="space-y-5"
                @submit.prevent="onSubmit"
              >
                <UserFormFields
                  v-model:pending-avatar-file="pendingAvatarFile"
                  :form="form"
                  :roles="roles"
                  :is-edit="true"
                  :user-id="userId"
                  :avatar-url="profileAvatarUrl"
                  :upload-avatar="(f) => uploadAvatarFile(userId, f)"
                  :delete-avatar="() => deleteAvatarFile(userId)"
                  :saving="saving"
                  :first-error="firstError"
                  :clear-field-error="clearFieldError"
                  :toggle-role="toggleRole"
                />
              </form>
              <div v-else-if="loading" class="flex justify-center py-12">
                <CrmLoadingSpinner message="Loading User…" />
              </div>
            </div>

            <footer
              v-if="!loading && userId"
              class="flex shrink-0 flex-wrap gap-3 border-t border-gray-200 bg-gray-50/90 px-5 py-4 dark:border-gray-800 dark:bg-gray-900/90"
            >
              <button
                type="submit"
                form="user-edit-modal-form"
                :disabled="saving"
                class="inline-flex min-h-[2.75rem] flex-1 items-center justify-center rounded-xl bg-[#2563eb] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-[#2563eb]/40 disabled:opacity-50 sm:flex-none"
              >
                {{ saving ? "Saving…" : "Save Changes" }}
              </button>
              <button
                type="button"
                class="inline-flex min-h-[2.75rem] flex-1 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:flex-none"
                :disabled="saving"
                @click="close"
              >
                Cancel
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
.modal-backdrop-enter-active .absolute,
.modal-backdrop-leave-active .absolute {
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
