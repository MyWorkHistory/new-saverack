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
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const categories = ref(["Capsules", "Gummies", "Skin Cream", "Liquids"]);
const products = ref([]);
const accounts = ref([]);
const loading = ref(true);
const saving = ref(false);
const deleteBusy = ref(false);
const filterMenuOpen = ref(false);
const drawerOpen = ref(false);
const editingProduct = ref(null);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const query = reactive({
  search: "",
  client_account_id: "",
  category: "",
  page: 1,
  per_page: DEFAULT_PER_PAGE,
  sort_by: "sku",
  sort_dir: "asc",
});

const form = reactive({
  client_account_id: "",
  sku: "",
  name: "",
  category: "Capsules",
  price_dollars: "",
});

let searchDebounce = null;
let searchWatchLock = false;

const canUpdateInventory = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("inventory.update");
});

const tableColspan = computed(() => (canUpdateInventory.value ? 6 : 5));
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });

const showingFrom = computed(() => {
  const total = pagination.value.total;
  if (total === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const total = pagination.value.total;
  if (total === 0) return 0;
  return Math.min(pagination.value.current_page * query.per_page, total);
});

const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  if (last < 1) return [];
  if (last <= 7) return Array.from({ length: last }, (_, i) => ({ type: "page", value: i + 1 }));

  const items = [{ type: "page", value: 1 }];
  const start = Math.max(2, cur - 1);
  const end = Math.min(last - 1, cur + 1);
  if (start > 2) items.push({ type: "gap" });
  for (let i = start; i <= end; i += 1) items.push({ type: "page", value: i });
  if (end < last - 1) items.push({ type: "gap" });
  items.push({ type: "page", value: last });
  return items;
});

const manageMenuProduct = computed(
  () => products.value.find((product) => product.id === manageOpenId.value) ?? null,
);

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      loadProducts();
    }, 300);
  },
);

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | On-Demand Inventory",
    description: "Account On-Demand SKU catalog.",
  });
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", closeManageMenu, true);
  window.addEventListener("resize", closeManageMenu);
  await Promise.all([loadProducts(), loadAccounts()]);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", closeManageMenu, true);
  window.removeEventListener("resize", closeManageMenu);
  clearTimeout(searchDebounce);
});

async function loadProducts() {
  loading.value = true;
  try {
    const params = {
      page: query.page,
      per_page: query.per_page,
      sort_by: query.sort_by,
      sort_dir: query.sort_dir,
    };
    if (query.search.trim()) params.q = query.search.trim();
    if (query.client_account_id) params.client_account_id = query.client_account_id;
    if (query.category) params.category = query.category;

    const { data } = await api.get("/inventory/on-demand-products", { params });
    products.value = Array.isArray(data?.products) ? data.products : [];
    if (Array.isArray(data?.categories) && data.categories.length) {
      categories.value = data.categories;
    }
    pagination.value = {
      current_page: Number(data?.pagination?.current_page ?? query.page),
      last_page: Number(data?.pagination?.last_page ?? 1),
      per_page: Number(data?.pagination?.per_page ?? query.per_page),
      total: Number(data?.pagination?.total ?? products.value.length),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load On-Demand SKUs.");
  } finally {
    loading.value = false;
  }
}

async function loadAccounts() {
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  }
}

function applySearch() {
  clearTimeout(searchDebounce);
  query.page = 1;
  loadProducts();
}

function clearFilters() {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.client_account_id = "";
  query.category = "";
  query.sort_by = "sku";
  query.sort_dir = "asc";
  query.page = 1;
  loadProducts().finally(() => {
    searchWatchLock = false;
  });
}

function toggleSort(column) {
  if (query.sort_by === column) {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.sort_by = column;
    query.sort_dir = "asc";
  }
  query.page = 1;
  loadProducts();
}

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  return query.sort_by === column
    ? query.sort_dir === "asc"
      ? "ascending"
      : "descending"
    : "none";
}

function goPage(page) {
  if (page < 1 || page > pagination.value.last_page || loading.value) return;
  query.page = page;
  loadProducts();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  loadProducts();
}

function resetForm() {
  form.client_account_id = "";
  form.sku = "";
  form.name = "";
  form.category = categories.value[0] ?? "Capsules";
  form.price_dollars = "";
}

function openCreateDrawer() {
  editingProduct.value = null;
  resetForm();
  drawerOpen.value = true;
}

function openEditDrawer(product) {
  editingProduct.value = product;
  form.client_account_id = String(product.client_account_id ?? "");
  form.sku = product.sku ?? "";
  form.name = product.name ?? "";
  form.category = product.category ?? categories.value[0] ?? "Capsules";
  form.price_dollars = centsToDollars(product.price_cents);
  drawerOpen.value = true;
  closeManageMenu();
}

function closeDrawer() {
  if (!saving.value) drawerOpen.value = false;
}

function centsToDollars(cents) {
  const value = Number(cents);
  return Number.isFinite(value) ? (value / 100).toFixed(2) : "";
}

function dollarsToCents(value) {
  const normalized = String(value ?? "").replace(/[$,\s]/g, "");
  const amount = Number(normalized);
  if (!Number.isFinite(amount)) return null;
  return Math.round(amount * 100);
}

