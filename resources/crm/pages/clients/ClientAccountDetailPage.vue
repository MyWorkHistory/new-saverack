<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmOutlineEditButton from "../../components/common/CrmOutlineEditButton.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import ClientStoreCreateDrawer from "../../components/clients/ClientStoreCreateDrawer.vue";
import ClientStoreEditModal from "../../components/clients/ClientStoreEditModal.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { formatDateUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateAccount = computed(() => userHasPerm("clients.update"));
const canViewStores = computed(() => userHasPerm("stores.view"));
const canCreateStore = computed(() => userHasPerm("stores.create"));
const canUpdateStore = computed(() => userHasPerm("stores.update"));
const canDeleteStore = computed(() => userHasPerm("stores.delete"));

const loading = ref(true);
const errorMsg = ref("");
const account = ref(null);
const storesLoading = ref(false);
const stores = ref([]);

const editAccountOpen = ref(false);
const accountManagers = ref([]);

const addStoreOpen = ref(false);
const editStoreOpen = ref(false);
const editingStore = ref(null);

const storeDeleteTarget = ref(null);
const storeDeleteBusy = ref(false);

const storeSearch = ref("");
const storeStatusFilter = ref("all");

const storeDeleteOpen = computed(() => storeDeleteTarget.value !== null);
const storeDeleteMessage = computed(() => {
  const s = storeDeleteTarget.value;
  return s ? `Delete store “${s.name}”? This cannot be undone.` : "";
});

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

const avatarPalettes = [
  "bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-500/20 dark:text-sky-200 dark:ring-sky-500/30",
  "bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-500/20 dark:text-violet-200 dark:ring-violet-500/30",
  "bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-500/20 dark:text-amber-200 dark:ring-amber-500/30",
  "bg-emerald-100 text-emerald-900 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-200 dark:ring-emerald-500/30",
  "bg-rose-100 text-rose-900 ring-rose-200 dark:bg-rose-500/20 dark:text-rose-200 dark:ring-rose-500/30",
];

function avatarClassForEmail(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const heroBrandWebsiteSegments = computed(() => {
  const a = account.value;
  if (!a) return [];
  const brand = (a.brand_name && String(a.brand_name).trim()) || "";
  const web = (a.website && String(a.website).trim()) || "";
  if (!brand && !web) return [];
  return [
    { key: "brand", text: brand || "—", emphasis: true },
    { key: "web", text: web || "—", emphasis: false },
  ];
});

function accountStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "paused") {
    return "bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300";
}

function storeStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300";
}

const filteredStores = computed(() => {
  let list = stores.value;
  const st = storeStatusFilter.value;
  if (st && st !== "all") {
    list = list.filter((r) => String(r.status).toLowerCase() === st);
  }
  const q = storeSearch.value.trim().toLowerCase();
  if (!q) return list;
  return list.filter((r) => {
    const hay = `${r.name || ""} ${r.website || ""} ${r.marketplace || ""}`.toLowerCase();
    return hay.includes(q);
  });
});

function normalizeAccountManagersFromMeta(payload) {
  const raw =
    payload?.account_managers ??
    payload?.accountManagers ??
    (Array.isArray(payload) ? payload : null);
  if (!Array.isArray(raw)) return [];
  return raw.map((row) => ({
    id: Number(row.id),
    name: row.name != null ? String(row.name) : "",
    email: row.email != null ? String(row.email) : "",
  }));
}

async function fetchMeta() {
  try {
    const { data } = await api.get("/client-accounts/meta");
    accountManagers.value = normalizeAccountManagersFromMeta(data);
  } catch (e) {
    accountManagers.value = [];
    toast.errorFrom(e, "Could not load account manager list.");
  }
}

async function loadAccount() {
  loading.value = true;
  errorMsg.value = "";
  account.value = null;
  try {
    const { data } = await api.get(`/client-accounts/${props.id}`);
    account.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to this account.";
    } else if (st === 404) {
      errorMsg.value = "Account not found.";
    } else {
      errorMsg.value = "Could not load account.";
    }
  } finally {
    loading.value = false;
  }
}

async function loadStores() {
  if (!canViewStores.value || !props.id) return;
  storesLoading.value = true;
  try {
    const { data } = await api.get(`/client-accounts/${props.id}/stores`);
    stores.value = Array.isArray(data) ? data : [];
  } catch (e) {
    stores.value = [];
    if (e.response?.status !== 403) {
      toast.errorFrom(e, "Could not load stores.");
    }
  } finally {
    storesLoading.value = false;
  }
}

function openEditStore(row) {
  editingStore.value = { ...row };
  editStoreOpen.value = true;
}

function closeStoreDelete() {
  if (storeDeleteBusy.value) return;
  storeDeleteTarget.value = null;
}

async function confirmStoreDelete() {
  const row = storeDeleteTarget.value;
  if (!row) return;
  storeDeleteBusy.value = true;
  try {
    await api.delete(`/client-stores/${row.id}`);
    storeDeleteTarget.value = null;
    toast.success("Store deleted.");
    await loadStores();
  } catch (e) {
    toast.errorFrom(e, "Could not delete store.");
  } finally {
    storeDeleteBusy.value = false;
  }
}

