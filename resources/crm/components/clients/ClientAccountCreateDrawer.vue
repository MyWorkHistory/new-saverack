<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountManagers: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  company_name: "",
  brand_name: "",
  website: "",
  contact_first_name: "",
  contact_last_name: "",
  email: "",
  phone: "",
  notify_email: true,
  telegram_handle: "",
  whatsapp_e164: "",
  street: "",
  city: "",
  state: "",
  zip: "",
  country: "",
  account_manager_id: "",
});

function reset() {
  form.company_name = "";
  form.brand_name = "";
  form.website = "";
  form.contact_first_name = "";
  form.contact_last_name = "";
  form.email = "";
  form.phone = "";
  form.notify_email = true;
  form.telegram_handle = "";
  form.whatsapp_e164 = "";
  form.street = "";
  form.city = "";
  form.state = "";
  form.zip = "";
  form.country = "";
  form.account_manager_id = "";
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
  saving.value = true;
  errorMsg.value = "";
  try {
    const payload = {
      company_name: form.company_name.trim(),
      brand_name: form.brand_name.trim() || null,
      website: form.website.trim() || null,
      contact_first_name: form.contact_first_name.trim() || null,
      contact_last_name: form.contact_last_name.trim() || null,
      email: form.email.trim(),
      phone: form.phone.trim() || null,
      notify_email: !!form.notify_email,
      telegram_handle: form.telegram_handle.trim() || null,
      whatsapp_e164: form.whatsapp_e164.trim() || null,
      street: form.street.trim() || null,
      city: form.city.trim() || null,
      state: form.state.trim() || null,
      zip: form.zip.trim() || null,
      country: form.country.trim() || null,
      account_manager_id: form.account_manager_id
        ? Number(form.account_manager_id)
        : null,
    };
    await api.post("/client-accounts", payload);
    toast.success("Account created.");
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
      typeof m === "string" && m ? m : "Could not create account.";
    toast.errorFrom(e, "Could not create account.");
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
                Add Account
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
              id="client-account-create-form"
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
                      >Phone</label
                    >
                    <input
                      v-model="form.phone"
                      type="text"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                        >Brand name</label
                      >
                      <input
                        v-model="form.brand_name"
                        type="text"
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
                  </div>
                  <p class="text-xs font-medium text-gray-600 dark:text-gray-400">
                    Address
                  </p>
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                      >Street</label
                    >
                    <input
                      v-model="form.street"
                      type="text"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                        >City</label
                      >
                      <input
                        v-model="form.city"
                        type="text"
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                        >State</label
                      >
                      <input
                        v-model="form.state"
                        type="text"
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                        >ZIP</label
                      >
                      <input
                        v-model="form.zip"
                        type="text"
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                        >Country</label
                      >
                      <input
                        v-model="form.country"
                        type="text"
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
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
                      <label
                        class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
                        >Telegram</label
                      >
                      <input
                        v-model="form.telegram_handle"
                        type="text"
                        placeholder="@handle"
                        class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                    <div class="mt-2">
                      <label
                        class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
                        >WhatsApp</label
                      >
                      <input
                        v-model="form.whatsapp_e164"
                        type="text"
                        placeholder="E.164"
                        class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </div>
                  </div>
                </div>
              </div>
              <footer
                class="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50/80 px-5 py-4 dark:border-gray-800 dark:bg-gray-900/80"
              >
                <button
                  type="submit"
                  :disabled="saving"
                  class="inline-flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl bg-[#2563eb] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 disabled:opacity-50"
                >
                  {{ saving ? "Saving…" : "Save" }}
                </button>
                <button
                  type="button"
                  class="inline-flex min-h-[2.75rem] min-w-0 flex-1 basis-0 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                  :disabled="saving"
                  @click="close"
                >
                  Cancel
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
