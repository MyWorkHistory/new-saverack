<script setup>
import { Transition, computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";
import { asnTrackingUrl } from "../../utils/asnTrackingUrl.js";
import { formatAsnDisplay } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

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
const statusFilter = ref("");
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const sortBy = ref("created_at");
const sortDir = ref("desc");

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 200;
const MENU_H = 120;

const actionMenuOpen = ref(false);
const actionMenuRect = ref({ top: 0, left: 0 });
const ACTION_MENU_W = 200;
const ACTION_MENU_H = 56;

const scanOpen = ref(false);
const scanAsnNumber = ref("");
const scanText = ref("");
const scanBusy = ref(false);

const createModalOpen = ref(false);
const createAccountId = ref("");
const createBusy = ref(false);

const nonCompliantOpen = ref(false);
const nonCompliantBusy = ref(false);
const ncAccountId = ref("");
const ncBoxes = ref(0);
const ncPallets = ref(0);
const ncFee = ref("");
const ncTrackings = ref([{ carrier: "", tracking_number: "" }]);

const tableColspan = 9;

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

const STAT_CARDS = [
  {
    key: "pending",
    label: "Pending",
    sub: "Pending ASNs",
    status: "pending",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
    iconPath:
      "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
  },
  {
    key: "in_progress",
    label: "In-Progress",
    sub: "Processing ASNs",
    status: "in_progress",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
    iconPath:
      "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  },
  {
    key: "completed",
    label: "Completed",
    sub: "Completed ASNs",
    status: "completed",
    iconStyle: { background: "#dcfce7", color: "#166534" },
    iconPath: "M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z",
  },
  {
    key: "non_compliant",
    label: "Non-Compliant",
    sub: "Non-Compliant ASNs",
    status: "non_compliant",
    iconStyle: { background: "#fee2e2", color: "#b91c1c" },
    iconPath: "M6.4 19L5 17.6l5.6-5.6L5 6.4 6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12l5.6 5.6L17.6 19l-5.6-5.6z",
  },
];

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

function statusLabel(s) {
  const x = String(s || "").toLowerCase();
  if (x === "draft") return "Draft";
  if (x === "pending") return "Pending";
  if (x === "in_progress") return "In Progress";
  if (x === "completed") return "Completed";
  if (x === "non_compliant") return "Non-Compliant";
  return s || "—";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "non_compliant") return "bg-danger-subtle text-danger-emphasis";
  return "bg-body-secondary text-body-secondary";
}

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
  if (statusFilter.value) {
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
  statusFilter.value = statusFilter.value === status ? "" : status;
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
  ncTrackings.value = [{ carrier: "", tracking_number: "" }];
  nonCompliantOpen.value = true;
}

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
  const href = router.resolve({
    name: "admin-asn-detail",
    params: { id: String(r.id) },
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

function placeActionMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - ACTION_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - ACTION_MENU_W - 8));
  if (top + ACTION_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - ACTION_MENU_H - 4);
  }
  actionMenuRect.value = { top, left };
}

async function toggleActionMenu(e) {
  e?.stopPropagation?.();
  if (actionMenuOpen.value) {
    actionMenuOpen.value = false;
    return;
  }
  const btn = e?.currentTarget;
  actionMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeActionMenu(btn);
  });
}

async function openScanFromAction() {
  actionMenuOpen.value = false;
  scanAsnNumber.value = "";
  scanText.value = "";
  scanOpen.value = true;
  await nextTick();
  document.getElementById("admin-asn-scan-asn-number")?.focus();
}

function normalizeAsnNumberInput(raw) {
  return formatAsnDisplay(String(raw ?? "").trim());
}

async function resolveAsnIdByNumber(asnNumber) {
  const needle = normalizeAsnNumberInput(asnNumber);
  if (!needle) {
    return null;
  }
  const params = { q: needle, per_page: 25 };
  if (accountFilter.value) {
    params.client_account_id = accountFilter.value;
  }
  const { data } = await api.get("/admin/asns", { params });
  const list = data.data || [];
  const matches = list.filter((row) => normalizeAsnNumberInput(row.asn_number) === needle);
  if (matches.length === 1) {
    return matches[0].id;
  }
  if (matches.length > 1) {
    throw new Error("Multiple ASNs match that number. Narrow by account filter.");
  }
  return null;
}

