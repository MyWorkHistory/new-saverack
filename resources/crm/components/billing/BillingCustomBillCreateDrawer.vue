<script setup>
import { reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  clientAccounts: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "created"]);

const router = useRouter();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  client_account_id: "",
  bill_date: "",
});

function localDateYmd() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function reset() {
  form.client_account_id = "";
  form.bill_date = localDateYmd();
  errorMsg.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) reset();
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
  if (!form.bill_date) {
    errorMsg.value = "Bill date is required.";
    return;
  }
  errorMsg.value = "";
  saving.value = true;
  try {
    const payload = {
      client_account_id: Number(form.client_account_id),
      bill_date: form.bill_date,
    };
    const { data } = await api.post("/custom-bills", payload);
    emit("created", data);
    emit("update:open", false);
    reset();
    if (data?.id) {
      router.push(`/admin/billing/bills/${data.id}`);
    }
  } catch (e) {
    const d = e?.response?.data;
    errorMsg.value =
      d?.message ||
      d?.errors?.client_account_id?.[0] ||
      d?.errors?.bill_date?.[0] ||
      "Could not create bill.";
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
        aria-labelledby="billing-cb-drawer-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-lg"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2
                id="billing-cb-drawer-title"
                class="text-lg font-semibold text-gray-900 dark:text-white"
              >
                Create Bill
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
                  button-id="cb-drawer-account"
                  :disabled="saving"
                />

                <label class="form-label mt-3" for="cb-drawer-date">Bill Date</label>
                <input
                  id="cb-drawer-date"
                  v-model="form.bill_date"
                  type="date"
                  class="form-control"
                  :disabled="saving"
                />
                <p class="small text-secondary mt-3 mb-0">
                  Creates a draft bill. Add line items on the bill page, then mark it Open when ready.
                </p>
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
                {{ saving ? "Saving…" : "Create Bill" }}
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
