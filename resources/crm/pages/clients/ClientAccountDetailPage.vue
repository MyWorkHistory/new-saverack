<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientStoreCreateDrawer from "../../components/clients/ClientStoreCreateDrawer.vue";
import ClientStoreEditModal from "../../components/clients/ClientStoreEditModal.vue";
import ClientStoresBulkEditModal from "../../components/clients/ClientStoresBulkEditModal.vue";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { crmIsAdmin } from "../../utils/crmUser";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const route = useRoute();
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

const TAB_ACCOUNT_INFO = "account-info";
const TAB_FEES = "fees";
const TAB_BILLING = "billing";

const accountTabList = [
  { id: TAB_ACCOUNT_INFO, label: "Account Info" },
  { id: TAB_FEES, label: "Fees" },
  { id: TAB_BILLING, label: "Billing" },
];

const activeTab = ref(TAB_ACCOUNT_INFO);

function tabFromRouteQuery(tab) {
  const t = String(tab || "").toLowerCase();
  if (t === TAB_FEES) return TAB_FEES;
  if (t === TAB_BILLING) return TAB_BILLING;
  if (
    t === TAB_ACCOUNT_INFO ||
    t === "overview" ||
    t === "stores"
  ) {
    return TAB_ACCOUNT_INFO;
  }
  return TAB_ACCOUNT_INFO;
}

function syncTabFromRoute() {
  const next = tabFromRouteQuery(route.query.tab);
  if (activeTab.value !== next) {
    activeTab.value = next;
  }
}

function setActiveTab(tabId) {
  activeTab.value = tabId;
  const q = String(route.query.tab || "");
  if (q !== tabId) {
    router.replace({ query: { ...route.query, tab: tabId } });
  }
}

watch(
  () => route.query.tab,
  () => {
    const next = tabFromRouteQuery(route.query.tab);
    if (activeTab.value !== next) {
      activeTab.value = next;
    }
  },
);

const notesDraft = ref("");
const notesSaving = ref(false);