async function submitScan() {
  const asnNum = normalizeAsnNumberInput(scanAsnNumber.value);
  if (!asnNum) {
    toast.error("Enter an ASN #.");
    return;
  }
  if (!String(scanText.value || "").trim()) {
    toast.error("Enter at least one barcode.");
    return;
  }
  scanBusy.value = true;
  try {
    const asnId = await resolveAsnIdByNumber(asnNum);
    if (!asnId) {
      toast.error("No ASN found for that number.");
      return;
    }
    const { data } = await api.post(`/admin/asns/${asnId}/scan-barcodes`, {
      barcodes: scanText.value,
    });
    const unmatched = data.unmatched || [];
    if (unmatched.length) {
      toast.error(`No match for: ${unmatched.slice(0, 3).join(", ")}${unmatched.length > 3 ? "…" : ""}`);
    } else {
      toast.success(`Processed ${data.matched || 0} item(s).`);
    }
    scanOpen.value = false;
    scanAsnNumber.value = "";
    scanText.value = "";
    await Promise.all([loadSummary(), loadList()]);
  } catch (e) {
    const msg = e instanceof Error && e.message ? e.message : null;
    toast.errorFrom(e, msg || "Could not process barcodes.");
  } finally {
    scanBusy.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    manageOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-asn-hub-actions]")) {
    actionMenuOpen.value = false;
  }
}

function onWindowCloseManageMenu() {
  manageOpenId.value = null;
  actionMenuOpen.value = false;
}