watch(
  () => account.value?.company_name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | ${name}`,
        description: `Client account ${name}.`,
      });
    }
  },
);

watch(editAccountOpen, (o) => {
  if (o) fetchMeta();
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Account",
    description: "Client account profile.",
  });
  await loadAccount();
  await loadStores();
});
</script>

<template>
  <div class="w-full">
    <nav class="mb-4 flex flex-wrap items-center gap-1.5 text-sm">
      <RouterLink
        to="/dashboard"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Home
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        to="/clients/accounts"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Clients
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <RouterLink
        to="/clients/accounts"
        class="font-medium text-gray-500 transition hover:text-[#2563eb] dark:text-gray-400 dark:hover:text-blue-400"
      >
        Accounts
      </RouterLink>
      <span class="text-gray-400 dark:text-gray-600" aria-hidden="true">/</span>
      <span class="font-medium text-gray-800 dark:text-gray-200">
        {{ account?.company_name || "Account" }}
      </span>
    </nav>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        Account
      </h1>
    </div>

    <ClientAccountEditModal
      v-if="canUpdateAccount"
      v-model:open="editAccountOpen"
      :account-id="id"
      :account-managers="accountManagers"
      @saved="loadAccount"
    />
    <ClientStoreCreateDrawer
      v-if="canCreateStore && canViewStores"
      v-model:open="addStoreOpen"
      :client-account-id="id"
      @saved="loadStores"
    />
    <ClientStoreEditModal
      v-if="canUpdateStore"
      v-model:open="editStoreOpen"
      :store="editingStore"
      @saved="loadStores"
    />
    <ConfirmModal
      :open="storeDeleteOpen"
      title="Delete store"
      :message="storeDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="storeDeleteBusy"
      @close="closeStoreDelete"
      @confirm="confirmStoreDelete"
    />

    <div v-if="loading" class="flex justify-center py-20">
      <CrmLoadingSpinner message="Loading account…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-sm text-red-600 dark:text-red-400">
        {{ errorMsg }}
      </p>
      <RouterLink
        to="/clients/accounts"
        class="mt-2 inline-block text-sm font-medium text-[#2563eb] hover:underline dark:text-blue-400"
      >
        Back to accounts
      </RouterLink>
    </template>

    <div v-else-if="account" class="space-y-6">
      <!-- Hero -->
      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <div
          class="flex flex-col gap-6 border-b border-gray-100 p-6 dark:border-gray-800 sm:flex-row sm:items-start sm:justify-between"
        >
          <div class="flex min-w-0 flex-1 items-start gap-5">
            <span
              class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full text-xl font-bold ring-2 ring-white dark:ring-gray-900"
              :class="avatarClassForEmail(account.email)"
            >
              {{ initials(account.company_name) }}
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="truncate text-xl font-semibold text-gray-900 dark:text-white">
                {{ account.company_name }}
              </h2>
              <p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">
                {{ display(account.contact_full_name) }}
              </p>
              <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm sm:gap-x-3">
                <template v-if="heroBrandWebsiteSegments.length">
                  <template
                    v-for="(seg, idx) in heroBrandWebsiteSegments"
                    :key="seg.key"
                  >
                    <span
                      v-if="idx > 0"
                      class="text-gray-300 dark:text-gray-600"
                      aria-hidden="true"
                      >|</span
                    >
                    <span
                      :class="
                        seg.emphasis
                          ? 'font-medium text-gray-700 dark:text-gray-300'
                          : 'text-gray-500 dark:text-gray-400'
                      "
                      >{{ seg.text }}</span
                    >
                  </template>
                </template>
                <span v-else class="text-gray-500 dark:text-gray-400">—</span>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                  :class="accountStatusBadgeClass(account.status)"
                >
                  {{ account.status }}
                </span>
              </div>
            </div>
          </div>
          <div
            v-if="canUpdateAccount"
            class="flex w-full shrink-0 flex-col self-end sm:w-auto"
          >
            <CrmOutlineEditButton @click="editAccountOpen = true" />
          </div>
        </div>
      </div>

      <!-- Account info -->
      <section
        class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <h3 class="mb-5 border-b border-gray-100 pb-3 text-lg font-semibold text-gray-900 dark:border-gray-800 dark:text-white">
          Account Info
        </h3>
        <div
          class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800"
        >
          <div class="space-y-4 lg:pr-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Company Name
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.company_name) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Full Name
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.contact_full_name) }}
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4 lg:px-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Phone
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.phone) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Email
                </dt>
                <dd class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.email) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Channels
                </dt>
                <dd class="mt-1">
                  <ClientAccountChannelIcons
                    :notify-email="!!account.notify_email"
                    :telegram-handle="account.telegram_handle || ''"
                    :whatsapp-e164="account.whatsapp_e164 || ''"
                  />
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4 lg:pl-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Create Date
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ formatDateUs(account.created_at) }}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </section>

      <!-- Address -->
      <section
        class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <h3 class="mb-5 border-b border-gray-100 pb-3 text-lg font-semibold text-gray-900 dark:border-gray-800 dark:text-white">
          Address
        </h3>
        <div
          class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800"
        >
          <div class="space-y-4 lg:pr-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Street
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.street) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  City
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.city) }}
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4 lg:px-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  State
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.state) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  ZIP
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.zip) }}
                </dd>
              </div>
            </dl>
          </div>
          <div class="space-y-4 lg:pl-6">
            <dl class="space-y-4">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Country
                </dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  {{ display(account.country) }}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </section>

      <!-- Stores -->
      <section
        v-if="canViewStores"
        class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <div
          class="flex flex-col gap-3 border-b border-gray-100 px-6 py-5 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between"
        >
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            Stores
          </h3>
          <button
            v-if="canCreateStore"
            type="button"
            class="inline-flex h-10 shrink-0 items-center justify-center rounded-lg bg-[#2563eb] px-4 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
            @click="addStoreOpen = true"
          >
            Add Store
          </button>
        </div>

        <div
          class="flex flex-col gap-3 border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
          <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
            Store list
          </p>
          <div
            class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:justify-end"
          >
            <div class="relative w-full min-w-0 sm:max-w-xs">
              <span
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
              >
                <svg
                  class="h-5 w-5"
                  fill="none"
                  viewBox="0 0 20 20"
                  stroke="currentColor"
                  stroke-width="1.5"
                >
                  <path
                    stroke-linecap="round"
                    d="M3.042 9.374c0-3.497 2.835-6.332 6.333-6.332 3.497 0 6.332 2.835 6.332 6.332 0 3.498-2.835 6.333-6.332 6.333-3.498 0-6.333-2.835-6.333-6.333zM17.208 17.205l-2.82-2.82"
                  />
                </svg>
              </span>
              <input
                v-model="storeSearch"
                type="search"
                placeholder="Search…"
                class="h-11 w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
              />
            </div>
            <div class="flex items-center gap-2">
              <svg
                class="h-5 w-5 shrink-0 text-gray-400"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
                />
              </svg>
              <select
                v-model="storeStatusFilter"
                class="h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
              >
                <option value="all">All statuses</option>
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>

        <div class="overflow-x-auto px-2 pb-4 sm:px-4">
          <div v-if="storesLoading" class="flex justify-center py-12">
            <CrmLoadingSpinner message="Loading stores…" />
          </div>
          <table
            v-else
            class="min-w-[900px] w-full text-left text-sm"
          >
            <thead>
              <tr
                class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50"
              >
                <th class="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                  Status
                </th>
                <th class="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                  Store Name
                </th>
                <th class="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                  Website
                </th>
                <th class="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                  Marketplace
                </th>
                <th
                  v-if="canUpdateStore || canDeleteStore"
                  class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400"
                >
                  Action
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-if="!filteredStores.length">
                <td
                  :colspan="canUpdateStore || canDeleteStore ? 5 : 4"
                  class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                >
                  No stores yet.
                </td>
              </tr>
              <tr
                v-for="row in filteredStores"
                :key="row.id"
                class="bg-white dark:bg-transparent"
              >
                <td class="px-4 py-3 align-middle">
                  <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                    :class="storeStatusBadgeClass(row.status)"
                  >
                    {{ row.status }}
                  </span>
                </td>
                <td class="px-4 py-3 align-middle">
                  <div class="flex items-center gap-3">
                    <span
                      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                      :class="avatarClassForEmail(row.name)"
                    >
                      {{ initials(row.name) }}
                    </span>
                    <div class="min-w-0">
                      <p class="font-semibold text-gray-900 dark:text-white">
                        {{ row.name }}
                      </p>
                      <p
                        v-if="row.website"
                        class="truncate text-xs text-gray-500 dark:text-gray-400"
                      >
                        {{ row.website }}
                      </p>
                    </div>
                  </div>
                </td>
                <td
                  class="max-w-[12rem] truncate px-4 py-3 align-middle text-gray-700 dark:text-gray-300"
                >
                  {{ display(row.website) }}
                </td>
                <td class="px-4 py-3 align-middle text-gray-700 dark:text-gray-300">
                  {{ display(row.marketplace) }}
                </td>
                <td
                  v-if="canUpdateStore || canDeleteStore"
                  class="px-4 py-3 text-right align-middle"
                >
                  <div class="inline-flex items-center justify-end gap-1">
                    <button
                      v-if="canUpdateStore"
                      type="button"
                      class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10"
                      title="Edit"
                      aria-label="Edit store"
                      @click="openEditStore(row)"
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
                          d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                        />
                      </svg>
                    </button>
                    <button
                      v-if="canDeleteStore"
                      type="button"
                      class="rounded-lg p-2 text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                      title="Delete"
                      aria-label="Delete store"
                      @click="storeDeleteTarget = row"
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
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                        />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>
