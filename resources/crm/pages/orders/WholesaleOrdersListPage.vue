<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import WholesaleOrderCreateDrawer from "../../components/orders/WholesaleOrderCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { crmIsPortalUser } from "../../utils/crmUser.js";
import {
  WHOLESALE_STATUS_OPTIONS,
  WHOLESALE_TYPE_OPTIONS,
  wholesaleStatusBadgeClass,
  wholesaleStatusLabel,
  wholesaleTypeLabel,
} from "../../utils/formatWholesaleOrderDisplay.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();
const crmUser = inject("crmUser", ref(null));

const isPortal = computed(
  () => Boolean(route.meta?.userPortal) || crmIsPortalUser(crmUser.value),
);
const detailRouteName = computed(() =>
  isPortal.value ? "user-wholesale-order-detail" : "wholesale-order-detail",
);

const accounts = ref([]);
const accountsLoading = ref(false);
const accountFilter = ref("");
const orderNumber = ref("");
const statusFilter = ref("");
const typeFilter = ref("");
const loading = ref(true);
const results = ref([]);
const filterMenuOpen = ref(false);

const createOpen = ref(false);
const createBusy = ref(false);
const createAccountId = ref("");
const createOrderType = ref("");
const createOrderNumber = ref("");
const createInstructions = ref("");

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 180;
const MENU_H = 100;
const deleteConfirmOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);

const tableColspan = computed(() => (isPortal.value ? 6 : 7));

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || a.label || `Account #${a.id}`,
    email: a.email ? String(a.email) : "",
  })),
);

const pickListRoute = computed(() => {
  const query = accountFilter.value ? { client_account_id: String(accountFilter.value) } : {};
  return { name: "wholesale-pick-list", query };
});

const manageMenuRow = computed(
  () => results.value.find((r) => r.id === manageOpenId.value) ?? null,
);

function canDeleteRow(row) {
  if (isPortal.value) return false;
  const status = String(row?.status || "").toLowerCase();
  return status === "draft" || status === "pending";
}

function onDocClickFilter(e) {
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-wholesale-row-actions]")) {
    manageOpenId.value = null;
  }
}

function resetToolbarFilters() {
  statusFilter.value = "";
  typeFilter.value = "";
  filterMenuOpen.value = false;
  loadList();
}