const statCardValue = (key) => summary.value[key] ?? 0;

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Advanced Shipment Notice",
    description: "Search and manage advance shipping notices.",
  });
  if (route.query.status) {
    statusFilter.value = String(route.query.status);
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
        <button type="button" class="btn btn-outline-secondary" @click="openNonCompliant">
          Non-Compliant ASN
        </button>
        <div data-asn-hub-actions class="position-relative">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :class="{ 'is-open': actionMenuOpen }"
            aria-haspopup="true"
            :aria-expanded="actionMenuOpen ? 'true' : 'false'"
            @click="toggleActionMenu"
          >
            Action
          </button>
        </div>
      </div>
    </div>

    <div v-if="summaryLoading" class="d-flex justify-content-center py-4 mb-4">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>
    <div v-else class="row g-3 mb-4">
      <div v-for="card in STAT_CARDS" :key="card.key" class="col-12 col-sm-6 col-xl-3">
        <button
          type="button"
          class="staff-stat-card admin-asn-summary-btn h-100 text-start w-100"
          :class="{ 'admin-asn-summary-btn--active': statusFilter === card.status }"
          @click="setStatusCard(card.status)"
        >
          <p class="staff-stat-card__label">{{ card.label }}</p>
          <p class="staff-stat-card__value">{{ statCardValue(card.key) }}</p>
          <p class="staff-stat-card__sub">{{ card.sub }}</p>
          <div class="staff-stat-card__icon" :style="card.iconStyle" aria-hidden="true">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path :d="card.iconPath" />
            </svg>
          </div>
        </button>
      </div>
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
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
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
              <th class="staff-table-head__th text-center" scope="col">Account</th>
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
                <td class="text-center">
                  <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(row.status)">
                    {{ statusLabel(row.status) }}
                  </span>
                </td>
                <td class="text-center fw-semibold admin-asn-list-asn-col">
                  {{ formatAsnDisplay(row.asn_number) }}
                </td>
                <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) }}</td>
                <td class="text-center small text-secondary">{{ row.client_account_company_name || "—" }}</td>
                <td class="text-center">{{ Number(row.expected_qty ?? 0).toLocaleString() }}</td>
                <td class="text-center">{{ Number(row.accepted_qty ?? 0).toLocaleString() }}</td>
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
          v-if="actionMenuOpen"
          data-asn-hub-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{
            top: `${actionMenuRect.top}px`,
            left: `${actionMenuRect.left}px`,
          }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openScanFromAction">
            Scan Items
          </button>
        </div>
      </Transition>
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
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="scanOpen"
      title="Scan Items"
      confirm-label="Save"
      :danger="false"
      :busy="scanBusy"
      @close="scanOpen = false"
      @confirm="submitScan"
    >
      <label class="form-label" for="admin-asn-scan-asn-number">ASN #</label>
      <input
        id="admin-asn-scan-asn-number"
        v-model="scanAsnNumber"
        type="text"
        class="form-control mb-3"
        placeholder="e.g. 0010"
        autocomplete="off"
      />
      <label class="form-label" for="admin-asn-scan-barcodes">Enter barcodes line by line</label>
      <textarea
        id="admin-asn-scan-barcodes"
        v-model="scanText"
        class="form-control font-monospace"
        rows="10"
      />
    </ConfirmModal>

    <ConfirmModal
      :open="createModalOpen"
      title="Create ASN"
      confirm-label="Continue"
      :busy="createBusy"
      @close="createModalOpen = false"
      @confirm="confirmCreate"
    >
      <label class="form-label">Account</label>
      <CrmSearchableSelect
        v-model="createAccountId"
        appearance="staff"
        :options="accountOptions"
        placeholder="Select account…"
        :allow-empty="false"
        search-placeholder="Search accounts…"
      />
    </ConfirmModal>

    <ConfirmModal
      :open="nonCompliantOpen"
      title="Non-Compliant ASN"
      confirm-label="Create"
      :busy="nonCompliantBusy"
      @close="nonCompliantOpen = false"
      @confirm="submitNonCompliant"
    >
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Account</label>
          <CrmSearchableSelect
            v-model="ncAccountId"
            appearance="staff"
            :options="accountOptions"
            placeholder="Select account…"
            :allow-empty="false"
            search-placeholder="Search accounts…"
          />
        </div>
        <div class="col-6">
          <label class="form-label">Boxes</label>
          <input v-model.number="ncBoxes" type="number" min="0" class="form-control" />
        </div>
        <div class="col-6">
          <label class="form-label">Pallets</label>
          <input v-model.number="ncPallets" type="number" min="0" class="form-control" />
        </div>
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label mb-0">Tracking</label>
            <button type="button" class="btn btn-link btn-sm p-0" @click="addNcTracking">Add Row</button>
          </div>
          <div v-for="(t, i) in ncTrackings" :key="i" class="row g-2 mb-2">
            <div class="col-5">
              <select v-model="t.carrier" class="form-select form-select-sm">
                <option value="">Carrier</option>
                <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="col-7">
              <input
                v-model="t.tracking_number"
                type="text"
                class="form-control form-control-sm"
                placeholder="Tracking #"
              />
            </div>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label">Non-Compliant Fee</label>
          <input v-model="ncFee" type="number" min="0" step="0.01" class="form-control" placeholder="0.00" />
          <p class="form-text mb-0">If greater than zero, a custom bill line is created automatically.</p>
        </div>
      </div>
    </ConfirmModal>
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.admin-asn-summary-btn {
  cursor: pointer;
  font: inherit;
  color: inherit;
  border: 0;
  box-shadow: 0 2px 10px rgba(47, 43, 61, 0.06);
  transition:
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

[data-bs-theme="dark"] .admin-asn-summary-btn {
  box-shadow: 0 2px 14px rgba(0, 0, 0, 0.22);
}

.admin-asn-summary-btn:hover {
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.1);
  transform: translateY(-1px);
}

.admin-asn-summary-btn--active {
  box-shadow:
    0 0 0 2px rgba(115, 103, 240, 0.35),
    0 0.45rem 1rem rgba(47, 43, 61, 0.1);
}

[data-bs-theme="dark"] .admin-asn-summary-btn:hover,
[data-bs-theme="dark"] .admin-asn-summary-btn--active {
  box-shadow:
    0 0 0 2px rgba(186, 175, 255, 0.35),
    0 0.5rem 1.15rem rgba(0, 0, 0, 0.28);
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
