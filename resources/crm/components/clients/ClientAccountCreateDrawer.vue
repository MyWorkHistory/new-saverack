<script setup>
import { computed, reactive, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountManagers: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");
const showPortalPassword = ref(false);

const form = reactive({
  company_name: "",
  brand_name: "",
  website: "",
  full_name: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
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

const portalFieldsRequired = computed(
  () => (form.password || "").trim().length > 0,
);

function reset() {
  form.company_name = "";
  form.brand_name = "";
  form.website = "";
  form.full_name = "";
  form.email = "";
  form.phone = "";
  form.password = "";
  form.password_confirmation = "";
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
  showPortalPassword.value = false;
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
      full_name: form.full_name.trim() || null,
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
    const rawPw = (form.password || "").trim();
    if (rawPw !== "") {
      payload.password = rawPw;
      payload.password_confirmation = (form.password_confirmation || "").trim();
    }
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
                  <div>
                    <label
                      class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                      >Full name</label
                    >
                    <input
                      v-model="form.full_name"
                      type="text"
                      autocomplete="name"
                      :required="portalFieldsRequired"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      Required if you set a portal password below.
                    </p>
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
                      autocomplete="tel"
                      class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>

                  <div
                    class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                  >
                    <p
                      class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-400"
                    >
                      Portal login (optional)
                    </p>
                    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                      Creates a pending client user with this email so they can
                      sign in to the portal.
                    </p>
                    <div class="space-y-3">
                      <div>
                        <label
                          class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                          >Password</label
                        >
                        <div class="relative">
                          <input
                            v-model="form.password"
                            :type="showPortalPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            minlength="8"
                            class="h-10 w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-10 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                          />
                          <button
                            type="button"
                            class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10"
                            :aria-pressed="showPortalPassword"
                            aria-label="Toggle password visibility"
                            @click="showPortalPassword = !showPortalPassword"
                          >
                            <svg
                              v-if="!showPortalPassword"
                              class="h-5 w-5"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                            >
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                              />
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                              />
                            </svg>
                            <svg
                              v-else
                              class="h-5 w-5"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                            >
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                              />
                            </svg>
                          </button>
                        </div>
                      </div>
                      <div>
                        <label
                          class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400"
                          >Confirm password</label
                        >
                        <div class="relative">
                          <input
                            v-model="form.password_confirmation"
                            :type="showPortalPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            minlength="8"
                            class="h-10 w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-10 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                          />
                          <button
                            type="button"
                            class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10"
                            :aria-pressed="showPortalPassword"
                            aria-label="Toggle confirm password visibility"
                            @click="showPortalPassword = !showPortalPassword"
                          >
                            <svg
                              v-if="!showPortalPassword"
                              class="h-5 w-5"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                            >
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                              />
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                              />
                            </svg>
                            <svg
                              v-else
                              class="h-5 w-5"
                              fill="none"
                              stroke="currentColor"
                              viewBox="0 0 24 24"
                            >
                              <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                              />
                            </svg>
                          </button>
                        </div>
                      </div>
                    </div>
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
                  <CrmSearchableSelect
                    v-model="form.account_manager_id"
                    label="Account manager"
                    :options="accountManagers"
                    placeholder="Choose account manager"
                    search-placeholder="Search staff…"
                    empty-label="— None —"
                  />
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
