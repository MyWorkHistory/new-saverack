<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import ClientStoreCreateDrawer from "../../components/clients/ClientStoreCreateDrawer.vue";
import ClientStoreEditModal from "../../components/clients/ClientStoreEditModal.vue";
import ClientStoresBulkEditModal from "../../components/clients/ClientStoresBulkEditModal.vue";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { crmIsAdmin } from "../../utils/crmUser";
import { formatDateUs, formatDateTimeUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateAccount = computed(() => userHasPerm("clients.update"));
const canDeleteAccount = computed(() => userHasPerm("clients.delete"));
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

const storeListQuery = reactive({
  page: 1,
  per_page: DEFAULT_PER_PAGE,
});
const selectedStoreIds = ref([]);
const storeBulkEditOpen = ref(false);
const storeBulkEditBusy = ref(false);

const activeTab = ref("overview");

const accountTabs = computed(() => {
  const rows = [{ id: "overview", label: "Overview" }];
  if (canViewStores.value) {
    rows.push({ id: "stores", label: "Stores" });
  }
  return rows;
});

watch(
  accountTabs,
  (tabs) => {
    if (!tabs.some((t) => t.id === activeTab.value)) {
      activeTab.value = "overview";
    }
  },
  { immediate: true },
);

const accountDeleteOpen = ref(false);
const accountDeleteBusy = ref(false);

const storeDeleteOpen = computed(() => storeDeleteTarget.value !== null);
const storeDeleteMessage = computed(() => {
  const s = storeDeleteTarget.value;
  return s ? `Delete store “${s.name}”? This cannot be undone.` : "";
});

const accountDeleteMessage = computed(() => {
  const a = account.value;
  return a
    ? `Delete ${a.company_name}? This cannot be undone.`
    : "";
});

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

const avatarPalettes = [
  "bg-info-subtle text-info-emphasis",
  "bg-primary-subtle text-primary-emphasis",
  "bg-warning-subtle text-warning-emphasis",
  "bg-success-subtle text-success-emphasis",
  "bg-danger-subtle text-danger-emphasis",
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

function accountStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "badge bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "badge bg-warning-subtle text-warning-emphasis";
  }
  if (s === "paused") {
    return "badge bg-info-subtle text-info-emphasis";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-body-secondary text-body-secondary";
}

function storeStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "badge bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "badge bg-warning-subtle text-warning-emphasis";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-body-secondary text-body-secondary";
}

const storesTotal = computed(() => stores.value.length);
const storesActiveCount = computed(() =>
  stores.value.filter((r) => String(r.status).toLowerCase() === "active")
    .length,
);

const channelsCount = computed(() => {
  const a = account.value;
  if (!a) return 0;
  let n = 0;
  if (a.notify_email) n += 1;
  if (a.telegram_handle && String(a.telegram_handle).trim()) n += 1;
  if (a.whatsapp_e164 && String(a.whatsapp_e164).trim()) n += 1;
  return n;
});

const showStoreCheckboxCol = computed(() => canUpdateStore.value);

const storeTableColspan = computed(() => {
  let n = 4;
  if (canUpdateStore.value || canDeleteStore.value) {
    n += 1;
  }
  if (showStoreCheckboxCol.value) {
    n += 1;
  }
  return n;
});

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

const storeListTotal = computed(() => filteredStores.value.length);

const storeListLastPage = computed(() => {
  const t = storeListTotal.value;
  const pp = storeListQuery.per_page;
  if (t === 0) return 1;
  return Math.max(1, Math.ceil(t / pp));
});

const paginatedStores = computed(() => {
  const list = filteredStores.value;
  const pp = storeListQuery.per_page;
  const p = storeListQuery.page;
  const start = (p - 1) * pp;
  return list.slice(start, start + pp);
});

const showingStoresFrom = computed(() => {
  const t = storeListTotal.value;
  if (t === 0) return 0;
  return (storeListQuery.page - 1) * storeListQuery.per_page + 1;
});

const showingStoresTo = computed(() => {
  const t = storeListTotal.value;
  if (t === 0) return 0;
  return Math.min(storeListQuery.page * storeListQuery.per_page, t);
});

