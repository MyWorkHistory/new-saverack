<script setup>
import { Transition, computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter, RouterLink } from "vue-router";
import api from "../../services/api";
import AsnHubSummaryCards from "../../components/asn/AsnHubSummaryCards.vue";
import AsnStatusChip from "../../components/asn/AsnStatusChip.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import AdminAsnCreateDrawer from "../../components/admin-asn/AdminAsnCreateDrawer.vue";
import AdminAsnNonCompliantDrawer from "../../components/admin-asn/AdminAsnNonCompliantDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { asnTrackingUrl } from "../../utils/asnTrackingUrl.js";
import { formatAsnDisplay } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { avatarClassFromSeed, initialsFromName } from "../../utils/avatarDisplay.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();

const loading = ref(true);
const summaryLoading = ref(true);
const summary = ref({
  pending: 0,
  in_progress: 0,
  completed: 0,
  non_compliant: 0,
});
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });

const accounts = ref([]);
const accountsLoading = ref(false);
const accountFilter = ref("");
const statusFilter = ref("pending");
const filterMenuOpen = ref(false);
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const sortBy = ref("created_at");
const sortDir = ref("desc");

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 200;
const MENU_H = 160;

const selected = ref(new Set());
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);
const rowDeleteOpen = ref(false);
const rowDeleteTarget = ref(null);
const rowDeleteBusy = ref(false);

const createModalOpen = ref(false);
const createAccountId = ref("");
const createBusy = ref(false);

const nonCompliantOpen = ref(false);
const nonCompliantBusy = ref(false);
const ncAccountId = ref("");
const ncBoxes = ref(0);
const ncPallets = ref(0);
const ncFee = ref("");
const ncFeeDefaultLabel = ref("");
const ncTrackings = ref([{ carrier: "", tracking_number: "" }]);

const tableColspan = 11;

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer !== false)
    .map((a) => ({
      id: a.id,
      name: a.company_name || a.label || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const allSelected = computed(() => {
  if (rows.value.length === 0) return false;
  return rows.value.every((r) => selected.value.has(r.id));
});

const selectedCount = computed(() => selected.value.size);

const selectedDeletableIds = computed(() =>
  rows.value.filter((r) => selected.value.has(r.id)).map((r) => r.id),
);

const bulkDeleteDisabled = computed(() => selectedCount.value === 0);

const manageMenuRowCanDelete = computed(() => Boolean(manageMenuRow.value));

const summaryActiveStatus = computed(() =>
  statusFilter.value === "all" ? "" : statusFilter.value,
);

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = v.trim();
    meta.value.current_page = 1;
    loadList();
  }, 300);
});

watch([accountFilter, statusFilter], () => {
  meta.value.current_page = 1;
  loadSummary();
  loadList();
});

function sortIndicator(column) {
  if (sortBy.value !== column) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (sortBy.value !== column) {
    sortBy.value = column;
    sortDir.value = "asc";
  } else {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  }
  meta.value.current_page = 1;
  loadList();
}

function summaryParams() {
  const p = {};
  if (accountFilter.value) {
    p.client_account_id = accountFilter.value;
  }
  return p;
}

function listParams() {
  const p = {
    page: meta.value.current_page,
    per_page: meta.value.per_page,
    sort_by: sortBy.value,
    sort_dir: sortDir.value,
  };
  if (accountFilter.value) {
    p.client_account_id = accountFilter.value;
  }
  if (statusFilter.value && statusFilter.value !== "all") {
    p.status = statusFilter.value;
  }
  if (searchDebounced.value) {
    p.q = searchDebounced.value;
  }
  return p;
}

