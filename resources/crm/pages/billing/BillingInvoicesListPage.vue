<script setup>
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import BillingInvoiceCreateDrawer from "../../components/billing/BillingInvoiceCreateDrawer.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatCents } from "../../utils/formatMoney.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("billing.create"));
const canUpdate = computed(() => userHasPerm("billing.update"));
const canDelete = computed(() => userHasPerm("billing.delete"));

const showCheckboxColumn = computed(() => canUpdate.value || canDelete.value);
const tableColspan = computed(() => (showCheckboxColumn.value ? 9 : 8));

const loading = ref(true);
const summaryLoading = ref(true);
const summaryError = ref("");
const summary = ref({
  open_balance_due_cents: 0,
  overdue_invoice_count: 0,
  draft_invoice_count: 0,
  paid_mtd_cents: 0,
  counts_by_status: {},
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const selectedIds = ref([]);
const bulkMenuOpen = ref(false);
const bulkSendOpen = ref(false);
const bulkVoidOpen = ref(false);
const bulkDeleteOpen = ref(false);
const bulkBusy = ref(false);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const meta = ref({ statuses: [], client_accounts: [] });

const query = reactive({
  search: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "issued_at",
  sort_dir: "desc",
  status: "all",
  client_account_id: "",
});

let searchDebounce = null;
watch(
  () => query.search,
  () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      selectedIds.value = [];
      fetchRows();
    }, 280);
  },
);

watch(
  () => [query.status, query.client_account_id],
  () => {
    query.page = 1;
    selectedIds.value = [];
    fetchRows();
  },
);

const isAllPageSelected = computed(
  () =>
    rows.value.length > 0 &&
    rows.value.every((r) => selectedIds.value.includes(r.id)),
);

function toggleRowSelect(id) {
  const i = selectedIds.value.indexOf(id);
  if (i === -1) {
    selectedIds.value = [...selectedIds.value, id];
  } else {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  }
}

function toggleSelectAllPage() {
  if (isAllPageSelected.value) {
    const pageIds = new Set(rows.value.map((r) => r.id));
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.has(id));
  } else {
    const next = new Set(selectedIds.value);
    rows.value.forEach((r) => next.add(r.id));
    selectedIds.value = [...next];
  }
}

function clearSelection() {
  selectedIds.value = [];
}

const filterMenuOpen = ref(false);
const addDrawerOpen = ref(false);
const importModalOpen = ref(false);
const importBusy = ref(false);
const importForm = reactive({
  import_type: "charges",
  client_account_id: "",
  due_at: "",
  invoice_number: "",
  file: null,
});

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 220;
const MENU_H = 280;

const payModalOpen = ref(false);
const payTarget = ref(null);
const payAmount = ref("");
const payBusy = ref(false);

const voidModalOpen = ref(false);
const voidTarget = ref(null);
const voidBusy = ref(false);

const deleteModalOpen = ref(false);
const deleteTarget = ref(null);
const deleteBusy = ref(false);

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

function closeManageMenu() {
  manageOpenId.value = null;
}

async function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

function onDocClick(e) {
  if (!e.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target.closest("[data-toolbar-bulk]")) {
    bulkMenuOpen.value = false;
  }
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
  }
}

function onWindowScrollOrResize() {
  manageOpenId.value = null;
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "paid") return "bg-success-subtle text-success";
  if (s === "draft") return "bg-secondary-subtle text-secondary";
  if (s === "void") return "bg-dark-subtle text-secondary";
  if (s === "partial") return "bg-info-subtle text-info-emphasis";
  if (s === "sent") return "bg-primary-subtle text-primary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (query.sort_by !== column) {
    query.sort_by = column;
    query.sort_dir = "asc";
  } else {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  }
  query.page = 1;
  selectedIds.value = [];
  fetchRows();
}

async function fetchMeta() {
  const { data } = await api.get("/invoices/meta");
  meta.value = {
    statuses: data?.statuses ?? ["all"],
    client_accounts: data?.client_accounts ?? [],
  };
}