const storePageItems = computed(() => {
  const last = storeListLastPage.value;
  const cur = storeListQuery.page;
  if (last < 1) return [];
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => ({
      type: "page",
      value: i + 1,
    }));
  }
  const nums = new Set([1, last, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
  const sorted = [...nums].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) out.push({ type: "gap" });
    out.push({ type: "page", value: p });
    prev = p;
  }
  return out;
});

const isAllStoresPageSelected = computed(
  () =>
    paginatedStores.value.length > 0 &&
    paginatedStores.value.every((r) => selectedStoreIds.value.includes(r.id)),
);

watch(storeListLastPage, (last) => {
  if (storeListQuery.page > last) {
    storeListQuery.page = last;
  }
});

watch([storeSearch, storeStatusFilter], () => {
  storeListQuery.page = 1;
  selectedStoreIds.value = [];
});

function toggleSelectAllStores() {
  const pageIds = paginatedStores.value.map((r) => r.id);
  if (!pageIds.length) return;
  const allSelected = pageIds.every((id) => selectedStoreIds.value.includes(id));
  if (allSelected) {
    selectedStoreIds.value = selectedStoreIds.value.filter(
      (id) => !pageIds.includes(id),
    );
  } else {
    const set = new Set(selectedStoreIds.value);
    pageIds.forEach((id) => set.add(id));
    selectedStoreIds.value = [...set];
  }
}

function toggleStoreRowSelect(id) {
  const i = selectedStoreIds.value.indexOf(id);
  if (i >= 0) {
    selectedStoreIds.value = selectedStoreIds.value.filter((x) => x !== id);
  } else {
    selectedStoreIds.value = [...selectedStoreIds.value, id];
  }
}

function storeGoPage(p) {
  const last = storeListLastPage.value;
  if (p < 1 || p > last) return;
  storeListQuery.page = p;
}

function storeFirstPage() {
  storeListQuery.page = 1;
}

function storeLastPageFn() {
  storeListQuery.page = storeListLastPage.value;
}

function onStorePerPageChange(e) {
  storeListQuery.per_page = Number(e.target.value);
  storeListQuery.page = 1;
  selectedStoreIds.value = [];
}

function openStoreBulkEdit() {
  if (!selectedStoreIds.value.length) return;
  storeBulkEditOpen.value = true;
}

async function applyStoreBulkEdit(payload) {
  storeBulkEditBusy.value = true;
  try {
    const body = {
      client_store_ids: selectedStoreIds.value,
      apply_status: !!payload.apply_status,
      apply_marketplace: !!payload.apply_marketplace,
    };
    if (payload.apply_status) {
      body.status = payload.status;
    }
    if (payload.apply_marketplace) {
      body.marketplace = payload.marketplace ?? null;
    }
    await api.patch("/client-stores/bulk", body);
    toast.success("Stores updated.");
    storeBulkEditOpen.value = false;
    selectedStoreIds.value = [];
    await loadStores();
  } catch (e) {
    toast.errorFrom(e, "Could not update stores.");
  } finally {
    storeBulkEditBusy.value = false;
  }
}

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

function closeAccountDelete() {
  if (accountDeleteBusy.value) return;
  accountDeleteOpen.value = false;
}