async function loadSummary() {
  summaryLoading.value = true;
  try {
    const { data } = await api.get("/admin/asns/summary", { params: summaryParams() });
    summary.value = {
      pending: Number(data.pending || 0),
      in_progress: Number(data.in_progress || 0),
      completed: Number(data.completed || 0),
      non_compliant: Number(data.non_compliant || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN summary.");
  } finally {
    summaryLoading.value = false;
  }
}

async function loadList() {
  loading.value = true;
  try {
    const { data } = await api.get("/admin/asns", { params: listParams() });
    rows.value = data.data || [];
    meta.value = { ...meta.value, ...(data.meta || {}) };
    selected.value = new Set();
  } catch (e) {
    toast.errorFrom(e, "Could not load ASNs.");
  } finally {
    loading.value = false;
  }
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

function setStatusCard(status) {
  statusFilter.value = statusFilter.value === status ? "all" : status;
}

function resetStatusFilter() {
  statusFilter.value = "pending";
  filterMenuOpen.value = false;
}

function clearSelection() {
  selected.value = new Set();
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set();
  } else {
    selected.value = new Set(rows.value.map((r) => r.id));
  }
}

function toggleOne(id) {
  const next = new Set(selected.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selected.value = next;
}

async function confirmBulkDelete() {
  if (selectedDeletableIds.value.length === 0) return;
  bulkDeleteBusy.value = true;
  try {
    const byAccount = new Map();
    for (const row of rows.value) {
      if (!selectedDeletableIds.value.includes(row.id)) continue;
      const accountId = Number(row.client_account_id || 0);
      if (!byAccount.has(accountId)) byAccount.set(accountId, []);
      byAccount.get(accountId).push(row.id);
    }
    for (const [accountId, ids] of byAccount.entries()) {
      await api.post("/asns/bulk-delete", {
        client_account_id: accountId,
        ids,
      });
    }
    toast.success("Deleted selected ASNs.");
    bulkDeleteOpen.value = false;
    clearSelection();
    await Promise.all([loadSummary(), loadList()]);
  } catch (e) {
    toast.errorFrom(e, "Bulk delete failed.");
  } finally {
    bulkDeleteBusy.value = false;
  }
}

function openRowDeleteFromMenu() {
  if (!manageMenuRow.value) return;
  rowDeleteTarget.value = manageMenuRow.value;
  manageOpenId.value = null;
  rowDeleteOpen.value = true;
}

async function confirmRowDelete() {
  if (!rowDeleteTarget.value?.id) return;
  rowDeleteBusy.value = true;
  try {
    await api.delete(`/asns/${rowDeleteTarget.value.id}`);
    toast.success("ASN removed.");
    rowDeleteOpen.value = false;
    rowDeleteTarget.value = null;
    await Promise.all([loadSummary(), loadList()]);
  } catch (e) {
    toast.errorFrom(e, "Could not delete ASN.");
  } finally {
    rowDeleteBusy.value = false;
  }
}

function openCreateModal() {
  createAccountId.value = accountFilter.value || "";
  createModalOpen.value = true;
}

async function confirmCreate() {
  const id = Number(createAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  createBusy.value = true;
  try {
    const { data } = await api.post("/asns", { client_account_id: id });
    createModalOpen.value = false;
    toast.success("ASN created.");
    const href = router.resolve({
      name: "admin-asn-detail",
      params: { id: String(data.id) },
      query: { client_account_id: String(id) },
    }).href;
    window.open(href, "_blank", "noopener,noreferrer");
    await loadList();
  } catch (e) {
    toast.errorFrom(e, "Could not create ASN.");
  } finally {
    createBusy.value = false;
  }
}

function openNonCompliant() {
  ncAccountId.value = accountFilter.value || "";
  ncBoxes.value = 0;
  ncPallets.value = 0;
  ncFee.value = "";
  ncFeeDefaultLabel.value = "";
  ncTrackings.value = [{ carrier: "", tracking_number: "" }];
  nonCompliantOpen.value = true;
  if (ncAccountId.value) {
    loadNcChargeOptions(ncAccountId.value);
  }
}

async function loadNcChargeOptions(accountId) {
  const id = Number(accountId || 0);
  if (!id) {
    ncFee.value = "";
    ncFeeDefaultLabel.value = "";
    return;
  }
  try {
    const { data } = await api.get("/admin/asns/charge-options", {
      params: { client_account_id: id },
    });
    const options = Array.isArray(data?.charge_options) ? data.charge_options : [];
    const ncOpt = options.find((o) => o.line_type === "non_compliant");
    const cents = Number(ncOpt?.default_unit_price_cents) || 0;
    if (cents > 0) {
      ncFee.value = (cents / 100).toFixed(2);
      ncFeeDefaultLabel.value = `Account default: $${(cents / 100).toFixed(2)}`;
    } else {
      ncFee.value = "";
      ncFeeDefaultLabel.value = "No account price configured";
    }
  } catch (e) {
    ncFee.value = "";
    ncFeeDefaultLabel.value = "";
    toast.errorFrom(e, "Could not load account fee.");
  }
}

watch(ncAccountId, (id) => {
  if (nonCompliantOpen.value) {
    loadNcChargeOptions(id);
  }
});

function addNcTracking() {
  ncTrackings.value = [...ncTrackings.value, { carrier: "", tracking_number: "" }];
}

async function submitNonCompliant() {
  const id = Number(ncAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  const trackings = ncTrackings.value
    .map((t) => ({
      carrier: String(t.carrier || "").trim(),
      tracking_number: String(t.tracking_number || "").trim(),
    }))
    .filter((t) => t.tracking_number !== "");
  if (!trackings.length) {
    toast.error("Add at least one tracking number.");
    return;
  }
  nonCompliantBusy.value = true;
  try {
    const fee = ncFee.value === "" ? 0 : Number(ncFee.value);
    const { data } = await api.post("/admin/asns/non-compliant", {
      client_account_id: id,
      total_boxes: Number(ncBoxes.value) || 0,
      total_pallets: Number(ncPallets.value) || 0,
      trackings,
      fee: Number.isFinite(fee) ? fee : 0,
    });
    nonCompliantOpen.value = false;
    toast.success("Non-compliant ASN created.");
    const href = router.resolve({
      name: "admin-asn-detail",
      params: { id: String(data.id) },
      query: { client_account_id: String(id) },
    }).href;
    window.open(href, "_blank", "noopener,noreferrer");
    await loadList();
  } catch (e) {
    toast.errorFrom(e, "Could not create non-compliant ASN.");
  } finally {
    nonCompliantBusy.value = false;
  }
}

function trackingLink(row) {
  return asnTrackingUrl(row.tracking_carrier, row.tracking_display);
}

function openRow(r) {
  const accountId = Number(r.client_account_id || accountFilter.value || 0);
  const query = accountId > 0 ? { client_account_id: String(accountId) } : {};
  const href = router.resolve({
    name: "admin-asn-detail",
    params: { id: String(r.id) },
    query,
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
}

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(rowId, e) {
  e?.stopPropagation?.();
  if (manageOpenId.value === rowId) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function viewAsnFromMenu() {
  if (manageMenuRow.value) openRow(manageMenuRow.value);
  manageOpenId.value = null;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    manageOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function onWindowCloseManageMenu() {
  manageOpenId.value = null;
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Advanced Shipment Notice",
    description: "Search and manage advance shipping notices.",
  });
  if (route.query.status) {
    statusFilter.value = String(route.query.status);
  } else {
    statusFilter.value = "pending";
  }
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowCloseManageMenu, true);
  window.addEventListener("resize", onWindowCloseManageMenu);
  await loadAccounts();
  await Promise.all([loadSummary(), loadList()]);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowCloseManageMenu, true);
  window.removeEventListener("resize", onWindowCloseManageMenu);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Advanced Shipment Notice</h1>
        <p class="small admin-asn-list__subtitle mb-0">Search by ASN # or tracking #.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-primary staff-page-primary" @click="openCreateModal">
          Create ASN
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
          @click="openNonCompliant"
        >
          Non-Compliant ASN
        </button>
      </div>
    </div>

    <div class="admin-asn-hub-summary mb-4">
      <AsnHubSummaryCards
        :loading="summaryLoading"
        :active-status="summaryActiveStatus"
        :values="summary"
        @select="setStatusCard"
      />
    </div>

    <div
      class="admin-asn-list admin-asn-page-toolbar staff-table-card staff-datatable-card staff-datatable-card--white w-100"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row admin-asn-toolbar-row">
          <div class="admin-asn-toolbar-account">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline w-100"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
            />
          </div>
          <div class="admin-asn-toolbar-search">
            <input
              id="admin-asn-list-search"
              v-model="search"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="Search ASN # or tracking #"
              autocomplete="off"
              aria-label="Search ASN"
              @keydown.enter.prevent="loadList"
            />
          </div>
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="ASN filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetStatusFilter"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="admin-asn-filter-status">Status</label>
                <select
                  id="admin-asn-filter-status"
                  v-model="statusFilter"
                  class="form-select staff-datatable-filters__select"
                >
                  <option value="all">All</option>
                  <option value="draft">Draft</option>
                  <option value="pending">Pending</option>
                  <option value="in_progress">In Progress</option>
                  <option value="completed">Completed</option>
                  <option value="non_compliant">Non-Compliant</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div
        v-if="selectedCount > 0"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <span class="small staff-bulk-selection-bar__count me-md-1">{{ selectedCount }} selected</span>
        <button
          type="button"
          class="btn btn-outline-danger btn-sm orders-bulk-toolbar-btn orders-toolbar-outline-btn orders-toolbar-outline-btn--danger"
          :disabled="bulkDeleteDisabled || loading"
          @click="bulkDeleteOpen = true"
        >
          Delete Selected
        </button>
        <button
          type="button"
          class="btn btn-link btn-sm staff-bulk-clear-link text-decoration-none px-1"
          @click="clearSelection"
        >
          Clear
        </button>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--select text-center" scope="col">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check"
                  :checked="allSelected"
                  :disabled="loading || rows.length === 0"
                  aria-label="Select all ASNs on this page"
                  @click.stop
                  @change="toggleAll"
                />
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center admin-asn-list-asn-col" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('asn_number')">
                  ASN #
                  <span v-if="sortIndicator('asn_number')" class="staff-sort-ind">{{ sortIndicator("asn_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('created_at')">
                  Date Created
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('expected_qty')">
                  Expected QTY
                  <span v-if="sortIndicator('expected_qty')" class="staff-sort-ind">{{ sortIndicator("expected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('accepted_qty')">
                  Accepted QTY
                  <span v-if="sortIndicator('accepted_qty')" class="staff-sort-ind">{{ sortIndicator("accepted_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('rejected_qty')">
                  Rejected QTY
                  <span v-if="sortIndicator('rejected_qty')" class="staff-sort-ind">{{ sortIndicator("rejected_qty") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('total_boxes')">
                  Total Boxes
                  <span v-if="sortIndicator('total_boxes')" class="staff-sort-ind">{{ sortIndicator("total_boxes") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th text-center admin-asn-list-tracking-col" scope="col">Tracking</th>
              <th class="staff-table-head__th staff-actions-col text-center admin-asn-list-actions-col" scope="col">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading ASNs…" />
                </div>
              </td>
            </tr>
            <template v-else>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="align-middle cursor-pointer"
                @click="openRow(row)"
              >
                <td class="staff-table-cell--tight-check text-center" @click.stop>
                  <input
                    type="checkbox"
                    class="form-check-input staff-table-head__check"
                    :checked="selected.has(row.id)"
                    :aria-label="`Select ASN ${formatAsnDisplay(row.asn_number)}`"
                    @click.stop
                    @change="toggleOne(row.id)"
                  />
                </td>
                <td class="text-center">
                  <AsnStatusChip :status="row.status" />
                </td>
                <td class="text-center fw-semibold admin-asn-list-asn-col">
                  {{ formatAsnDisplay(row.asn_number) }}
                </td>
                <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) }}</td>
                <td @click.stop>
                  <div class="d-flex align-items-center justify-content-start gap-2 min-w-0 admin-asn-list-account-cell">
                    <span
                      class="admin-asn-list-account-avatar"
                      :class="avatarClassFromSeed(row.client_account_company_name || row.client_account_id)"
                      aria-hidden="true"
                    >
                      {{ initialsFromName(row.client_account_company_name) }}
                    </span>
                    <RouterLink
                      v-if="row.client_account_id"
                      :to="`/admin/clients/accounts/${row.client_account_id}`"
                      class="text-truncate text-body text-decoration-none fw-medium"
                    >
                      {{ row.client_account_company_name || "—" }}
                    </RouterLink>
                    <span v-else class="text-truncate text-secondary small">
                      {{ row.client_account_company_name || "—" }}
                    </span>
                  </div>
                </td>
                <td class="text-center">{{ Number(row.expected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(row.accepted_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(row.rejected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(row.total_boxes ?? 0).toLocaleString() }}</td>
                <td class="text-center small text-secondary admin-asn-list-tracking-col" @click.stop>
                  <a
                    v-if="trackingLink(row)"
                    :href="trackingLink(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-decoration-none admin-asn-list-tracking-text"
                  >
                    {{ row.tracking_display || "—" }}
                  </a>
                  <span v-else class="admin-asn-list-tracking-text">{{ row.tracking_display || "—" }}</span>
                </td>
                <td class="staff-actions-cell text-center admin-asn-list-actions-cell" @click.stop>
                  <div
                    data-row-actions
                    class="staff-actions-inner staff-actions-inner--single admin-asn-list-actions-inner"
                  >
                    <button
                      type="button"
                      class="staff-action-btn staff-action-btn--more"
                      :class="{ 'is-open': manageOpenId == row.id }"
                      :aria-expanded="manageOpenId == row.id ? 'true' : 'false'"
                      aria-haspopup="true"
                      aria-label="Row actions"
                      @click="toggleManageMenu(row.id, $event)"
                    >
                      <CrmIconRowActions variant="horizontal" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="rows.length === 0">
                <td :colspan="tableColspan" class="text-center text-secondary py-5">No ASNs found.</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        v-if="!loading && meta.last_page > 1"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="
              meta.current_page--;
              loadList();
            "
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="
              meta.current_page++;
              loadList();
            "
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="manageMenuRow"
          data-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
          }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="viewAsnFromMenu">
            View ASN
          </button>
          <button
            v-if="manageMenuRowCanDelete"
            type="button"
            class="staff-row-menu__item text-danger"
            role="menuitem"
            @click="openRowDeleteFromMenu"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>

    <AdminAsnCreateDrawer
      v-model:open="createModalOpen"
      v-model:account-id="createAccountId"
      :account-options="accountOptions"
      :busy="createBusy"
      @submit="confirmCreate"
    />

    <AdminAsnNonCompliantDrawer
      v-model:open="nonCompliantOpen"
      v-model:account-id="ncAccountId"
      v-model:boxes="ncBoxes"
      v-model:pallets="ncPallets"
      v-model:fee="ncFee"
      v-model:trackings="ncTrackings"
      :account-options="accountOptions"
      :fee-default-label="ncFeeDefaultLabel"
      :busy="nonCompliantBusy"
      @add-tracking="addNcTracking"
      @submit="submitNonCompliant"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete ASNs"
      message="Remove the selected ASNs? This cannot be undone."
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="bulkDeleteBusy"
      danger
      @close="bulkDeleteOpen = false"
      @confirm="confirmBulkDelete"
    />

    <ConfirmModal
      :open="rowDeleteOpen"
      title="Remove ASN"
      :message="
        rowDeleteTarget
          ? `Remove ${formatAsnDisplay(rowDeleteTarget.asn_number)}? This cannot be undone.`
          : ''
      "
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="rowDeleteBusy"
      danger
      @close="
        rowDeleteOpen = false;
        rowDeleteTarget = null;
      "
      @confirm="confirmRowDelete"
    />
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.admin-asn-list :deep(.staff-table-head__th--sort .staff-sort-btn) {
  justify-content: center;
  width: 100%;
  text-align: center;
}

.admin-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
.admin-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.35);
  color: var(--bs-body-color);
}

[data-bs-theme="dark"] .admin-asn-list :deep(.staff-table-footer .btn-outline-secondary:hover:not(:disabled)),
[data-bs-theme="dark"] .admin-asn-list :deep(.staff-table-footer .btn-outline-secondary:focus-visible) {
  background-color: rgba(115, 103, 240, 0.12);
  border-color: rgba(186, 175, 255, 0.35);
  color: var(--bs-body-color);
}

.admin-asn-list :deep(.table.staff-data-table > thead > tr > th.admin-asn-list-actions-col),
.admin-asn-list :deep(.table.staff-data-table > tbody > tr > td.admin-asn-list-actions-cell) {
  text-align: center !important;
}

.admin-asn-list :deep(.admin-asn-list-actions-inner) {
  justify-content: center !important;
}

.admin-asn-list :deep(.admin-asn-list-tracking-col) {
  min-width: 9rem;
}

.admin-asn-list :deep(.admin-asn-list-actions-col) {
  min-width: 5.5rem;
}

.admin-asn-list :deep(.admin-asn-list-asn-col) {
  min-width: 6.5rem;
}

.admin-asn-list-tracking-text {
  display: inline-block;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}

.admin-asn-list__subtitle {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--bs-secondary-color, #6c757d);
}

[data-bs-theme="dark"] .admin-asn-list__subtitle {
  color: #fff !important;
}

.admin-asn-page-toolbar .admin-asn-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

@media (max-width: 767.98px) {
  .admin-asn-page-toolbar .admin-asn-toolbar-row {
    display: flex;
  }
}

.admin-asn-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.admin-asn-toolbar-search {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}
</style>
