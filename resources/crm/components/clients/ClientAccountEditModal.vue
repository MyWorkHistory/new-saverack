<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: String, default: "" },
  accountManagers: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  company_name: "",
  contact_first_name: "",
  contact_last_name: "",
  email: "",
  notify_email: true,
  telegram_handle: "",
  whatsapp_e164: "",
  account_manager_id: "",
});

function close() {
  emit("update:open", false);
}

function onBackdrop() {
  if (!saving.value) close();
}

async function load() {
  if (!props.accountId) return;
  loading.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.get(`/client-accounts/${props.accountId}`);
    form.company_name = data.company_name || "";
    form.contact_first_name = data.contact_first_name || "";
    form.contact_last_name = data.contact_last_name || "";
    form.email = data.email || "";
    form.notify_email = !!data.notify_email;
    form.telegram_handle = data.telegram_handle || "";
    form.whatsapp_e164 = data.whatsapp_e164 || "";
    form.account_manager_id = data.account_manager_id
      ? String(data.account_manager_id)
      : "";
  } catch (e) {
    errorMsg.value = "Could not load account.";
    toast.errorFrom(e, "Could not load account.");
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (o) => {
    if (o && props.accountId) load();
  },
);

async function onSubmit() {
  if (!props.accountId) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.patch(`/client-accounts/${props.accountId}`, {
      company_name: form.company_name.trim(),
      contact_first_name: form.contact_first_name.trim() || null,
      contact_last_name: form.contact_last_name.trim() || null,
      email: form.email.trim(),
      notify_email: !!form.notify_email,
      telegram_handle: form.telegram_handle.trim() || null,
      whatsapp_e164: form.whatsapp_e164.trim() || null,
      account_manager_id: form.account_manager_id
        ? Number(form.account_manager_id)
        : null,
    });
    toast.success("Account updated.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = "Could not save.";
    toast.errorFrom(e, "Could not save account.");
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
        class="fixed inset-0 z-[240] flex items-center justify-center p-4 sm:p-6"
        aria-modal="true"
        role="dialog"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px] dark:bg-black/55"
          aria-hidden="true"
          @click="onBackdrop"
        />
        <Transition name="modal-panel" appear>
          <div
            class="relative z-10 max-h-[min(90dvh,640px)] w-full max-w-lg overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"
          >
            <header
              class="sticky top-0 z-10 border-b border-gray-100 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900"
            >
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Edit Account
              </h2>
            </header>

            <div v-if="loading" class="flex justify-center py-12">
              <CrmLoadingSpinner message="Loading…" />
            </div>
            <form
              v-else
              class="space-y-4 px-5 py-4"
              @submit.prevent="onSubmit"
            >
              <p
                v-if="errorMsg"
                class="text-sm text-red-600 dark:text-red-400"
              >
                {{ errorMsg }}
              </p>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Company name</label
                >
                <input
                  v-model="form.company_name"
                  type="text"
                  required
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                    >Contact first name</label
                  >
                  <input
                    v-model="form.contact_first_name"
                    type="text"
                    class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                    >Contact last name</label
                  >
                  <input
                    v-model="form.contact_last_name"
                    type="text"
                    class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
              </div>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Email</label
                >
                <input
                  v-model="form.email"
                  type="email"
                  required
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
              </div>
              <div>
                <label
                  class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                  >Account manager</label
                >
                <select
                  v-model="form.account_manager_id"
                  class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                >
                  <option value="">— None —</option>
                  <option
                    v-for="m in accountManagers"
                    :key="m.id"
                    :value="String(m.id)"
                  >
                    {{ m.name }}
                  </option>
                </select>
              </div>
              <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-400">
                  Channels
                </p>
                <label class="flex items-center gap-2 py-1">
                  <input
                    v-model="form.notify_email"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-[#2563eb]"
                  />
                  <span class="text-sm text-gray-800 dark:text-gray-200"
                    >Email</span
                  >
                </label>
                <div class="mt-2">
                  <label class="mb-1 block text-xs text-gray-500">Telegram</label>
                  <input
                    v-model="form.telegram_handle"
                    type="text"
                    class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
                <div class="mt-2">
                  <label class="mb-1 block text-xs text-gray-500">WhatsApp</label>
                  <input
                    v-model="form.whatsapp_e164"
                    type="text"
                    class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                  />
                </div>
              </div>
              <div class="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                  :disabled="saving"
                  @click="close"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  class="rounded-lg bg-[#2563eb] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  :disabled="saving"
                >
                  {{ saving ? "Saving…" : "Save" }}
                </button>
              </div>
            </form>
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
  transform: scale(0.98);
}
</style>