async function confirmAccountDelete() {
  if (!account.value) return;
  accountDeleteBusy.value = true;
  try {
    await api.delete(`/client-accounts/${props.id}`);
    accountDeleteOpen.value = false;
    toast.success("Account deleted.");
    await router.push("/clients/accounts");
  } catch (e) {
    toast.errorFrom(e, "Could not delete account.");
  } finally {
    accountDeleteBusy.value = false;
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
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/dashboard">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/clients/accounts">Accounts</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{
        account?.company_name || "Account"
      }}</span>
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-start justify-content-between gap-2"
    >
      <div class="min-w-0">
        <h1 class="staff-user-view__title">
          {{ account?.company_name || "Account" }}
        </h1>
        <p
          v-if="account?.created_at"
          class="text-secondary small mb-0"
        >
          Added {{ formatDateTimeUs(account.created_at) }}
        </p>
      </div>
      <button
        v-if="canDeleteAccount && account && !loading && !errorMsg"
        type="button"
        class="btn btn-outline-danger btn-sm flex-shrink-0"
        @click="accountDeleteOpen = true"
      >
        Delete account
      </button>
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
    <ClientStoresBulkEditModal
      v-if="canUpdateStore && canViewStores"
      v-model:open="storeBulkEditOpen"
      :selected-count="selectedStoreIds.length"
      :busy="storeBulkEditBusy"
      @apply="applyStoreBulkEdit"
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
    <ConfirmModal
      :open="accountDeleteOpen"
      title="Delete account"
      subtitle="This action is permanent and may remove related CRM data."
      :message="accountDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="accountDeleteBusy"
      @close="closeAccountDelete"
      @confirm="confirmAccountDelete"
    />

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading account…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">
        {{ errorMsg }}
      </p>
      <RouterLink to="/clients/accounts" class="small"
        >Back to accounts</RouterLink
      >
    </template>

    <template v-else-if="account">
      <div class="row g-3">
        <div class="col-12 col-xl-4">
          <aside class="staff-user-profile">
            <div class="staff-user-profile__avatar-wrap">
              <span
                class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                :class="avatarClassForEmail(account.email)"
              >
                {{ initials(account.company_name) }}
              </span>
            </div>
            <h2 class="staff-user-profile__name">
              {{ account.company_name }}
            </h2>
            <p class="text-body-secondary small text-center mb-3">
              Account ID #{{ account.id }}
            </p>
            <div class="staff-user-profile__stats">
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ nf.format(storesTotal) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Stores</div>
              </div>
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val text-truncate px-1">
                  {{ formatDateUs(account.created_at) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Created</div>
              </div>
            </div>

            <h3 class="staff-user-profile__details-title">Details</h3>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd text-break">
                  {{ display(account.email) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Phone</dt>
                <dd class="staff-user-profile__dd">
                  {{ display(account.phone) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Status</dt>
                <dd class="staff-user-profile__dd text-capitalize">
                  <span :class="accountStatusBadgeClass(account.status)">{{
                    account.status
                  }}</span>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Channels</dt>
                <dd class="staff-user-profile__dd">
                  <ClientAccountChannelIcons
                    :notify-email="!!account.notify_email"
                    :telegram-handle="account.telegram_handle || ''"
                    :whatsapp-e164="account.whatsapp_e164 || ''"
                  />
                </dd>
              </div>
              <div v-if="account.account_manager?.name">
                <dt class="staff-user-profile__dt">Account manager</dt>
                <dd class="staff-user-profile__dd">
                  {{ account.account_manager.name }}
                </dd>
              </div>
            </dl>
            <div
              v-if="canUpdateAccount"
              class="staff-user-profile__actions staff-user-profile__actions--single"
            >
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                @click="editAccountOpen = true"
              >
                Edit details
              </button>
            </div>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
          <div class="staff-user-tabs" role="tablist">
            <button
              v-for="t in accountTabs"
              :key="t.id"
              type="button"
              class="staff-user-tab"
              :class="{ 'staff-user-tab--active': activeTab === t.id }"
              role="tab"
              :aria-selected="activeTab === t.id"
              @click="activeTab = t.id"
            >
              <svg
                v-if="t.id === 'overview'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25A2.25 2.25 0 0113.5 8.25V6zM3.75 15.75a2.25 2.25 0 012.25-2.25h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25v-2.25z"
                />
              </svg>
              <svg
                v-else
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M13.5 21v-7.5a.75.75 0 00-.75-.75H3a.75.75 0 00-.75.75V21m18-10.5v7.5a.75.75 0 01-.75.75H13.5a.75.75 0 01-.75-.75v-7.5m9-6h-9a.75.75 0 00-.75.75V9m10.5-3.75h-9a.75.75 0 01-.75-.75v-1.5c0-.414.336-.75.75-.75h9c.414 0 .75.336.75.75v1.5a.75.75 0 01-.75.75z"
                />
              </svg>
              {{ t.label }}
            </button>
          </div>

          <div
            class="staff-user-tab-panel"
            role="tabpanel"
            :aria-label="accountTabs.find((x) => x.id === activeTab)?.label"
          >
            <template v-if="activeTab === 'overview'">
              <div class="row g-4 mb-4">
                <div class="col-12 col-sm-6">
                  <div class="staff-stat-card h-100">
                    <p class="staff-stat-card__label">Total stores</p>
                    <p class="staff-stat-card__value">
                      {{ nf.format(storesTotal) }}
                    </p>
                    <p class="staff-stat-card__sub">Linked to this account</p>
                    <div
                      class="staff-stat-card__icon text-white"
                      style="background: #2563eb"
                      aria-hidden="true"
                    >
                      <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                        <path
                          d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-sm-6">
                  <div class="staff-stat-card h-100">
                    <p class="staff-stat-card__label">Active stores</p>
                    <p class="staff-stat-card__value">
                      {{ nf.format(storesActiveCount) }}
                    </p>
                    <p class="staff-stat-card__sub">Status active</p>
                    <div
                      class="staff-stat-card__icon bg-success-subtle text-success"
                      aria-hidden="true"
                    >
                      <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                        <path
                          d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-sm-6">
                  <div class="staff-stat-card h-100">
                    <p class="staff-stat-card__label">Contact</p>
                    <p class="staff-stat-card__value text-truncate fs-6">
                      {{ display(account.contact_full_name) }}
                    </p>
                    <p class="staff-stat-card__sub">Primary contact name</p>
                    <div
                      class="staff-stat-card__icon bg-warning-subtle text-warning-emphasis"
                      aria-hidden="true"
                    >
                      <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                        <path
                          d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-sm-6">
                  <div class="staff-stat-card h-100">
                    <p class="staff-stat-card__label">Channels</p>
                    <p class="staff-stat-card__value">
                      {{ nf.format(channelsCount) }}
                    </p>
                    <p class="staff-stat-card__sub">Notification paths on</p>
                    <div
                      class="staff-stat-card__icon bg-info-subtle text-info-emphasis"
                      aria-hidden="true"
                    >
                      <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                        <path
                          d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4 mb-4">
                <h3 class="staff-user-section-title">Account information</h3>
                <div class="row g-3">
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Company
                      </dt>
                      <dd class="mb-3 fw-semibold text-body">
                        {{ display(account.company_name) }}
                      </dd>
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Brand
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(account.brand_name) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Website
                      </dt>
                      <dd class="mb-3 fw-semibold text-body text-break">
                        {{ display(account.website) }}
                      </dd>
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Email
                      </dt>
                      <dd class="mb-0 fw-semibold text-body text-break">
                        {{ display(account.email) }}
                      </dd>
                    </dl>
                  </div>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4">
                <h3 class="staff-user-section-title">Address</h3>
                <div class="row g-3">
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Street
                      </dt>
                      <dd class="mb-3 fw-semibold text-body">
                        {{ display(account.street) }}
                      </dd>
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        City
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(account.city) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        State
                      </dt>
                      <dd class="mb-3 fw-semibold text-body">
                        {{ display(account.state) }}
                      </dd>
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        ZIP
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(account.zip) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Country
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(account.country) }}
                      </dd>
                    </dl>
                  </div>
                </div>
              </div>
            </template>

            <template v-else-if="activeTab === 'stores' && canViewStores">
              <div class="staff-table-card staff-datatable-card">
                <div
                  class="staff-table-toolbar d-flex flex-column flex-sm-row flex-wrap align-items-stretch align-items-sm-center justify-content-between gap-3"
                >
                  <h3 class="staff-datatable-filters__title mb-0">Stores</h3>
                  <button
                    v-if="canCreateStore"
                    type="button"
                    class="btn btn-primary staff-page-primary btn-sm"
                    @click="addStoreOpen = true"
                  >
                    Add store
                  </button>
                </div>
                <div
                  class="staff-table-toolbar staff-table-toolbar--split border-top-0 pt-0"
                >
                  <div
                    class="staff-toolbar-split d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-lg-between gap-3 gap-lg-4"
                  >
                    <div class="flex-shrink-0 staff-toolbar-per-page">
                      <label class="visually-hidden" for="store-per-page-toolbar"
                        >Rows per page</label
                      >
                      <select
                        id="store-per-page-toolbar"
                        class="form-select staff-toolbar-select staff-toolbar-per-page-select"
                        :value="storeListQuery.per_page"
                        :disabled="storesLoading"
                        @change="onStorePerPageChange"
                      >
                        <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
                          {{ n }}
                        </option>
                      </select>
                    </div>
                    <div
                      class="staff-toolbar-actions d-flex flex-column flex-sm-row flex-wrap align-items-stretch align-items-sm-center gap-2 flex-grow-1 min-w-0"
                    >
                      <input
                        v-model="storeSearch"
                        type="search"
                        class="form-control staff-toolbar-search flex-grow-1 min-w-0"
                        placeholder="Search stores"
                        autocomplete="off"
                      />
                      <select
                        v-model="storeStatusFilter"
                        class="form-select staff-toolbar-select flex-shrink-0"
                        style="max-width: 12rem"
                      >
                        <option value="all">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                      </select>
                      <button
                        v-if="canUpdateStore"
                        type="button"
                        class="btn btn-outline-secondary staff-toolbar-btn flex-shrink-0"
                        :disabled="!selectedStoreIds.length || storesLoading"
                        @click="openStoreBulkEdit"
                      >
                        Bulk edit
                      </button>
                    </div>
                  </div>
                </div>
                <div class="table-responsive staff-table-wrap">
                  <div v-if="storesLoading" class="d-flex justify-content-center py-5">
                    <CrmLoadingSpinner message="Loading stores…" />
                  </div>
                  <table
                    v-else
                    class="table table-hover align-middle mb-0 staff-data-table"
                  >
                    <thead class="table-light staff-table-head">
                      <tr>
                        <th
                          v-if="showStoreCheckboxCol"
                          class="staff-table-head__th"
                          scope="col"
                        >
                          <input
                            type="checkbox"
                            class="form-check-input staff-table-head__check mt-0"
                            :checked="isAllStoresPageSelected"
                            :disabled="storesLoading || !paginatedStores.length"
                            aria-label="Select all stores on this page"
                            @change="toggleSelectAllStores"
                          />
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Status
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Store name
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Website
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Marketplace
                        </th>
                        <th
                          v-if="canUpdateStore || canDeleteStore"
                          class="staff-table-head__th staff-actions-col text-end"
                          scope="col"
                        >
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!filteredStores.length">
                        <td
                          :colspan="storeTableColspan"
                          class="px-4 py-5 text-center text-secondary"
                        >
                          No stores yet.
                        </td>
                      </tr>
                      <tr
                        v-for="row in paginatedStores"
                        v-else
                        :key="row.id"
                        class="align-middle"
                      >
                        <td
                          v-if="showStoreCheckboxCol"
                          class="staff-table-cell--tight-check"
                        >
                          <input
                            type="checkbox"
                            class="form-check-input staff-table-head__check mt-0"
                            :checked="selectedStoreIds.includes(row.id)"
                            :aria-label="`Select ${row.name}`"
                            @change="toggleStoreRowSelect(row.id)"
                          />
                        </td>
                        <td>
                          <span
                            class="text-capitalize fw-medium"
                            :class="storeStatusBadgeClass(row.status)"
                          >
                            {{ row.status }}
                          </span>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-3 min-w-0">
                            <span
                              class="flex-shrink-0 rounded-circle d-inline-flex align-items-center justify-content-center small fw-semibold"
                              style="width: 2.25rem; height: 2.25rem"
                              :class="avatarClassForEmail(row.name)"
                            >
                              {{ initials(row.name) }}
                            </span>
                            <div class="min-w-0">
                              <span class="d-block fw-semibold text-body text-truncate">{{
                                row.name
                              }}</span>
                              <span
                                v-if="row.website"
                                class="d-block text-secondary staff-user-cell__meta text-truncate"
                              >
                                {{ row.website }}
                              </span>
                            </div>
                          </div>
                        </td>
                        <td
                          class="text-secondary staff-table-cell__meta text-truncate"
                          style="max-width: 12rem"
                        >
                          {{ display(row.website) }}
                        </td>
                        <td class="text-secondary staff-table-cell__meta">
                          {{ display(row.marketplace) }}
                        </td>
                        <td
                          v-if="canUpdateStore || canDeleteStore"
                          class="staff-actions-cell"
                        >
                          <div class="staff-actions-inner">
                            <button
                              v-if="canUpdateStore"
                              type="button"
                              class="staff-action-btn"
                              aria-label="Edit store"
                              @click="openEditStore(row)"
                            >
                              <svg
                                width="18"
                                height="18"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                              >
                                <path
                                  d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"
                                />
                              </svg>
                            </button>
                            <button
                              v-if="canDeleteStore"
                              type="button"
                              class="staff-action-btn text-danger"
                              aria-label="Delete store"
                              @click="storeDeleteTarget = row"
                            >
                              <svg
                                width="18"
                                height="18"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                              >
                                <path
                                  d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"
                                />
                              </svg>
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div
                  v-if="!storesLoading && filteredStores.length > 0"
                  class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
                >
                  <p
                    class="small text-secondary mb-0 order-2 order-lg-1 text-center text-lg-start"
                  >
                    Showing
                    <span class="fw-semibold text-body">{{ showingStoresFrom }}</span>
                    to
                    <span class="fw-semibold text-body">{{ showingStoresTo }}</span>
                    of
                    <span class="fw-semibold text-body">{{ storeListTotal }}</span>
                    entries
                  </p>
                  <nav
                    class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
                    aria-label="Store list pages"
                  >
                    <div class="staff-page-pager staff-page-pager--cluster">
                      <div class="staff-page-pager__start">
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="storesLoading || storeListQuery.page <= 1"
                          aria-label="First page"
                          @click="storeFirstPage"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path
                              d="M5.59 18L7 16.59 2.41 12 7 7.41 5.59 6l-6 6 6 6zm8 0L15 16.59 10.41 12 15 7.41 13.59 6l-6 6 6 6z"
                            />
                          </svg>
                        </button>
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="storesLoading || storeListQuery.page <= 1"
                          aria-label="Previous page"
                          @click="storeGoPage(storeListQuery.page - 1)"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                          </svg>
                        </button>
                      </div>
                      <div class="staff-page-pager__pages">
                        <div class="staff-page-pager-inner d-flex align-items-center">
                          <template
                            v-for="(item, idx) in storePageItems"
                            :key="'st-pi-' + idx"
                          >
                            <span
                              v-if="item.type === 'gap'"
                              class="px-1 small text-secondary user-select-none"
                              >…</span
                            >
                            <button
                              v-else
                              type="button"
                              class="staff-page-pager-tile"
                              :class="{
                                'staff-page-pager-tile--active':
                                  item.value === storeListQuery.page,
                              }"
                              :disabled="storesLoading"
                              @click="storeGoPage(item.value)"
                            >
                              {{ item.value }}
                            </button>
                          </template>
                        </div>
                      </div>
                      <div class="staff-page-pager__end">
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="
                            storesLoading ||
                            storeListQuery.page >= storeListLastPage
                          "
                          aria-label="Next page"
                          @click="storeGoPage(storeListQuery.page + 1)"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" />
                          </svg>
                        </button>
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="
                            storesLoading ||
                            storeListQuery.page >= storeListLastPage
                          "
                          aria-label="Last page"
                          @click="storeLastPageFn"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path
                              d="M6.41 6L5 7.41 9.58 12 5 16.59 6.41 18l6-6-6-6zm8 0L13 7.41 17.58 12 13 16.59 14.41 18l6-6-6-6z"
                            />
                          </svg>
                        </button>
                      </div>
                    </div>
                  </nav>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
