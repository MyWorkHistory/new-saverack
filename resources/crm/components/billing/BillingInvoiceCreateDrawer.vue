<script setup>
import { reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccounts: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "created", "refresh-meta"]);

const router = useRouter();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  invoice_number: "",
  client_account_id: "",
  due_at: "",
});

function localDateYmd() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function reset() {
  form.invoice_number = "";
  form.client_account_id = "";
  form.due_at = localDateYmd();
  errorMsg.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      reset();
      emit("refresh-meta");
    }
  },
);

function close() {
  if (saving.value) return;
  emit("update:open", false);
}

function onBackdropClick() {
  close();
}

async function submit() {
  if (!form.client_account_id) {
    errorMsg.value = "Select an account.";
    return;
  }
  errorMsg.value = "";
  saving.value = true;
  try {
    const payload = {
      client_account_id: Number(form.client_account_id),
      due_at: form.due_at || null,
      items: [],
    };
    const num = String(form.invoice_number || "").trim();
    if (num) {
      payload.invoice_number = num;
    }
    const { data } = await api.post("/invoices", payload);
    emit("created", data);
    emit("update:open", false);
    reset();
    if (data?.id) {
      router.push(`/billing/invoices/${data.id}`);
    }
  } catch (e) {
    const d = e?.response?.data;
    errorMsg.value =
      d?.message ||
      d?.errors?.invoice_number?.[0] ||
      d?.errors?.client_account_id?.[0] ||
      "Could not create invoice.";
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
        class="fixed inset-0 z-[1200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
        aria-modal="true"
        role="dialog"
        aria-labelledby="billing-inv-drawer-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-md"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2
                id="billing-inv-drawer-title"
                class="text-lg font-semibold text-gray-900 dark:text-white"
              >
                Add Invoice
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

              <p v-if="!clientAccounts.length" class="small text-secondary mb-0">
                No client accounts found. Add an account first.
              </p>
              <template v-else>
                <label class="form-label" for="inv-drawer-number">Invoice #</label>
                <input
                  id="inv-drawer-number"
                  v-model="form.invoice_number"
                  type="text"
                  class="form-control mb-3"
                  placeholder="Auto-assign if empty"
                  autocomplete="off"
                  :disabled="saving"
                />
                <p class="small text-secondary mb-3">
                  Leave blank to use the next number (e.g. INV-2026-00001). Add line items on the
                  invoice page.
                </p>

                <label class="form-label">Account</label>
                <CrmSearchableSelect
                  v-model="form.client_account_id"
                  appearance="staff"
                  aria-label="Client account"
                  :options="clientAccounts"
                  placeholder="Select account…"
                  search-placeholder="Search accounts…"
                  :allow-empty="false"
                  empty-label="Select account…"
                  button-id="inv-drawer-account"
                  :disabled="saving"
                />

                <label class="form-label mt-3" for="inv-drawer-due">Due date</label>
                <input
                  id="inv-drawer-due"
                  v-model="form.due_at"
                  type="date"
                  class="form-control"
                  :disabled="saving"
                />
              </template>
            </div>

            <footer
              class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="saving || !clientAccounts.length"
                @click="submit"
              >
                {{ saving ? "Saving…" : "Create Invoice" }}
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
  transition: transform 0.22s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