async function saveProduct() {
  const priceCents = dollarsToCents(form.price_dollars);
  if (!form.client_account_id) return toast.error("Select an account.");
  if (!form.sku.trim() || !form.name.trim()) return toast.error("Enter SKU and name.");
  if (!priceCents || priceCents < 1) return toast.error("Enter a positive price.");

  saving.value = true;
  try {
    const payload = {
      client_account_id: Number(form.client_account_id),
      sku: form.sku.trim().toUpperCase(),
      name: form.name.trim(),
      category: form.category,
      price_cents: priceCents,
    };
    if (editingProduct.value?.id) {
      await api.patch(`/inventory/on-demand-products/${editingProduct.value.id}`, payload);
      toast.success("On-Demand SKU updated.");
    } else {
      await api.post("/inventory/on-demand-products", payload);
      toast.success("On-Demand SKU added.");
    }
    drawerOpen.value = false;
    await loadProducts();
  } catch (e) {
    toast.errorFrom(e, "Could not save On-Demand SKU.");
  } finally {
    saving.value = false;
  }
}

function openDeleteModal(product) {
  deleteTarget.value = product;
  closeManageMenu();
}

function closeDeleteModal() {
  if (!deleteBusy.value) deleteTarget.value = null;
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/inventory/on-demand-products/${deleteTarget.value.id}`);
    toast.success("On-Demand SKU deleted.");
    deleteTarget.value = null;
    await loadProducts();
  } catch (e) {
    toast.errorFrom(e, "Could not delete On-Demand SKU.");
  } finally {
    deleteBusy.value = false;
  }
}

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  const width = 180;
  const height = 96;
  let top = rect.bottom + 4;
  let left = rect.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(productId, e) {
  e.stopPropagation();
  if (manageOpenId.value === productId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = productId;
  await nextTick();
  requestAnimationFrame(() => {
    if (manageOpenId.value === productId && btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function onDocClick(e) {
  if (!e.target.closest("[data-toolbar-filter]")) filterMenuOpen.value = false;
  if (!e.target.closest("[data-row-actions]")) closeManageMenu();
}
</script>

<template>
  <div class="staff-page staff-page--wide staff-directory-page">
    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">
          On-Demand Inventory
        </h1>
        <p class="staff-page__intro mb-0">
          Account SKU catalog used during billing imports
        </p>
      </div>
      <button
        v-if="canUpdateInventory"
        type="button"
        class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2 text-nowrap"
        @click="openCreateDrawer"
      >
        <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
        </svg>
        Add SKU
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="on-demand-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search SKU, name, or account"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="on-demand-filter-panel"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              id="on-demand-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Table filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="
                    clearFilters();
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="on-demand-filter-account">Account</label>
                <select
                  id="on-demand-filter-account"
                  v-model="query.client_account_id"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">All accounts</option>
                  <option v-for="account in accounts" :key="account.id" :value="String(account.id)">
                    {{ account.company_name }}
                  </option>
                </select>

                <label class="form-label" for="on-demand-filter-category">Category</label>
                <select
                  id="on-demand-filter-category"
                  v-model="query.category"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">All categories</option>
                  <option v-for="category in categories" :key="category" :value="category">
                    {{ category }}
                  </option>
                </select>
              </div>
            </div>
          </div>
          <div class="staff-toolbar-row-actions d-flex align-items-center gap-2 ms-md-auto">
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="loading"
              @click="loadProducts"
            >
              Refresh
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col" :aria-sort="thAriaSort('sku')">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('sku')">
                  SKU <span v-if="sortIndicator('sku')" class="staff-sort-ind">{{ sortIndicator("sku") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col" :aria-sort="thAriaSort('account')">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('account')">
                  Account <span v-if="sortIndicator('account')" class="staff-sort-ind">{{ sortIndicator("account") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col" :aria-sort="thAriaSort('name')">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('name')">
                  Name <span v-if="sortIndicator('name')" class="staff-sort-ind">{{ sortIndicator("name") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col" :aria-sort="thAriaSort('category')">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('category')">
                  Category <span v-if="sortIndicator('category')" class="staff-sort-ind">{{ sortIndicator("category") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-end" scope="col" :aria-sort="thAriaSort('price_cents')">
                <button type="button" class="staff-sort-btn ms-auto" :disabled="loading" @click="toggleSort('price_cents')">
                  Price <span v-if="sortIndicator('price_cents')" class="staff-sort-ind">{{ sortIndicator("price_cents") }}</span>
                </button>
              </th>
              <th
                v-if="canUpdateInventory"
                class="staff-table-head__th staff-actions-col"
                scope="col"
                aria-sort="none"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading On-Demand SKUs..." />
                </div>
              </td>
            </tr>
            <tr v-for="product in products" v-else :key="product.id" class="align-middle">
              <td class="fw-semibold text-body text-nowrap">{{ product.sku }}</td>
              <td class="text-body staff-table-cell__meta">{{ product.account_name || "—" }}</td>
              <td class="text-body">{{ product.name }}</td>
              <td>
                <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis fw-medium">
                  {{ product.category }}
                </span>
              </td>
              <td class="text-end text-body fw-semibold text-nowrap">
                {{ formatCents(product.price_cents) }}
              </td>
              <td v-if="canUpdateInventory" class="staff-actions-cell text-center">
                <div data-row-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === product.id }"
                    :aria-expanded="manageOpenId === product.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(product.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && products.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No On-Demand SKUs found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 gap-sm-4 flex-wrap order-2 order-lg-1 justify-content-center justify-content-lg-start">
          <p class="small text-secondary mb-0 text-center text-sm-start">
            Showing <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to <span class="fw-semibold text-body">{{ showingTo }}</span>
            of <span class="fw-semibold text-body">{{ pagination.total }}</span> entries
          </p>
          <div class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start">
            <label class="small text-secondary text-nowrap mb-0" for="on-demand-per-page-footer">
              Rows per page
            </label>
            <select
              id="on-demand-per-page-footer"
              class="form-select form-select-sm staff-table-footer-per-page"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
            </select>
          </div>
        </div>
        <nav class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0" aria-label="On-Demand SKU list pages">
          <div class="staff-page-pager staff-page-pager--cluster">
            <div class="staff-page-pager__start">
              <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="loading || pagination.current_page <= 1" aria-label="First page" @click="goFirstPage">
                <span aria-hidden="true">«</span>
              </button>
              <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="loading || pagination.current_page <= 1" aria-label="Previous page" @click="goPage(pagination.current_page - 1)">
                <span aria-hidden="true">‹</span>
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <div class="staff-page-pager-inner d-flex align-items-center">
                <template v-for="(item, idx) in pageItems" :key="'pi-' + idx">
                  <span v-if="item.type === 'gap'" class="px-1 small text-secondary user-select-none">…</span>
                  <button
                    v-else
                    type="button"
                    class="staff-page-pager-tile"
                    :class="{ 'staff-page-pager-tile--active': item.value === pagination.current_page }"
                    :disabled="loading"
                    @click="goPage(item.value)"
                  >
                    {{ item.value }}
                  </button>
                </template>
              </div>
            </div>
            <div class="staff-page-pager__end">
              <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="loading || pagination.current_page >= pagination.last_page" aria-label="Next page" @click="goPage(pagination.current_page + 1)">
                <span aria-hidden="true">›</span>
              </button>
              <button type="button" class="staff-page-pager-tile staff-page-pager-tile--nav" :disabled="loading || pagination.current_page >= pagination.last_page" aria-label="Last page" @click="goLastPage">
                <span aria-hidden="true">»</span>
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageOpenId !== null && manageMenuProduct"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        :style="{ top: manageMenuRect.top + 'px', left: manageMenuRect.left + 'px' }"
        role="menu"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openEditDrawer(manageMenuProduct)">
          Edit SKU
        </button>
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteModal(manageMenuProduct)"
        >
          Delete SKU
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <Transition name="drawer-fade">
        <div
          v-if="drawerOpen"
          class="fixed inset-0 z-[1200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
          aria-modal="true"
          role="dialog"
        >
          <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px]" aria-hidden="true" @click="closeDrawer" />
          <Transition name="drawer-slide" appear>
            <aside class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl sm:max-w-lg">
              <header class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">
                  {{ editingProduct ? "Edit On-Demand SKU" : "Add On-Demand SKU" }}
                </h2>
                <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800" aria-label="Close" :disabled="saving" @click="closeDrawer">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </header>

              <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <form id="on-demand-product-drawer-form" class="space-y-5" @submit.prevent="saveProduct">
                  <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Account</label>
                    <select v-model="form.client_account_id" class="form-select" required :disabled="saving">
                      <option value="">Select account</option>
                      <option v-for="account in accounts" :key="account.id" :value="String(account.id)">
                        {{ account.company_name }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">SKU</label>
                    <input v-model="form.sku" type="text" class="form-control text-uppercase" maxlength="128" required :disabled="saving" />
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Name</label>
                    <input v-model="form.name" type="text" class="form-control" required :disabled="saving" placeholder="CBD Gummies" />
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Category</label>
                    <select v-model="form.category" class="form-select" required :disabled="saving">
                      <option v-for="category in categories" :key="category" :value="category">
                        {{ category }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Price</label>
                    <input v-model="form.price_dollars" type="number" class="form-control" min="0.01" step="0.01" placeholder="3.25" required :disabled="saving" />
                  </div>
                </form>
              </div>

              <footer class="flex shrink-0 items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4">
                <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="closeDrawer">
                  Cancel
                </button>
                <button type="submit" form="on-demand-product-drawer-form" class="btn btn-primary" :disabled="saving">
                  {{ saving ? "Saving..." : "Save SKU" }}
                </button>
              </footer>
            </aside>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="deleteTarget !== null"
      title="Delete On-Demand SKU"
      :message="deleteTarget ? `Delete ${deleteTarget.name} (${deleteTarget.sku})? This cannot be undone.` : ''"
      confirm-label="Delete SKU"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
  </div>
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
  transition: transform 0.25s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
