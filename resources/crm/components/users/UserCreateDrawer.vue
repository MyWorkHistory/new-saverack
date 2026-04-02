<script setup>
import { watch } from "vue";
import UserFormFields from "./UserFormFields.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useUserForm } from "../../composables/useUserForm";

const props = defineProps({
  open: { type: Boolean, default: false },
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
  resetForCreate,
  submit,
  toggleRole,
  clearFieldError,
  firstError,
} = useUserForm();

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen) return;
    loading.value = true;
    errorMsg.value = "";
    resetForCreate();
    try {
      await loadRoles();
    } finally {
      loading.value = false;
    }
  },
);

function close() {
  emit("update:open", false);
}

async function onSubmit() {
  const ok = await submit({ isEdit: false, userId: null });
  if (ok) {
    emit("saved");
    close();
    resetForCreate();
  }
}

function onBackdropClick() {
  if (!saving.value) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="drawer-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-[200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
        aria-modal="true"
        role="dialog"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-2xl"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Add user
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

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              <p
                v-if="errorMsg"
                class="mb-4 text-sm text-red-600 dark:text-red-400"
              >
                {{ errorMsg }}
              </p>

              <form
                v-if="!loading"
                id="user-create-drawer-form"
                class="space-y-5"
                @submit.prevent="onSubmit"
              >
                <UserFormFields
                  v-model:pending-avatar-file="pendingAvatarFile"
                  :form="form"
                  :roles="roles"
                  :is-edit="false"
                  :avatar-url="profileAvatarUrl"
                  :saving="saving"
                  :first-error="firstError"
                  :clear-field-error="clearFieldError"
                  :toggle-role="toggleRole"
                />
              </form>
              <div v-else class="flex justify-center py-8">
                <CrmLoadingSpinner message="Loading..." />
              </div>
            </div>

            <footer
              v-if="!loading"
              class="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50/80 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom,0px))] dark:border-gray-800 dark:bg-gray-900/80"
            >
              <button
                type="submit"
                form="user-create-drawer-form"
                :disabled="saving"
                class="inline-flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl bg-[#0ea5e9] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-[#0ea5e9]/40 disabled:opacity-50"
              >
                {{ saving ? "Saving…" : "Save" }}
              </button>
              <button
                type="button"
                class="inline-flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
            </footer>
          </aside>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}
.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.25s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
