<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import BillingCustomBillCreateDrawer from "../../components/billing/BillingCustomBillCreateDrawer.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";
import { DEFAULT_PER_PAGE } from "../../constants/pagination.js";

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
const showCheckboxColumn = computed(() => canDelete.value || canUpdate.value);

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });
const clientAccounts = ref([]);
const createDrawerOpen = ref(false);
const filterMenuOpen = ref(false);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 160;
const MENU_H = 48;

const selectedIds = ref([]);
const bulkMenuOpen = ref(false);
const bulkDeleteOpen = ref(false);
const bulkAddOpen = ref(false);
const bulkBusy = ref(false);

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

const showingFrom = computed(() => {
  if (!pagination.value.total) return 0;
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1;
});

const showingTo = computed(() =>
  Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total),
);

const deleteTarget = ref(null);
const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const query = reactive({
  search: "",
  status: "all",
  client_account_id: "",
  date_from: "",
  date_to: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "id",
  sort_dir: "desc",
});

const isAllPageSelected = computed(
  () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)),
);

const selectedRows = computed(() => {
  const idSet = new Set(selectedIds.value);
  return rows.value.filter((r) => idSet.has(r.id));
});

const selectedOpenCount = computed(
  () => selectedRows.value.filter((r) => r.status === "open").length,
);

const selectedOpenWithLinesCount = computed(
  () =>
    selectedRows.value.filter(
      (r) => r.status === "open" && Number(r.items_count ?? 0) > 0,
    ).length,
);

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
  () => [query.status, query.client_account_id, query.date_from, query.date_to],
  () => {
    query.page = 1;
    selectedIds.value = [];
    fetchRows();
  },
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

async function fetchMeta() {
  const { data } = await api.get("/invoices/meta");
  clientAccounts.value = Array.isArray(data?.client_accounts) ? data.client_accounts : [];
}