async function loadSummary() {
  summaryLoading.value = true;
  summaryError.value = "";
  try {
    const { data } = await api.get("/billing/summary");
    summary.value = {
      open_balance_due_cents: data?.open_balance_due_cents ?? 0,
      overdue_invoice_count: data?.overdue_invoice_count ?? 0,
      draft_invoice_count: data?.draft_invoice_count ?? 0,
      paid_mtd_cents: data?.paid_mtd_cents ?? 0,
      counts_by_status: data?.counts_by_status ?? {},
    };
  } catch (e) {
    summaryError.value =
      e.response?.data?.message || "Could not load billing summary.";
  } finally {
    summaryLoading.value = false;
  }
}

async function fetchRows() {
  loading.value = true;
  try {
    const { data } = await api.get("/invoices", {
      params: {
        search: query.search || undefined,
        per_page: query.per_page,
        page: query.page,
        sort_by: query.sort_by,
        sort_dir: query.sort_dir,
        status: query.status === "all" ? undefined : query.status,
        client_account_id: query.client_account_id || undefined,
      },
    });
    rows.value = data?.data ?? [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? 0,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load invoices.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function dollarsToCents(s) {
  const n = Number.parseFloat(String(s).replace(/,/g, ""));
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100);
}

async function onInvoiceDrawerCreated() {
  toast.success("Invoice created.");
  await loadSummary();
  await fetchRows();
}

function openImportModal() {
  importForm.import_type = "charges";
  importForm.client_account_id = "";
  importForm.due_at = new Date().toISOString().slice(0, 10);
  importForm.invoice_number = "";
  importForm.file = null;
  importModalOpen.value = true;
}

function closeImportModal() {
  if (importBusy.value) return;
  importModalOpen.value = false;
}

function onImportFileChange(event) {
  const input = event?.target;
  const file = input?.files?.[0] ?? null;
  importForm.file = file;
}

async function submitImportCsv() {
  if (!importForm.client_account_id) {
    toast.error("Select a client account.");
    return;
  }
  if (!importForm.due_at) {
    toast.error("Select a due date.");
    return;
  }
  if (!importForm.file) {
    toast.error("Choose a CSV file.");
    return;
  }

  importBusy.value = true;
  try {
    const formData = new FormData();
    formData.append("due_at", importForm.due_at);
    formData.append("file", importForm.file);
    const invNum = String(importForm.invoice_number || "").trim();
    if (invNum) {
      formData.append("invoice_number", invNum);
    }

    const accountId = encodeURIComponent(String(importForm.client_account_id));
    const endpoint =
      importForm.import_type === "storage"
        ? `/client-accounts/${accountId}/invoice-imports/storage`
        : `/client-accounts/${accountId}/invoice-imports/charges`;

    const { data } = await api.post(endpoint, formData);
    const newInvoiceId = data?.invoice?.id;
    if (!newInvoiceId) {
      throw new Error("Missing invoice id.");
    }

    toast.success("Invoice imported.");
    closeImportModal();
    await loadSummary();
    await fetchRows();
    router.push(`/billing/invoices/${newInvoiceId}`);
  } catch (e) {
    toast.errorFrom(e, "Could not import CSV.");
  } finally {
    importBusy.value = false;
  }
}

function openPayModal(row) {
  manageOpenId.value = null;
  payTarget.value = row;
  payAmount.value = row.balance_due_cents
    ? (Number(row.balance_due_cents) / 100).toFixed(2)
    : "";
  payModalOpen.value = true;
}

function closePayModal() {
  if (payBusy.value) return;
  payModalOpen.value = false;
  payTarget.value = null;
}

async function confirmPay() {
  const row = payTarget.value;
  if (!row) return;
  const cents = dollarsToCents(payAmount.value);
  if (cents < 1) {
    toast.error("Enter a valid payment amount.");
    return;
  }
  payBusy.value = true;
  try {
    await api.post(`/invoices/${row.id}/record-payment`, {
      amount_cents: cents,
    });
    toast.success("Payment recorded.");
    closePayModal();
    await loadSummary();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not record payment.");
  } finally {
    payBusy.value = false;
  }
}

function openVoidModal(row) {
  manageOpenId.value = null;
  voidTarget.value = row;
  voidModalOpen.value = true;
}

function closeVoidModal() {
  if (voidBusy.value) return;
  voidModalOpen.value = false;
  voidTarget.value = null;
}

async function confirmVoid() {
  const row = voidTarget.value;
  if (!row) return;
  voidBusy.value = true;
  try {
    await api.post(`/invoices/${row.id}/void`);
    toast.success("Invoice voided.");
    closeVoidModal();
    await loadSummary();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not void invoice.");
  } finally {
    voidBusy.value = false;
  }
}

function openDeleteModal(row) {
  manageOpenId.value = null;
  deleteTarget.value = row;
  deleteModalOpen.value = true;
}

function closeDeleteModal() {
  if (deleteBusy.value) return;
  deleteModalOpen.value = false;
  deleteTarget.value = null;
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/invoices/${row.id}`);
    toast.success("Invoice deleted.");
    closeDeleteModal();
    await loadSummary();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete invoice.");
  } finally {
    deleteBusy.value = false;
  }
}

async function sendInvoice(row) {
  manageOpenId.value = null;
  try {
    await api.post(`/invoices/${row.id}/send`);
    toast.success("Invoice sent.");
    await loadSummary();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not send invoice.");
  }
}

function closeBulkSend() {
  if (bulkBusy.value) return;
  bulkSendOpen.value = false;
}

function closeBulkVoid() {
  if (bulkBusy.value) return;
  bulkVoidOpen.value = false;
}

function closeBulkDelete() {
  if (bulkBusy.value) return;
  bulkDeleteOpen.value = false;
}

const bulkSendMessage = computed(() => {
  const n = selectedIds.value.length;
  return n
    ? `Send ${n} selected invoice${n === 1 ? "" : "s"}? Only drafts will be sent successfully; other statuses are skipped.`
    : "";
});

const bulkVoidMessage = computed(() => {
  const n = selectedIds.value.length;
  return n
    ? `Void ${n} selected invoice${n === 1 ? "" : "s"}? Invoices with payments recorded cannot be voided.`
    : "";
});

const bulkDeleteMessage = computed(() => {
  const n = selectedIds.value.length;
  return n
    ? `Delete ${n} selected draft invoice${n === 1 ? "" : "s"}? This cannot be undone. Non-drafts are skipped.`
    : "";
});

async function confirmBulkSend() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkBusy.value = true;
  let ok = 0;
  let fail = 0;
  for (const id of ids) {
    try {
      await api.post(`/invoices/${id}/send`);
      ok++;
    } catch {
      fail++;
    }
  }
  bulkSendOpen.value = false;
  selectedIds.value = [];
  await loadSummary();
  await fetchRows();
  bulkBusy.value = false;
  if (ok && !fail) {
    toast.success(`Sent ${ok} invoice${ok === 1 ? "" : "s"}.`);
  } else if (ok) {
    toast.success(`Sent ${ok}; ${fail} skipped or failed.`);
  } else {
    toast.error("No invoices were sent. Select drafts you can send.");
  }
}

async function confirmBulkVoid() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkBusy.value = true;
  let ok = 0;
  let fail = 0;
  for (const id of ids) {
    try {
      await api.post(`/invoices/${id}/void`);
      ok++;
    } catch {
      fail++;
    }
  }
  bulkVoidOpen.value = false;
  selectedIds.value = [];
  await loadSummary();
  await fetchRows();
  bulkBusy.value = false;
  if (ok && !fail) {
    toast.success(`Voided ${ok} invoice${ok === 1 ? "" : "s"}.`);
  } else if (ok) {
    toast.success(`Voided ${ok}; ${fail} skipped or failed.`);
  } else {
    toast.error("No invoices were voided. Check status and payments.");
  }
}

async function confirmBulkDelete() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkBusy.value = true;
  let ok = 0;
  let fail = 0;
  for (const id of ids) {
    try {
      await api.delete(`/invoices/${id}`);
      ok++;
    } catch {
      fail++;
    }
  }
  bulkDeleteOpen.value = false;
  selectedIds.value = [];
  await loadSummary();
  await fetchRows();
  bulkBusy.value = false;
  if (ok && !fail) {
    toast.success(`Deleted ${ok} draft${ok === 1 ? "" : "s"}.`);
  } else if (ok) {
    toast.success(`Deleted ${ok}; ${fail} skipped or failed.`);
  } else {
    toast.error("No invoices were deleted. Only drafts can be deleted.");
  }
}

const showingFrom = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return Math.min(pagination.value.current_page * query.per_page, t);
});

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  setCrmPageMeta({
    title: "Save Rack | Invoices",
    description: "Client invoices and balances.",
  });
  try {
    await Promise.all([fetchMeta(), loadSummary()]);
  } catch {
    /* toast on list fetch */
  }
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-invoices-page">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Invoices</h1>
        <p class="text-secondary small mb-0">
          Fulfillment billing — search, filter, and manage client invoices
        </p>
      </div>
      <div v-if="canCreate" class="d-flex flex-wrap gap-2 ms-md-auto">
        <button
          type="button"
          class="btn btn-outline-secondary flex-shrink-0"
          @click="openImportModal"
        >
          Import CSV
        </button>
        <button
          type="button"
          class="btn btn-primary staff-page-primary flex-shrink-0"
          @click="addDrawerOpen = true"
        >
          Add Invoice
        </button>
      </div>
    </div>

    <div v-if="summaryError" class="alert alert-warning mb-3" role="alert">
      {{ summaryError }}
    </div>

    <div v-if="summaryLoading" class="d-flex justify-content-center py-4 mb-2">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>
    <div v-else class="row g-4 mb-4">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Open balance due</p>
          <p class="staff-stat-card__value">
            {{ formatCents(summary.open_balance_due_cents) }}
          </p>
          <p class="staff-stat-card__sub">Sent and partial — unpaid total</p>
          <div
            class="staff-stat-card__icon text-white"
            style="background: #2563eb"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Overdue invoices</p>
          <p class="staff-stat-card__value">
            {{ nf.format(summary.overdue_invoice_count) }}
          </p>
          <p class="staff-stat-card__sub">Past due date with balance</p>
          <div
            class="staff-stat-card__icon bg-warning-subtle text-warning-emphasis"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Draft invoices</p>
          <p class="staff-stat-card__value">
            {{ nf.format(summary.draft_invoice_count) }}
          </p>
          <p class="staff-stat-card__sub">Not yet sent</p>
          <div
            class="staff-stat-card__icon bg-secondary-subtle text-secondary"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Paid (month to date)</p>
          <p class="staff-stat-card__value">
            {{ formatCents(summary.paid_mtd_cents) }}
          </p>
          <p class="staff-stat-card__sub">Recorded payments this month</p>
          <div
            class="staff-stat-card__icon bg-success-subtle text-success"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="inv-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search invoice # or client"
            autocomplete="off"
            @keydown.enter.prevent="fetchRows"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              @click.stop="
                bulkMenuOpen = false;
                filterMenuOpen = !filterMenuOpen;
              "
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
              aria-label="Invoice filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="
                    query.status = 'all';
                    query.client_account_id = '';
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="inv-filter-status">Status</label>
                <select
                  id="inv-filter-status"
                  v-model="query.status"
                  class="form-select staff-datatable-filters__select mb-3"
                >
                  <option
                    v-for="st in meta.statuses"
                    :key="st"
                    :value="st"
                  >
                    {{ st === "all" ? "All statuses" : st }}
                  </option>
                </select>
                <label class="form-label" for="inv-filter-client">Client</label>
                <CrmSearchableSelect
                  v-model="query.client_account_id"
                  appearance="staff"
                  aria-label="Client"
                  :options="meta.client_accounts"
                  placeholder="All clients"
                  search-placeholder="Search clients…"
                  empty-label="All clients"
                  button-id="inv-filter-client"
                />
              </div>
            </div>
          </div>
          <div
            v-if="showCheckboxColumn"
            class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
          >
            <div class="d-none d-md-flex align-items-center gap-2 flex-shrink-0">
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn"
                :disabled="!selectedIds.length || loading || bulkBusy"
                @click="bulkSendOpen = true"
              >
                Bulk Send
              </button>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn"
                :disabled="!selectedIds.length || loading || bulkBusy"
                @click="bulkVoidOpen = true"
              >
                Bulk Void
              </button>
              <button
                v-if="canDelete"
                type="button"
                class="btn btn-outline-danger staff-toolbar-btn"
                :disabled="!selectedIds.length || loading || bulkBusy"
                @click="bulkDeleteOpen = true"
              >
                Bulk Delete
              </button>
            </div>
            <div
              v-if="canUpdate && canDelete"
              class="d-md-none position-relative flex-shrink-0"
              data-toolbar-bulk
            >
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-1"
                :aria-expanded="bulkMenuOpen"
                aria-haspopup="true"
                :disabled="loading || bulkBusy"
                @click.stop="
                  filterMenuOpen = false;
                  bulkMenuOpen = !bulkMenuOpen;
                "
              >
                Bulk Actions
                <svg
                  width="14"
                  height="14"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  class="text-secondary"
                  aria-hidden="true"
                >
                  <path d="M7 10l5 5 5-5H7z" />
                </svg>
              </button>
              <div
                v-if="bulkMenuOpen"
                class="dropdown-menu show shadow border px-0 py-1 mt-1 staff-toolbar-bulk-dropdown"
                style="right: 0; left: auto"
                role="menu"
                aria-label="Bulk actions"
                @click.stop
              >
                <button
                  type="button"
                  class="dropdown-item small"
                  role="menuitem"
                  :disabled="!selectedIds.length || loading || bulkBusy"
                  @click="
                    bulkMenuOpen = false;
                    bulkSendOpen = true;
                  "
                >
                  Bulk Send
                </button>
                <button
                  type="button"
                  class="dropdown-item small"
                  role="menuitem"
                  :disabled="!selectedIds.length || loading || bulkBusy"
                  @click="
                    bulkMenuOpen = false;
                    bulkVoidOpen = true;
                  "
                >
                  Bulk Void
                </button>
                <button
                  type="button"
                  class="dropdown-item small text-danger"
                  role="menuitem"
                  :disabled="!selectedIds.length || loading || bulkBusy"
                  @click="
                    bulkMenuOpen = false;
                    bulkDeleteOpen = true;
                  "
                >
                  Bulk Delete
                </button>
              </div>
            </div>
            <button
              v-if="canUpdate && !canDelete"
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-md-none flex-shrink-0"
              :disabled="!selectedIds.length || loading || bulkBusy"
              @click="bulkSendOpen = true"
            >
              Bulk Send
            </button>
            <button
              v-if="canUpdate && !canDelete"
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-md-none flex-shrink-0"
              :disabled="!selectedIds.length || loading || bulkBusy"
              @click="bulkVoidOpen = true"
            >
              Bulk Void
            </button>
            <button
              v-if="canDelete && !canUpdate"
              type="button"
              class="btn btn-outline-danger staff-toolbar-btn d-md-none flex-shrink-0"
              :disabled="!selectedIds.length || loading || bulkBusy"
              @click="bulkDeleteOpen = true"
            >
              Bulk Delete
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                v-if="showCheckboxColumn"
                class="staff-table-head__th staff-table-head__th--select"
                scope="col"
              >
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAllPage"
                />
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{
                    sortIndicator("status")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  @click="toggleSort('invoice_number')"
                >
                  Invoice #
                  <span v-if="sortIndicator('invoice_number')" class="staff-sort-ind">{{
                    sortIndicator("invoice_number")
                  }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Client</th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  @click="toggleSort('total_cents')"
                >
                  Total
                  <span v-if="sortIndicator('total_cents')" class="staff-sort-ind">{{
                    sortIndicator("total_cents")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  @click="toggleSort('issued_at')"
                >
                  Issued
                  <span v-if="sortIndicator('issued_at')" class="staff-sort-ind">{{
                    sortIndicator("issued_at")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button type="button" class="staff-sort-btn" @click="toggleSort('due_at')">
                  Due
                  <span v-if="sortIndicator('due_at')" class="staff-sort-ind">{{
                    sortIndicator("due_at")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  @click="toggleSort('balance_due_cents')"
                >
                  Balance
                  <span                    v-if="sortIndicator('balance_due_cents')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("balance_due_cents") }}</span
                  >
                </button>
              </th>
              <th
                class="staff-table-head__th staff-actions-col text-center billing-invoices-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading invoices…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle"
            >
              <td v-if="showCheckboxColumn" class="staff-table-cell--tight-check">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select invoice ${row.invoice_number}`"
                  @change="toggleRowSelect(row.id)"
                />
              </td>
              <td>
                <span class="d-flex flex-wrap align-items-center gap-1">
                  <span
                    class="badge rounded-pill text-capitalize fw-medium"
                    :class="statusBadgeClass(row.status)"
                  >
                    {{ row.status }}
                  </span>
                  <span
                    v-if="row.is_overdue"
                    class="badge rounded-pill bg-danger-subtle text-danger-emphasis small"
                  >
                    Overdue
                  </span>
                </span>
              </td>
              <td class="fw-medium text-body">
                <RouterLink
                  :to="{ name: 'billing-invoice-detail', params: { id: String(row.id) } }"
                  class="text-decoration-none text-body billing-inv-row-link"
                >
                  {{ row.invoice_number }}
                </RouterLink>
              </td>
              <td class="text-secondary staff-table-cell__meta">
                <RouterLink
                  v-if="row.client_company_name"
                  :to="{ name: 'billing-invoice-detail', params: { id: String(row.id) } }"
                  class="text-decoration-none text-secondary billing-inv-row-link"
                >
                  {{ row.client_company_name }}
                </RouterLink>
                <span v-else>—</span>
              </td>
              <td class="text-body staff-table-cell__meta">
                {{ formatCents(row.total_cents, row.currency) }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ row.issued_at ? new Date(row.issued_at).toLocaleDateString() : "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ row.due_at ? new Date(row.due_at).toLocaleDateString() : "—" }}
              </td>
              <td class="text-body staff-table-cell__meta">
                {{ formatCents(row.balance_due_cents, row.currency) }}
              </td>
              <td class="staff-actions-cell text-center billing-invoices-actions-cell">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single billing-invoices-actions-inner"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No invoices found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="!loading && pagination.total > 0"
        class="staff-table-footer card-footer d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-between gap-3"
      >
        <div class="d-flex align-items-center gap-2 order-2 order-lg-1">
          <span class="small text-secondary">
            Showing {{ showingFrom }}–{{ showingTo }} of
            {{ pagination.total }}
          </span>
          <select
            v-model.number="query.per_page"
            class="form-select form-select-sm staff-table-footer-per-page"
            aria-label="Rows per page"
            @change="
              query.page = 1;
              selectedIds = [];
              fetchRows();
            "
          >
            <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
              {{ n }} / page
            </option>
          </select>
        </div>
        <nav
          v-if="pagination.last_page > 1"
          class="order-1 order-lg-2 flex-shrink-0"
          aria-label="Invoice list pagination"
        >
          <div class="d-flex justify-content-center">
            <div class="staff-page-pager staff-page-pager--cluster">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="pagination.current_page <= 1"
                aria-label="Previous page"
                @click="
                  query.page = pagination.current_page - 1;
                  clearSelection();
                  fetchRows();
                "
              >
                ‹
              </button>
              <span class="small text-secondary px-1">
                {{ pagination.current_page }} / {{ pagination.last_page }}
              </span>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="pagination.current_page >= pagination.last_page"
                aria-label="Next page"
                @click="
                  query.page = pagination.current_page + 1;
                  clearSelection();
                  fetchRows();
                "
              >
                ›
              </button>
            </div>
          </div>
        </nav>
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
          <RouterLink
            class="staff-row-menu__item text-decoration-none text-body"
            role="menuitem"
            :to="`/billing/invoices/${manageMenuRow.id}`"
            @click="closeManageMenu"
          >
            View
          </RouterLink>
          <button
            v-if="canUpdate && manageMenuRow.status === 'draft'"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="sendInvoice(manageMenuRow)"
          >
            Send Invoice
          </button>
          <button
            v-if="
              canUpdate &&
              manageMenuRow.status !== 'draft' &&
              manageMenuRow.status !== 'paid' &&
              manageMenuRow.status !== 'void' &&
              manageMenuRow.balance_due_cents > 0
            "
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openPayModal(manageMenuRow)"
          >
            Record Payment
          </button>
          <button
            v-if="canUpdate && manageMenuRow.status !== 'draft' && manageMenuRow.status !== 'void'"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openVoidModal(manageMenuRow)"
          >
            Void Invoice
          </button>
          <button
            v-if="canDelete && manageMenuRow.status === 'draft'"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteModal(manageMenuRow)"
          >
            Delete Draft
          </button>
        </div>
      </Transition>
    </Teleport>

    <BillingInvoiceCreateDrawer
      v-model:open="addDrawerOpen"
      :client-accounts="meta.client_accounts"
      @created="onInvoiceDrawerCreated"
      @refresh-meta="fetchMeta"
    />

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="importModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeImportModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head border-bottom">
              <h2 class="crm-vx-modal__title mb-0">Import Invoice CSV</h2>
            </header>
            <div class="crm-vx-modal__body">
              <div class="mb-3">
                <label class="form-label" for="billing-import-type">Import Type</label>
                <select id="billing-import-type" v-model="importForm.import_type" class="form-select">
                  <option value="charges">Charge CSV</option>
                  <option value="storage">Storage CSV</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="billing-import-client">Client Account</label>
                <CrmSearchableSelect
                  v-model="importForm.client_account_id"
                  appearance="staff"
                  :options="meta.client_accounts"
                  placeholder="Select client account"
                  search-placeholder="Search clients…"
                  empty-label="No client account selected"
                  button-id="billing-import-client"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="billing-import-due">Due Date</label>
                <input
                  id="billing-import-due"
                  v-model="importForm.due_at"
                  type="date"
                  class="form-control"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="billing-import-number">Invoice # (Optional)</label>
                <input
                  id="billing-import-number"
                  v-model="importForm.invoice_number"
                  type="text"
                  class="form-control"
                  placeholder="00001"
                />
              </div>
              <div>
                <label class="form-label" for="billing-import-file">CSV File</label>
                <input
                  id="billing-import-file"
                  type="file"
                  class="form-control"
                  accept=".csv,text/csv,text/plain"
                  @change="onImportFileChange"
                />
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="importBusy"
                @click="closeImportModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="importBusy"
                @click="submitImportCsv"
              >
                {{ importBusy ? "Importing…" : "Import CSV" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="payModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closePayModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head border-bottom">
              <h2 class="crm-vx-modal__title mb-0">Record Payment</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Amount (USD)</label>
              <input v-model="payAmount" type="text" class="form-control" placeholder="0.00" />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="payBusy"
                @click="closePayModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="payBusy"
                @click="confirmPay"
              >
                {{ payBusy ? "Saving…" : "Record" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="voidModalOpen"
      title="Void Invoice?"
      subtitle="This cannot be undone if the invoice has no payments."
      message="Void this invoice? It will no longer be payable."
      confirm-label="Void"
      cancel-label="Cancel"
      :busy="voidBusy"
      danger
      @close="closeVoidModal"
      @confirm="confirmVoid"
    />

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete Draft?"
      :message="
        deleteTarget
          ? `Delete ${deleteTarget.invoice_number}? This cannot be undone.`
          : ''
      "
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      danger
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />

    <ConfirmModal
      :open="bulkSendOpen"
      title="Bulk Send Invoices?"
      :message="bulkSendMessage"
      confirm-label="Send"
      cancel-label="Cancel"
      :busy="bulkBusy"
      :danger="false"
      @close="closeBulkSend"
      @confirm="confirmBulkSend"
    />

    <ConfirmModal
      :open="bulkVoidOpen"
      title="Bulk Void Invoices?"
      :message="bulkVoidMessage"
      confirm-label="Void"
      cancel-label="Cancel"
      :busy="bulkBusy"
      danger
      @close="closeBulkVoid"
      @confirm="confirmBulkVoid"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Bulk Delete Drafts?"
      :message="bulkDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="bulkBusy"
      danger
      @close="closeBulkDelete"
      @confirm="confirmBulkDelete"
    />
  </div>
</template>

