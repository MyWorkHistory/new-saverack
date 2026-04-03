<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccountId: { type: String, required: true },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  name: "",
  website: "",
  marketplace: "",
});

function reset() {
  form.name = "";
  form.website = "";
  form.marketplace = "";
  errorMsg.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) reset();
  },
);

function close() {
  emit("update:open", false);
}

function onBackdropClick() {
  if (!saving.value) close();
}

async function onSubmit() {
  if (!props.clientAccountId) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.post(`/client-accounts/${props.clientAccountId}/stores`, {
      name: form.name.trim(),
      website: form.website.trim() || null,
      marketplace: form.marketplace.trim() || null,
    });
    toast.success("Store added.");
    emit("saved");
    close();
    reset();
  } catch (e) {
    const m =
      e.response?.data?.message ||
      (Array.isArray(e.response?.data?.errors)
        ? Object.values(e.response.data.errors).flat().join(" ")
        : null);
    errorMsg.value =
      typeof m === "string" && m ? m : "Could not add store.";
    toast.errorFrom(e, "Could not add store.");
  } finally {
    saving.value = false;
  }
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
                Add Store
              </h2>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10"
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

            <form
              class="flex min-h-0 flex-1 flex-col"
              @submit.prevent="onSubmit"
            >
              <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <p
                  v-if="errorMsg"
                  class="mb-4 text-sm text-red-600 dark:text-red-400"
                >
                  {{ errorMsg }}
                </p>
                <div class="space-y-4">
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                      >Store name</label
                    >
                    <input
                      v-model="form.name"
                      type="text"
                      required
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                      >Website</label
                    >
                    <input
                      v-model="form.website"
                      type="url"
                      placeholder="https://"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                      >Marketplace</label
                    >
                    <input
                      v-model="form.marketplace"
                      type="text"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                </div>
              </div>
              <footer :class="CRM_DIALOG_FOOTER_CLASS">
                <button
                  type="button"
                  :class="CRM_BTN_SECONDARY"
                  :disabled="saving"
                  @click="close"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  :class="CRM_BTN_PRIMARY"
                  :disabled="saving"
                >
                  {{ saving ? "Saving…" : "Save" }}
                </button>
              </footer>
            </form>
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
