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

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });
const clientAccounts = ref([]);
const createDrawerOpen = ref(false);
const filterMenuOpen = ref(false);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 160;
const MENU_H = 132;

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

const editDateModalOpen = ref(false);
const editDateTarget = ref(null);
const editDateValue = ref("");
const editDateBusy = ref(false);

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

let searchDebounce = null;
watch(
  () => query.search,
  () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      fetchRows();
    }, 280);
  },
);

watch(
  () => [query.status, query.client_account_id, query.date_from, query.date_to],
  () => {
    query.page = 1;
    fetchRows();
  },
);

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
}

function goToBillDetail(row) {
  closeManageMenu();
  router.push(`/admin/billing/custom-bills/${row.id}`);
}

function openEditDateModal(row) {
  closeManageMenu();
  editDateTarget.value = row;
  editDateValue.value = row.bill_date || "";
  editDateModalOpen.value = true;
}

function closeEditDateModal() {
  if (editDateBusy.value) return;
  editDateModalOpen.value = false;
  editDateTarget.value = null;
}

async function saveBillDate() {
  if (!editDateTarget.value) return;
  editDateBusy.value = true;
  try {
    await api.patch(`/custom-bills/${editDateTarget.value.id}`, {
      bill_date: editDateValue.value,
    });
    toast.success("Bill date updated.");
    editDateModalOpen.value = false;
    editDateTarget.value = null;
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not update bill date.");
  } finally {
    editDateBusy.value = false;
  }
}

function openDeleteModal(row) {
  closeManageMenu();
  deleteTarget.value = row;
  deleteModalOpen.value = true;
}

async function confirmDelete() {
  if (!deleteTarget.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/custom-bills/${deleteTarget.value.id}`);
    toast.success("Custom bill deleted.");
    deleteModalOpen.value = false;
    deleteTarget.value = null;
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete custom bill.");
  } finally {
    deleteBusy.value = false;
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
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Status</th>
              <th class="staff-table-head__th" scope="col">Bill #</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th text-end" scope="col">Price</th>
              <th
                class="staff-table-head__th staff-actions-col text-center billing-custom-bills-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading custom bills…" />
                </div>
              </td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id" class="align-middle">
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
                  {{ row.bill_number }}
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
              <td class="staff-actions-cell text-center billing-custom-bills-actions-col">
                <div
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
              <td colspan="6" class="px-4 py-5 text-center text-secondary">
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
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="goToBillDetail(manageMenuRow)"
        >
          View
        </button>
        <button
          v-if="canUpdate && manageMenuRow.status === 'open'"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditDateModal(manageMenuRow)"
        >
          Edit
        </button>
        <button
          v-if="canDelete && manageMenuRow.status === 'open'"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteModal(manageMenuRow)"
        >
          Delete
        </button>
        <RouterLink
          v-if="manageMenuRow.status === 'invoiced' && manageMenuRow.invoice_id"
          :to="`/admin/billing/invoices/${manageMenuRow.invoice_id}`"
          class="staff-row-menu__item text-decoration-none text-body"
          role="menuitem"
          @click="closeManageMenu"
        >
          View Invoice
        </RouterLink>
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

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="editDateModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeEditDateModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="editDateBusy"
              @click="closeEditDateModal"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.75"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Edit Bill Date</h2>
            </header>
            <div class="crm-vx-modal__body">
              <form id="cb-list-edit-date-form" @submit.prevent="saveBillDate">
                <label class="form-label" for="cb-list-edit-date">Bill Date</label>
                <input
                  id="cb-list-edit-date"
                  v-model="editDateValue"
                  type="date"
                  class="form-control mb-0"
                  :disabled="editDateBusy"
                  required
                />
              </form>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="editDateBusy"
                @click="closeEditDateModal"
              >
                Cancel
              </button>
              <button
                type="submit"
                form="cb-list-edit-date-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="editDateBusy"
              >
                {{ editDateBusy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