async function fetchRows() {
  loading.value = true;
  try {
    const { data } = await api.get("/custom-bills", {
      params: {
        search: query.search || undefined,
        status: query.status !== "all" ? query.status : undefined,
        client_account_id: query.client_account_id || undefined,
        date_from: query.date_from || undefined,
        date_to: query.date_to || undefined,
        per_page: query.per_page,
        page: query.page,
        sort_by: query.sort_by,
        sort_dir: query.sort_dir,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? 0,
      per_page: data?.per_page ?? query.per_page,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load custom bills.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
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

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    closeManageMenu();
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-toolbar-bulk]")) {
    bulkMenuOpen.value = false;
  }
}

function openDeleteModal(row) {
  closeManageMenu();
  deleteTarget.value = row;
  deleteModalOpen.value = true;
}

async function confirmDelete() {
  if (!deleteTarget.value) return;
  const deletedId = deleteTarget.value.id;
  deleteBusy.value = true;
  try {
    await api.delete(`/custom-bills/${deletedId}`);
    toast.success("Custom bill deleted.");
    deleteModalOpen.value = false;
    deleteTarget.value = null;
    selectedIds.value = selectedIds.value.filter((id) => id !== deletedId);
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete custom bill.");
  } finally {
    deleteBusy.value = false;
  }
}

const bulkDeleteMessage = computed(() => {
  const total = selectedIds.value.length;
  const open = selectedOpenCount.value;
  if (open === total) {
    return `Delete ${open} selected custom bill${open === 1 ? "" : "s"}? This cannot be undone.`;
  }
  return `Delete ${open} open bill${open === 1 ? "" : "s"} from ${total} selected? Invoiced bills will be skipped.`;
});

const bulkAddMessage = computed(() => {
  const total = selectedIds.value.length;
  const eligible = selectedOpenWithLinesCount.value;
  return `Add ${eligible} open bill${eligible === 1 ? "" : "s"} to each account's newest draft invoice? Bills are processed one by one; line items are not combined. ${total - eligible > 0 ? `${total - eligible} selected bill${total - eligible === 1 ? "" : "s"} will be skipped (invoiced or no lines).` : ""}`;
});

async function confirmBulkDelete() {
  const ids = [...selectedIds.value];
  if (!ids.length || bulkBusy.value) return;
  bulkBusy.value = true;
  let deleted = 0;
  let skipped = 0;
  try {
    for (const id of ids) {
      const row = rows.value.find((r) => r.id === id);
      if (!row || row.status !== "open") {
        skipped++;
        continue;
      }
      try {
        await api.delete(`/custom-bills/${id}`);
        deleted++;
      } catch {
        skipped++;
      }
    }
    bulkDeleteOpen.value = false;
    clearSelection();
    await fetchRows();
    if (deleted > 0) {
      const parts = [`Deleted ${deleted} bill${deleted === 1 ? "" : "s"}`];
      if (skipped > 0) parts.push(`${skipped} skipped`);
      toast.success(`${parts.join("; ")}.`);
    } else {
      toast.error("No bills were deleted.");
    }
  } finally {
    bulkBusy.value = false;
  }
}

async function confirmBulkAddToInvoices() {
  const ids = [...selectedIds.value];
  if (!ids.length || bulkBusy.value) return;
  bulkBusy.value = true;
  let added = 0;
  let skipped = 0;
  let failed = 0;
  try {
    for (const id of ids) {
      const row = rows.value.find((r) => r.id === id);
      if (!row || row.status !== "open" || Number(row.items_count ?? 0) <= 0) {
        skipped++;
        continue;
      }
      try {
        const { data: draftData } = await api.get(`/custom-bills/${id}/draft-invoices`);
        const drafts = Array.isArray(draftData?.invoices) ? draftData.invoices : [];
        if (!drafts.length) {
          failed++;
          continue;
        }
        await api.post(`/custom-bills/${id}/add-to-invoice`, {
          invoice_id: Number(drafts[0].id),
        });
        added++;
      } catch {
        failed++;
      }
    }
    bulkAddOpen.value = false;
    clearSelection();
    await fetchRows();
    const parts = [];
    if (added > 0) parts.push(`${added} added to invoice${added === 1 ? "" : "s"}`);
    if (skipped > 0) parts.push(`${skipped} skipped`);
    if (failed > 0) parts.push(`${failed} failed (no draft invoice)`);
    if (added > 0) {
      toast.success(parts.join("; ") + ".");
    } else {
      toast.error(parts.length ? parts.join("; ") + "." : "No bills were added to invoices.");
    }
  } finally {
    bulkBusy.value = false;
  }
}

function onCreated(data) {
  createDrawerOpen.value = false;
  if (data?.id) {
    router.push(`/admin/billing/custom-bills/${data.id}`);
  } else {
    fetchRows();
  }
}

function goToPage(page) {
  query.page = page;
  selectedIds.value = [];
  fetchRows();
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: "Save Rack | Custom Bills",
    description: "Custom bills for client accounts.",
  });
  try {
    await fetchMeta();
  } catch {
    /* optional */
  }
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Custom Bills</h1>
        <p class="text-secondary small mb-0">
          Create bills and add their lines to draft invoices when ready.
        </p>
      </div>
      <div v-if="canCreate" class="ms-md-auto">
        <button
          type="button"
          class="btn btn-primary staff-page-primary"
          @click="createDrawerOpen = true"
        >
          Create Bill
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search bill # or account"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              Filters
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
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
                    query.date_from = '';
                    query.date_to = '';
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label">Status</label>
                <select v-model="query.status" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="open">Open</option>
                  <option value="invoiced">Invoiced</option>
                </select>
                <label class="form-label">Account</label>
                <CrmSearchableSelect
                  v-model="query.client_account_id"
                  appearance="staff"
                  :options="clientAccounts"
                  placeholder="All accounts"
                  search-placeholder="Search accounts…"
                  empty-label="All accounts"
                />
                <label class="form-label mt-3">Date from</label>
                <input v-model="query.date_from" type="date" class="form-control mb-3" />
                <label class="form-label">Date to</label>
                <input v-model="query.date_to" type="date" class="form-control" />
              </div>
            </div>
          </div>
          <div
            v-if="showCheckboxColumn && selectedIds.length"
            class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
          >
            <div class="d-none d-md-flex align-items-center gap-2 flex-shrink-0">
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn"
                :disabled="!selectedOpenWithLinesCount || loading || bulkBusy"
                @click="bulkAddOpen = true"
              >
                Add to Invoices
              </button>
              <button
                v-if="canDelete"
                type="button"
                class="btn btn-outline-danger staff-toolbar-btn"
                :disabled="!selectedOpenCount || loading || bulkBusy"
                @click="bulkDeleteOpen = true"
              >
                Bulk Delete
              </button>
            </div>
            <div
              v-if="canUpdate || canDelete"
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
              </button>
              <div
                v-if="bulkMenuOpen"
                class="dropdown-menu show shadow border px-0 py-1 mt-1 staff-toolbar-bulk-dropdown"
                @click.stop
              >
                <button
                  v-if="canUpdate"
                  type="button"
                  class="dropdown-item"
                  :disabled="!selectedOpenWithLinesCount || loading || bulkBusy"
                  @click="
                    bulkMenuOpen = false;
                    bulkAddOpen = true;
                  "
                >
                  Add to Invoices
                </button>
                <button
                  v-if="canDelete"
                  type="button"
                  class="dropdown-item text-danger"
                  :disabled="!selectedOpenCount || loading || bulkBusy"
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
              type="button"
              class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
              :disabled="bulkBusy"
              @click="clearSelection"
            >
              Clear
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
                class="staff-table-head__th staff-checkbox-col"
                scope="col"
              >
                <input
                  type="checkbox"
                  class="form-check-input"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAllPage"
                />
              </th>
              <th class="staff-table-head__th" scope="col">Status</th>
              <th class="staff-table-head__th" scope="col">Bill #</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th text-end" scope="col">Price</th>
              <th
                v-if="canDelete"
                class="staff-table-head__th staff-actions-col text-center billing-custom-bills-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="showCheckboxColumn ? (canDelete ? 7 : 6) : (canDelete ? 6 : 5)" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading custom bills…" />
                </div>
              </td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id" class="align-middle">
              <td v-if="showCheckboxColumn" class="staff-checkbox-col">
                <input
                  type="checkbox"
                  class="form-check-input"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select bill #${row.bill_number}`"
                  @change="toggleRowSelect(row.id)"
                />
              </td>
              <td>
                <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(row.status)">
                  {{ row.status_label }}
                </span>
              </td>
              <td class="fw-medium text-body">
                <RouterLink
                  :to="`/admin/billing/custom-bills/${row.id}`"
                  class="text-decoration-none text-body"
                >
                  {{ row.display_name || row.bill_number }}
                </RouterLink>
              </td>
              <td class="text-secondary staff-table-cell__meta">
                {{ row.client_account_name || "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatIsoDate(row.bill_date) }}
              </td>
              <td class="text-body staff-table-cell__meta text-end">
                {{ formatCents(row.total_cents) }}
              </td>
              <td
                v-if="canDelete"
                class="staff-actions-cell text-center billing-custom-bills-actions-col"
              >
                <div
                  v-if="row.status === 'open'"
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single"
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
            <tr v-if="!loading && !rows.length">
              <td
                :colspan="showCheckboxColumn ? (canDelete ? 7 : 6) : (canDelete ? 6 : 5)"
                class="px-4 py-5 text-center text-secondary"
              >
                No custom bills found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="!loading && pagination.total > 0"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-3"
      >
        <span class="small text-secondary">
          Showing {{ showingFrom }}–{{ showingTo }} of {{ pagination.total }}
        </span>
        <div
          v-if="pagination.last_page > 1"
          class="d-flex align-items-center justify-content-sm-end gap-2"
        >
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page <= 1"
            @click="goToPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <span class="small text-secondary">
            Page {{ pagination.current_page }} of {{ pagination.last_page }}
          </span>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page >= pagination.last_page"
            @click="goToPage(pagination.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
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
        <button
          v-if="canDelete && manageMenuRow.status === 'open'"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteModal(manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <BillingCustomBillCreateDrawer
      v-model:open="createDrawerOpen"
      :client-accounts="clientAccounts"
      @created="onCreated"
    />

    <ConfirmModal
      v-model:open="deleteModalOpen"
      title="Delete Custom Bill"
      :message="deleteTarget ? `Delete bill #${deleteTarget.bill_number}? This cannot be undone.` : ''"
      confirm-label="Delete"
      variant="danger"
      :busy="deleteBusy"
      @confirm="confirmDelete"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Bulk Delete"
      :message="bulkDeleteMessage"
      confirm-label="Delete"
      variant="danger"
      :busy="bulkBusy"
      @update:open="(v) => { if (!bulkBusy) bulkDeleteOpen = v; }"
      @confirm="confirmBulkDelete"
    />

    <ConfirmModal
      :open="bulkAddOpen"
      title="Add to Invoices"
      :message="bulkAddMessage"
      confirm-label="Add to Invoices"
      variant="primary"
      :busy="bulkBusy"
      @update:open="(v) => { if (!bulkBusy) bulkAddOpen = v; }"
      @confirm="confirmBulkAddToInvoices"
    />
  </div>
</template>