watch(
  () => account.value,
  (a) => {
    if (a) {
      notesDraft.value = a.notes != null ? String(a.notes) : "";
    }
  },
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

const storeCountDisplay = computed(() => {
  const a = account.value;
  if (a && a.stores_count != null) return Number(a.stores_count);
  return stores.value.length;
});

const usersCountDisplay = computed(() => {
  const a = account.value;
  if (a && a.account_users_count != null) return Number(a.account_users_count);
  return 0;
});

function formatAccountAddress(a) {
  if (!a) return "";
  const lines = [];
  if (a.street) lines.push(String(a.street));
  const cityParts = [a.city, a.state, a.zip].filter(
    (x) => x != null && String(x).trim() !== "",
  );
  if (cityParts.length) lines.push(cityParts.join(", "));
  if (a.country) lines.push(String(a.country));
  return lines.join("\n");
}

async function saveNotes() {
  if (!canUpdateAccount.value || !account.value) return;
  notesSaving.value = true;
  try {
    await api.patch(`/client-accounts/${props.id}`, {
      notes: notesDraft.value.trim() || null,
    });
    toast.success("Notes saved.");
    await loadAccount();
  } catch (e) {
    toast.errorFrom(e, "Could not save notes.");
  } finally {
    notesSaving.value = false;
  }
}

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
    await refreshStoresAndAccountCounts();
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

async function refreshStoresAndAccountCounts() {
  await loadStores();
  await loadAccount();
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
    await refreshStoresAndAccountCounts();
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
  syncTabFromRoute();
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
        <h1 class="staff-user-view__title">Account Profile</h1>
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
      @saved="refreshStoresAndAccountCounts"
    />
    <ClientStoreEditModal
      v-if="canUpdateStore"
      v-model:open="editStoreOpen"
      :store="editingStore"
      @saved="refreshStoresAndAccountCounts"
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
            <div class="text-center mb-3">
              <span
                class="text-capitalize"
                :class="accountStatusBadgeClass(account.status)"
              >{{ account.status }}</span>
            </div>
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
                  {{ nf.format(storeCountDisplay) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Stores</div>
              </div>
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ nf.format(usersCountDisplay) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Users</div>
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
            v-for="t in accountTabList"
            :key="t.id"
            type="button"
            class="staff-user-tab"
            :class="{ 'staff-user-tab--active': activeTab === t.id }"
            role="tab"
            :aria-selected="activeTab === t.id"
            @click="setActiveTab(t.id)"
          >
            {{ t.label }}
          </button>
        </div>

        <div
          class="staff-user-tab-panel"
          role="tabpanel"
          :aria-label="accountTabList.find((x) => x.id === activeTab)?.label"
        >
          <template v-if="activeTab === TAB_ACCOUNT_INFO">
            <div class="staff-surface p-3 p-md-4 mb-4">
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
              >
                <h3 class="staff-user-section-title mb-0">Account Info</h3>
                <button
                  v-if="canUpdateAccount"
                  type="button"
                  class="btn btn-primary staff-page-primary btn-sm"
                  @click="editAccountOpen = true"
                >
                  Edit details
                </button>
              </div>
              <dl class="mb-0 small">
                <dt
                  class="text-secondary text-uppercase fw-semibold mb-1"
                  style="font-size: 0.65rem"
                >
                  Company
                </dt>
                <dd class="mb-3 fw-semibold text-body">
                  {{ display(account.company_name) }}
                </dd>
                <dt
                  class="text-secondary text-uppercase fw-semibold mb-1"
                  style="font-size: 0.65rem"
                >
                  Name
                </dt>
                <dd class="mb-3 fw-semibold text-body">
                  {{ display(account.contact_full_name) }}
                </dd>
                <dt
                  class="text-secondary text-uppercase fw-semibold mb-1"
                  style="font-size: 0.65rem"
                >
                  Email
                </dt>
                <dd class="mb-3 fw-semibold text-body text-break">
                  {{ display(account.email) }}
                </dd>
                <dt
                  class="text-secondary text-uppercase fw-semibold mb-1"
                  style="font-size: 0.65rem"
                >
                  Phone
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{ display(account.phone) }}
                </dd>
              </dl>
            </div>

            <div class="staff-surface p-3 p-md-4 mb-4">
              <h3 class="staff-user-section-title">Address</h3>
              <p
                v-if="formatAccountAddress(account).trim()"
                class="mb-0 small fw-semibold text-body"
                style="white-space: pre-line"
              >
                {{ formatAccountAddress(account) }}
              </p>
              <p v-else class="text-secondary small mb-0">No address on file.</p>
            </div>

            <div class="staff-surface p-3 p-md-4 mb-4">
              <h3 class="staff-user-section-title">Notes</h3>
              <template v-if="canUpdateAccount">
                <textarea
                  v-model="notesDraft"
                  class="form-control mb-3"
                  rows="5"
                  placeholder="Add internal notes…"
                />
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary btn-sm"
                  :disabled="notesSaving"
                  @click="saveNotes"
                >
                  {{ notesSaving ? "Saving…" : "Save notes" }}
                </button>
              </template>
              <template v-else>
                <p
                  v-if="notesDraft.trim()"
                  class="mb-0 small"
                  style="white-space: pre-wrap"
                >
                  {{ notesDraft }}
                </p>
                <p v-else class="text-secondary small mb-0">No notes.</p>
              </template>
            </div>

            <template v-if="canViewStores">
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
                          class="staff-table-head__th staff-table-head__th--select"
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
          </template>

          <template v-else-if="activeTab === TAB_FEES">
            <div class="staff-surface p-3 p-md-4">
              <p class="text-secondary small mb-0">
                Fee schedules and account pricing will appear here.
              </p>
            </div>
          </template>

          <template v-else-if="activeTab === TAB_BILLING">
            <div class="staff-surface p-3 p-md-4">
              <p class="text-secondary small mb-0">
                Billing history and invoices will appear here.
              </p>
            </div>
          </template>
        </div>
        </div>
      </div>
    </template>
  </div>
</template>