async function loadAccounts() {
  if (isPortal.value) return;
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts)
      ? data.accounts
      : Array.isArray(data?.data)
        ? data.data
        : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function loadList() {
  loading.value = true;
  try {
    const params = {};
    if (!isPortal.value && accountFilter.value) {
      params.client_account_id = Number(accountFilter.value);
    }
    if (orderNumber.value.trim()) params.q = orderNumber.value.trim();
    if (statusFilter.value) params.status = statusFilter.value;
    if (typeFilter.value) params.order_type = typeFilter.value;
    const { data } = await api.get("/admin/wholesale-orders", { params });
    results.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale orders.");
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  router.push({ name: detailRouteName.value, params: { id: String(row.id) } });
}

function placeManageMenu(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(row, e) {
  e?.stopPropagation?.();
  const id = row?.id;
  if (manageOpenId.value === id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function openCreate() {
  createAccountId.value = isPortal.value
    ? String(crmUser.value?.client_account_id || "")
    : accountFilter.value || "";
  createOrderType.value = "";
  createOrderNumber.value = "";
  createInstructions.value = "";
  createOpen.value = true;
}

async function submitCreate() {
  const accountId = Number(
    isPortal.value ? crmUser.value?.client_account_id : createAccountId.value,
  );
  if (!isPortal.value && !accountId) {
    toast.error("Select an account.");
    return;
  }
  if (!createOrderType.value) {
    toast.error("Select a type.");
    return;
  }
  const num = createOrderNumber.value.trim();
  if (!num) {
    toast.error("Enter an order number.");
    return;
  }
  createBusy.value = true;
  try {
    const body = {
      order_type: createOrderType.value,
      order_number: num,
      instructions: createInstructions.value.trim() || null,
    };
    if (!isPortal.value) {
      body.client_account_id = accountId;
    }
    const { data } = await api.post("/admin/wholesale-orders", body);
    createOpen.value = false;
    toast.success(isPortal.value ? "Wholesale order submitted." : "Wholesale order created.");
    if (data?.id) {
      router.push({ name: detailRouteName.value, params: { id: String(data.id) } });
    } else {
      await loadList();
    }
  } catch (e) {
    toast.errorFrom(e, isPortal.value ? "Could not submit wholesale order." : "Could not create wholesale order.");
  } finally {
    createBusy.value = false;
  }
}

function openDeleteConfirm(row) {
  manageOpenId.value = null;
  deleteTarget.value = row;
  deleteConfirmOpen.value = true;
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row?.id || deleteBusy.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/admin/wholesale-orders/${row.id}`);
    toast.success("Wholesale order deleted.");
    deleteConfirmOpen.value = false;
    deleteTarget.value = null;
    await loadList();
  } catch (e) {
    toast.errorFrom(e, "Could not delete wholesale order.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Wholesale Orders",
    description: isPortal.value
      ? "Your wholesale fulfillment orders."
      : "Manage wholesale fulfillment orders.",
  });
  document.addEventListener("click", onDocClickFilter);
  loadAccounts();
  loadList();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickFilter);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Wholesale</h1>
        <p class="small text-secondary mb-0">
          {{
            isPortal
              ? "Submit and track wholesale fulfillment orders."
              : "Wholesale fulfillment orders by account."
          }}
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <RouterLink
          v-if="!isPortal"
          :to="pickListRoute"
          class="btn btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
        >
          Pick List
        </RouterLink>
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold"
          @click="openCreate"
        >
          {{ isPortal ? "Submit Order" : "Create Order" }}
        </button>
      </div>
    </div>

    <div
      class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 wholesale-orders-page-toolbar"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row wholesale-orders-toolbar-row">
          <div v-if="!isPortal" class="wholesale-orders-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              button-id="wholesale-orders-account-trigger"
            />
          </div>

          <div class="wholesale-orders-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                id="wholesale-orders-order-search"
                v-model.trim="orderNumber"
                type="search"
                class="form-control"
                placeholder="Search by Order #"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Order number"
                :disabled="loading"
                @keydown.enter.prevent="loadList"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                :disabled="loading"
                @click="loadList"
              >
                Search
              </button>
            </div>
          </div>

          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading"
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
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Wholesale order filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetToolbarFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="wholesale-filter-status">Status</label>
                <select
                  id="wholesale-filter-status"
                  v-model="statusFilter"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="loadList"
                >
                  <option
                    v-for="opt in WHOLESALE_STATUS_OPTIONS"
                    :key="opt.value || 'all-statuses'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
                <label class="form-label" for="wholesale-filter-type">Type</label>
                <select
                  id="wholesale-filter-type"
                  v-model="typeFilter"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="loadList"
                >
                  <option
                    v-for="opt in WHOLESALE_TYPE_OPTIONS"
                    :key="opt.value || 'all-types'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Type</th>
              <th class="staff-table-head__th text-center" scope="col">Items</th>
              <th v-if="!isPortal" class="staff-table-head__th text-center" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">Date</th>
              <th class="staff-table-head__th text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading wholesale orders…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!results.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">No wholesale orders found.</td>
            </tr>
            <tr
              v-for="row in results"
              v-else
              :key="row.id"
              class="align-middle wholesale-orders-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="wholesaleStatusBadgeClass(row.status)">
                  {{ row.status_label || wholesaleStatusLabel(row.status) }}
                </span>
              </td>
              <td class="text-center fw-semibold">{{ row.order_number || "—" }}</td>
              <td class="text-center">{{ row.order_type_label || wholesaleTypeLabel(row.order_type) }}</td>
              <td class="text-center">{{ row.items_count ?? "—" }}</td>
              <td v-if="!isPortal" class="text-center">{{ row.client_account_company_name || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) || "—" }}</td>
              <td class="text-center staff-actions-cell" @click.stop>
                <div
                  data-wholesale-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-wholesale-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openRow(manageMenuRow)">
          View
        </button>
        <button
          v-if="canDeleteRow(manageMenuRow)"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteConfirm(manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <ConfirmModal
      :open="deleteConfirmOpen"
      title="Delete Wholesale Order"
      :message="
        deleteTarget
          ? `Delete order ${deleteTarget.order_number || '#' + deleteTarget.id}? This cannot be undone.`
          : 'Delete this wholesale order?'
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      :danger="true"
      @close="deleteConfirmOpen = false"
      @confirm="confirmDelete"
    />

    <WholesaleOrderCreateDrawer
      v-model:open="createOpen"
      v-model:account-id="createAccountId"
      v-model:order-type="createOrderType"
      v-model:order-number="createOrderNumber"
      v-model:instructions="createInstructions"
      :account-options="accountOptions"
      :busy="createBusy"
      :portal="isPortal"
      @submit="submitCreate"
    />
  </div>
</template>

<style scoped>
.wholesale-orders-page-toolbar .staff-table-toolbar--row.wholesale-orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.wholesale-orders-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.wholesale-orders-search-wrap {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}

.wholesale-orders-result-row {
  cursor: pointer;
}
</style>
