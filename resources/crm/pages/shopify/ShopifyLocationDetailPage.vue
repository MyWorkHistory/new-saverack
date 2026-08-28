<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ShopifyLocationBulkTransferModal from "../../components/shopify/ShopifyLocationBulkTransferModal.vue";
import ShopifyLocationTransferModal from "../../components/shopify/ShopifyLocationTransferModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser.js";

const MENU_W = 168;
const MENU_H = 148;

const props = defineProps({
  id: { type: String, default: "" },
});

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));
const loading = ref(true);
const busy = ref(false);
const location = ref(null);
const items = ref([]);
const totalQty = ref(0);
const types = ref([]);
const destLocations = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

const accountOptions = ref([]);
const accountsLoading = ref(false);
const filterAccountId = ref("");
const searchQuery = ref("");

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const editOpen = ref(false);
const qtyOpen = ref(false);
const transferOpen = ref(false);
const addItemOpen = ref(false);
const addItemForm = reactive({
  client_account_id: "",
  shopify_variant_id: "",
  sku: "",
  product_label: "",
  available: 1,
});
const skuSearchOpen = ref(false);
const skuSearchQ = ref("");
const skuSearchLoading = ref(false);
const skuSearchResults = ref([]);
let skuSearchTimer = null;

const form = reactive({ name: "", type: "", pickable: true, sellable: true, active: true });
const qtyForm = reactive({ available: 0 });
const activeItem = ref(null);
const transferToId = ref("");
const transferQty = ref("0");

const headerActionsOpen = ref(false);
const filterMenuOpen = ref(false);
const selectedIds = ref([]);
const bulkTransferOpen = ref(false);
const bulkTransferToId = ref("");
const deleteLocationOpen = ref(false);
const deleteLocationBusy = ref(false);
const deleteItemOpen = ref(false);
const deleteItemBusy = ref(false);
const deleteItemTarget = ref(null);

let searchTimer = null;

const locationId = computed(() => String(props.id || route.params.id || ""));
const manageMenuItem = computed(() => items.value.find((r) => r.id === manageOpenId.value) ?? null);
const canDeleteItems = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  return crmIsAdmin(u) || u.is_crm_owner;
});
const selectedItems = computed(() =>
  items.value.filter((row) => selectedIds.value.includes(row.id)),
);
const allPageSelected = computed(() => {
  if (!items.value.length) return false;
  return items.value.every((row) => selectedIds.value.includes(row.id));
});
const somePageSelected = computed(() => {
  if (!items.value.length) return false;
  const n = items.value.filter((row) => selectedIds.value.includes(row.id)).length;
  return n > 0 && n < items.value.length;
});

function yesNoBadge(on) {
  return on ? "shopify-loc-badge shopify-loc-badge--yes" : "shopify-loc-badge shopify-loc-badge--no";
}

function activeBadge(on) {
  return on ? "shopify-loc-badge shopify-loc-badge--yes" : "shopify-loc-badge shopify-loc-badge--no";
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    const list = Array.isArray(data?.accounts) ? data.accounts : Array.isArray(data) ? data : [];
    accountOptions.value = list
      .filter((a) => a?.id)
      .map((a) => ({
        id: a.id,
        name: a.company_name || a.name || `Account #${a.id}`,
        email: a.email || "",
      }));
  } catch {
    accountOptions.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const params = {
      per_page: pagination.value.per_page,
      page: pagination.value.current_page,
    };
    const q = searchQuery.value.trim();
    if (q) params.q = q;
    const accountId = Number(filterAccountId.value || 0);
    if (accountId > 0) params.client_account_id = accountId;

    const { data } = await api.get(`/shopify/locations/${locationId.value}`, { params });
    location.value = data?.location || null;
    items.value = Array.isArray(data?.items) ? data.items : [];
    totalQty.value = Number(data?.total_qty || 0);
    pagination.value = {
      current_page: data?.meta?.current_page || 1,
      last_page: data?.meta?.last_page || 1,
      total: data?.meta?.total || 0,
      per_page: data?.meta?.per_page || pagination.value.per_page,
    };
    const visible = new Set(items.value.map((r) => r.id));
    selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
    if (location.value?.name) {
      setCrmPageMeta({
        title: `Save Rack | ${location.value.name}`,
        description: "Shopify warehouse location.",
      });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load location.");
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  pagination.value.current_page = 1;
  void load();
}

function openEdit() {
  const loc = location.value;
  if (!loc) return;
  form.name = loc.name || "";
  form.type = loc.type || types.value[0] || "";
  form.pickable = Boolean(loc.pickable);
  form.sellable = Boolean(loc.sellable);
  form.active = Boolean(loc.active);
  editOpen.value = true;
}

async function saveLocation() {
  const name = String(form.name || "").trim();
  if (!name) {
    toast.error("Location name is required.");
    return;
  }
  busy.value = true;
  try {
    const { data } = await api.patch(`/shopify/locations/${locationId.value}`, { ...form, name });
    location.value = data?.location || location.value;
    toast.success("Location updated.");
    editOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not update location.");
  } finally {
    busy.value = false;
  }
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
  if (manageOpenId.value === row?.id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = row.id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-loc-item-actions]")) manageOpenId.value = null;
  if (!e.target?.closest?.("[data-shopify-loc-sku-search]")) skuSearchOpen.value = false;
  if (!e.target?.closest?.("[data-shopify-loc-header-actions]")) headerActionsOpen.value = false;
  if (!e.target?.closest?.("[data-shopify-loc-filters]")) filterMenuOpen.value = false;
}

function resetFilters() {
  filterAccountId.value = "";
  searchQuery.value = "";
  applyFilters();
  filterMenuOpen.value = false;
}

function toggleSelect(id) {
  const next = selectedIds.value.includes(id)
    ? selectedIds.value.filter((v) => v !== id)
    : [...selectedIds.value, id];
  selectedIds.value = next;
}

function toggleSelectAll(event) {
  const checked = !!event?.target?.checked;
  selectedIds.value = checked ? items.value.map((row) => row.id) : [];
}

function clearSelection() {
  selectedIds.value = [];
}

function openDeleteLocation() {
  headerActionsOpen.value = false;
  deleteLocationOpen.value = true;
}

async function confirmDeleteLocation() {
  deleteLocationBusy.value = true;
  try {
    await api.delete(`/shopify/locations/${locationId.value}`);
    toast.success("Location deleted.");
    deleteLocationOpen.value = false;
    router.push({ name: "shopify-locations" });
  } catch (e) {
    toast.errorFrom(e, "Could not delete location.");
  } finally {
    deleteLocationBusy.value = false;
  }
}

function promptDeleteItem(row) {
  deleteItemTarget.value = row;
  deleteItemOpen.value = true;
  manageOpenId.value = null;
}

async function confirmDeleteItem() {
  const row = deleteItemTarget.value;
  if (!row?.id) return;
  deleteItemBusy.value = true;
  try {
    await api.delete(`/shopify/locations/${locationId.value}/items/${row.id}`);
    toast.success("Item removed.");
    deleteItemOpen.value = false;
    deleteItemTarget.value = null;
    selectedIds.value = selectedIds.value.filter((id) => id !== row.id);
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not remove item.");
  } finally {
    deleteItemBusy.value = false;
  }
}

async function openBulkTransfer() {
  if (!selectedIds.value.length) return;
  bulkTransferToId.value = "";
  try {
    const { data } = await api.get("/shopify/locations/options", { params: { exclude: locationId.value } });
    destLocations.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    destLocations.value = [];
    toast.errorFrom(e, "Could not load destination locations.");
    return;
  }
  bulkTransferOpen.value = true;
}

async function submitBulkTransfer() {
  if (!selectedIds.value.length) return;
  if (!bulkTransferToId.value) {
    toast.error("Select a destination location.");
    return;
  }
  busy.value = true;
  try {
    const { data } = await api.post(`/shopify/locations/${locationId.value}/bulk-transfer`, {
      item_ids: selectedIds.value,
      to_location_id: Number(bulkTransferToId.value),
    });
    const transferred = Number(data?.transferred || 0);
    const skipped = Number(data?.skipped || 0);
    let msg = `Transferred ${transferred} item${transferred === 1 ? "" : "s"}.`;
    if (skipped) msg += ` ${skipped} skipped.`;
    toast.success(msg);
    bulkTransferOpen.value = false;
    selectedIds.value = [];
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not transfer inventory.");
  } finally {
    busy.value = false;
  }
}

function openQty(row) {
  activeItem.value = row;
  qtyForm.available = Number(row.available || 0);
  qtyOpen.value = true;
  manageOpenId.value = null;
}

async function saveQty() {
  if (!activeItem.value) return;
  busy.value = true;
  try {
    await api.patch(`/shopify/locations/${locationId.value}/items/${activeItem.value.id}`, {
      available: Number(qtyForm.available || 0),
    });
    toast.success("Quantity updated.");
    qtyOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    busy.value = false;
  }
}

async function openTransfer(row) {
  activeItem.value = row;
  transferToId.value = "";
  transferQty.value = "0";
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/shopify/locations/options", { params: { exclude: locationId.value } });
    destLocations.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    destLocations.value = [];
    toast.errorFrom(e, "Could not load destination locations.");
  }
  transferOpen.value = true;
}

async function submitTransfer() {
  if (!activeItem.value) return;
  const qty = Number(transferQty.value || 0);
  if (!transferToId.value) {
    toast.error("Select a destination location.");
    return;
  }
  if (qty < 1) {
    toast.error("Enter a quantity to transfer.");
    return;
  }
  busy.value = true;
  try {
    await api.post(`/shopify/locations/${locationId.value}/transfer`, {
      item_id: activeItem.value.id,
      to_location_id: Number(transferToId.value),
      quantity: qty,
    });
    toast.success("Inventory transferred.");
    transferOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not transfer inventory.");
  } finally {
    busy.value = false;
  }
}

function openAddItem() {
  addItemForm.client_account_id = "";
  addItemForm.shopify_variant_id = "";
  addItemForm.sku = "";
  addItemForm.product_label = "";
  addItemForm.available = 1;
  skuSearchQ.value = "";
  skuSearchResults.value = [];
  skuSearchOpen.value = false;
  addItemOpen.value = true;
}

function clearSelectedSku() {
  addItemForm.shopify_variant_id = "";
  addItemForm.sku = "";
  addItemForm.product_label = "";
  skuSearchQ.value = "";
  skuSearchResults.value = [];
}

watch(
  () => addItemForm.client_account_id,
  () => {
    clearSelectedSku();
  },
);

function scheduleSkuSearch() {
  if (skuSearchTimer) clearTimeout(skuSearchTimer);
  skuSearchTimer = setTimeout(() => {
    skuSearchTimer = null;
    void searchSkus();
  }, 280);
}

async function searchSkus() {
  const accountId = Number(addItemForm.client_account_id || 0);
  if (accountId <= 0) {
    skuSearchResults.value = [];
    return;
  }
  skuSearchLoading.value = true;
  skuSearchOpen.value = true;
  try {
    const { data } = await api.get("/shopify/inventory", {
      params: {
        client_account_id: accountId,
        q: skuSearchQ.value.trim() || undefined,
        per_page: 25,
      },
    });
    skuSearchResults.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    skuSearchResults.value = [];
    toast.errorFrom(e, "Could not search products.");
  } finally {
    skuSearchLoading.value = false;
  }
}

function selectSkuResult(row) {
  addItemForm.shopify_variant_id = String(row.id || "");
  addItemForm.sku = String(row.sku || "");
  const title = String(row.product_title || row.title || "").trim();
  addItemForm.product_label = title
    ? `${title}${row.sku ? ` (${row.sku})` : ""}`
    : String(row.sku || "Selected product");
  skuSearchOpen.value = false;
  skuSearchQ.value = "";
}

async function addItem() {
  const accountId = Number(addItemForm.client_account_id || 0);
  const variantId = Number(addItemForm.shopify_variant_id || 0);
  const sku = String(addItemForm.sku || "").trim();
  if (accountId <= 0) {
    toast.error("Select an account.");
    return;
  }
  if (variantId <= 0 && !sku) {
    toast.error("Select a product SKU.");
    return;
  }
  busy.value = true;
  try {
    await api.post(`/shopify/locations/${locationId.value}/items`, {
      client_account_id: accountId,
      shopify_variant_id: variantId > 0 ? variantId : undefined,
      sku: sku || undefined,
      available: Number(addItemForm.available || 0),
    });
    toast.success("Item added.");
    addItemOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add item.");
  } finally {
    busy.value = false;
  }
}

function deleteItem(row) {
  promptDeleteItem(row);
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Location",
    description: "Shopify warehouse location.",
  });
  document.addEventListener("click", onDocClick);
  try {
    const { data } = await api.get("/shopify/locations/meta");
    types.value = Array.isArray(data?.types) ? data.types : [];
  } catch (_) {
    types.value = ["Large Bin", "Medium Bin", "Small Bin", "Large Pallet", "Medium Pallet", "Small Pallet"];
  }
  void loadAccounts();
  void load();
});

watch(() => route.params.id, () => {
  pagination.value.current_page = 1;
  searchQuery.value = "";
  filterAccountId.value = "";
  void load();
});

watch(filterAccountId, () => {
  applyFilters();
});

watch(
  () => searchQuery.value,
  () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      applyFilters();
    }, 280);
  },
);

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  if (skuSearchTimer) clearTimeout(skuSearchTimer);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <button
      type="button"
      class="btn btn-link text-decoration-none px-0 mb-3 shopify-loc-back"
      @click="router.push({ name: 'shopify-locations' })"
    >
      ← Back to Locations
    </button>

    <div v-if="loading && !location" class="py-5">
      <CrmLoadingSpinner message="Loading Location…" />
    </div>

    <template v-else-if="location">
      <div class="shopify-loc-summary mb-4">
        <div class="shopify-loc-summary__main">
          <div class="shopify-loc-hero-icon" aria-hidden="true">
            <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="#2563eb" stroke-width="1.7">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"
              />
            </svg>
          </div>
          <div class="shopify-loc-summary__info min-w-0">
            <h1 class="shopify-loc-summary__title">{{ location.name }}</h1>
            <div class="shopify-loc-meta">
              <div class="shopify-loc-meta__col">
                <div class="shopify-loc-meta__label">Type</div>
                <div class="shopify-loc-meta__value">{{ location.type || "—" }}</div>
              </div>
              <div class="shopify-loc-meta__col">
                <div class="shopify-loc-meta__label">Pickable</div>
                <span :class="yesNoBadge(location.pickable)">{{ location.pickable ? "Yes" : "No" }}</span>
              </div>
              <div class="shopify-loc-meta__col">
                <div class="shopify-loc-meta__label">Sellable</div>
                <span :class="yesNoBadge(location.sellable)">{{ location.sellable ? "Yes" : "No" }}</span>
              </div>
              <div class="shopify-loc-meta__col">
                <div class="shopify-loc-meta__label">Active</div>
                <span :class="activeBadge(location.active)">{{ location.active ? "Active" : "Inactive" }}</span>
              </div>
            </div>
          </div>
        </div>
        <div class="shopify-loc-summary__aside">
          <div class="position-relative flex-shrink-0" data-shopify-loc-header-actions>
            <button
              type="button"
              class="btn btn-outline-secondary orders-toolbar-outline-btn dropdown-toggle"
              :aria-expanded="headerActionsOpen"
              @click.stop="headerActionsOpen = !headerActionsOpen"
            >
              Actions
            </button>
            <div
              v-if="headerActionsOpen"
              class="dropdown-menu dropdown-menu-end show shadow border py-1"
              style="position: absolute; top: calc(100% + 0.25rem); right: 0; z-index: 1090; min-width: 12.5rem"
              @click.stop
            >
              <button type="button" class="dropdown-item small" @click="openEdit(); headerActionsOpen = false">
                Edit Location
              </button>
              <button type="button" class="dropdown-item small text-danger" @click="openDeleteLocation">
                Delete Location
              </button>
            </div>
          </div>
          <div class="shopify-loc-qty-card">
            <div class="shopify-loc-qty-card__label">Total QTY at Location</div>
            <div class="shopify-loc-qty-card__value">{{ totalQty }}</div>
          </div>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
        <div class="px-3 px-md-4 pt-4 pb-2 d-flex flex-wrap align-items-start justify-content-between gap-2">
          <div>
            <h2 class="shopify-loc-inv-title mb-1">Inventory at this location</h2>
            <p class="shopify-loc-inv-sub mb-0">All inventory items currently stored at this location.</p>
          </div>
          <button type="button" class="btn btn-sm btn-primary staff-page-primary" @click="openAddItem">
            Add Item
          </button>
        </div>

        <div class="staff-table-toolbar px-3 px-md-4">
          <div class="staff-table-toolbar--row shopify-loc-toolbar-row">
            <div class="shopify-loc-toolbar-search flex-grow-1">
              <div class="input-group orders-toolbar-search-group">
                <span class="input-group-text bg-white">
                  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" />
                  </svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="search"
                  class="form-control"
                  placeholder="Search by name or SKU…"
                  autocomplete="off"
                  aria-label="Search products at this location"
                  :disabled="loading"
                />
              </div>
            </div>
            <div class="shopify-loc-toolbar-account">
              <CrmSearchableSelect
                v-model="filterAccountId"
                class="staff-toolbar-search staff-toolbar-search--inline w-100"
                appearance="staff"
                aria-label="Filter by account"
                :options="accountOptions"
                :disabled="accountsLoading || loading"
                placeholder="All Accounts"
                empty-label="All Accounts"
                search-placeholder="Search accounts…"
              />
            </div>
            <div class="position-relative flex-shrink-0" data-shopify-loc-filters>
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
                :aria-expanded="filterMenuOpen"
                :disabled="loading"
                @click.stop="filterMenuOpen = !filterMenuOpen"
              >
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4" />
                </svg>
                Filters
              </button>
              <div
                v-if="filterMenuOpen"
                class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
                style="position: absolute; top: calc(100% + 0.25rem); right: 0; z-index: 1090"
                @click.stop
              >
                <div class="staff-toolbar-filter-dropdown__head">
                  <span>Filters</span>
                  <button
                    type="button"
                    class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                    @click="resetFilters"
                  >
                    Reset
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div
          v-if="selectedIds.length"
          class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
        >
          <span class="small staff-bulk-selection-bar__count">
            {{ selectedIds.length }} item{{ selectedIds.length === 1 ? "" : "s" }} selected
          </span>
          <button type="button" class="btn btn-sm staff-page-primary" @click="openBulkTransfer">Bulk Edit</button>
          <button
            type="button"
            class="btn btn-link btn-sm staff-bulk-clear-link ms-auto text-decoration-none"
            @click="clearSelection"
          >
            Clear Selection
          </button>
        </div>

        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th" style="width: 2.5rem">
                  <input
                    type="checkbox"
                    class="form-check-input"
                    :checked="allPageSelected"
                    :indeterminate.prop="somePageSelected"
                    aria-label="Select all on page"
                    @change="toggleSelectAll"
                  />
                </th>
                <th class="staff-table-head__th">Product</th>
                <th class="staff-table-head__th">Account</th>
                <th class="staff-table-head__th">QTY</th>
                <th class="staff-table-head__th staff-actions-col text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="5" class="py-4">
                  <CrmLoadingSpinner message="Loading Inventory…" />
                </td>
              </tr>
              <tr v-else-if="!items.length">
                <td colspan="5" class="px-4 py-5 text-center text-secondary">No inventory at this location yet.</td>
              </tr>
              <tr v-for="row in items" v-else :key="row.id">
                <td @click.stop>
                  <input
                    type="checkbox"
                    class="form-check-input"
                    :checked="selectedIds.includes(row.id)"
                    :aria-label="`Select ${row.sku || row.product_title}`"
                    @change="toggleSelect(row.id)"
                  />
                </td>
                <td>
                  <RouterLink
                    v-if="row.variant_id"
                    :to="{ name: 'shopify-inventory-detail', params: { id: String(row.variant_id) } }"
                    class="text-decoration-none text-body"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click.stop
                  >
                    <div class="sip-product-cell">
                      <div class="sip-product-cell__img">
                        <img v-if="row.image_url" :src="row.image_url" :alt="row.product_title || row.sku || 'Product'" />
                        <span v-else class="sip-product-cell__img-empty" aria-hidden="true">
                          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                          </svg>
                        </span>
                      </div>
                      <div class="min-w-0">
                        <div class="sip-product-cell__name text-truncate">{{ row.product_title || "—" }}</div>
                        <div class="sip-product-cell__sku">{{ row.sku || "—" }}</div>
                      </div>
                    </div>
                  </RouterLink>
                  <div v-else class="sip-product-cell">
                    <div class="sip-product-cell__img">
                      <img v-if="row.image_url" :src="row.image_url" :alt="row.product_title || row.sku || 'Product'" />
                      <span v-else class="sip-product-cell__img-empty" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                      <div class="sip-product-cell__name text-truncate">{{ row.product_title || "—" }}</div>
                      <div class="sip-product-cell__sku">{{ row.sku || "—" }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <RouterLink
                    v-if="row.client_account_id"
                    :to="{ name: 'client-account-detail', params: { id: String(row.client_account_id) } }"
                    class="text-decoration-none text-body"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click.stop
                  >
                    {{ row.account_name || "—" }}
                  </RouterLink>
                  <span v-else>{{ row.account_name || "—" }}</span>
                </td>
                <td class="fw-semibold">{{ row.available }}</td>
                <td class="staff-actions-cell text-center">
                  <div data-shopify-loc-item-actions class="staff-actions-inner staff-actions-inner--single justify-content-center">
                    <button
                      type="button"
                      class="staff-action-btn staff-action-btn--more"
                      :class="{ 'is-open': manageOpenId === row.id }"
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
        <p class="small text-secondary px-3 px-md-4 py-3 mb-0 border-top">
          Showing {{ items.length ? 1 : 0 }} to {{ items.length }} of {{ pagination.total }} items.
        </p>
      </div>
    </template>

    <Teleport to="body">
      <div
        v-if="manageMenuItem"
        data-shopify-loc-item-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item shopify-loc-menu-item" role="menuitem" @click="openQty(manageMenuItem)">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897z" />
          </svg>
          Edit
        </button>
        <button type="button" class="staff-row-menu__item shopify-loc-menu-item" role="menuitem" @click="openTransfer(manageMenuItem)">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-9L21 12m0 0L16.5 16.5M21 12H7.5" />
          </svg>
          Transfer
        </button>
        <button
          v-if="canDeleteItems"
          type="button"
          class="staff-row-menu__item shopify-loc-menu-item text-danger"
          role="menuitem"
          @click="deleteItem(manageMenuItem)"
        >
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
          </svg>
          Delete
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="editOpen" class="crm-vx-modal-overlay" @click.self="editOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button type="button" class="crm-vx-modal__close" aria-label="Close" @click="editOpen = false">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Edit Location</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="edit-loc-name">Location Name</label>
            <input id="edit-loc-name" v-model="form.name" type="text" class="form-control mb-3" />
            <label class="form-label" for="edit-loc-type">Type</label>
            <select id="edit-loc-type" v-model="form.type" class="form-select mb-3">
              <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
            <label class="form-label d-flex align-items-center justify-content-between">
              Pickable
              <input v-model="form.pickable" type="checkbox" class="form-check-input" />
            </label>
            <label class="form-label d-flex align-items-center justify-content-between">
              Sellable
              <input v-model="form.sellable" type="checkbox" class="form-check-input" />
            </label>
            <label class="form-label d-flex align-items-center justify-content-between mb-0">
              Active
              <input v-model="form.active" type="checkbox" class="form-check-input" />
            </label>
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="editOpen = false">Cancel</button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="saveLocation">
              {{ busy ? "Please Wait…" : "Save" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="qtyOpen" class="crm-vx-modal-overlay" @click.self="qtyOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Edit QTY</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="edit-qty">Quantity</label>
            <input id="edit-qty" v-model="qtyForm.available" type="number" min="0" class="form-control" />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="qtyOpen = false">Cancel</button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="saveQty">
              {{ busy ? "Please Wait…" : "Save" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="addItemOpen" class="crm-vx-modal-overlay" @click.self="addItemOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Add Item</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="add-item-account">Account</label>
            <CrmSearchableSelect
              id="add-item-account"
              v-model="addItemForm.client_account_id"
              class="mb-3"
              appearance="staff"
              aria-label="Select account"
              :options="accountOptions"
              :disabled="accountsLoading || busy"
              :allow-empty="false"
              placeholder="Select Account"
              empty-label="Select Account"
              search-placeholder="Search accounts…"
              teleport-panel
            />

            <label class="form-label" for="add-item-sku-search">SKU</label>
            <div data-shopify-loc-sku-search class="position-relative mb-3">
              <template v-if="addItemForm.shopify_variant_id">
                <div class="shopify-loc-sku-selected d-flex align-items-center justify-content-between gap-2">
                  <span class="small text-body min-w-0 text-truncate">{{ addItemForm.product_label || addItemForm.sku }}</span>
                  <button
                    type="button"
                    class="btn btn-link btn-sm p-0 text-decoration-none flex-shrink-0"
                    :disabled="busy || !addItemForm.client_account_id"
                    @click="clearSelectedSku"
                  >
                    Change
                  </button>
                </div>
              </template>
              <template v-else>
                <input
                  id="add-item-sku-search"
                  v-model="skuSearchQ"
                  type="search"
                  class="form-control"
                  placeholder="Search by name or SKU…"
                  autocomplete="off"
                  :disabled="busy || !addItemForm.client_account_id"
                  @focus="addItemForm.client_account_id && searchSkus()"
                  @input="scheduleSkuSearch"
                />
                <div
                  v-if="skuSearchOpen && addItemForm.client_account_id"
                  class="shopify-loc-sku-dropdown"
                  role="listbox"
                >
                  <div v-if="skuSearchLoading" class="px-3 py-2 small text-secondary">Searching…</div>
                  <button
                    v-for="row in skuSearchResults"
                    :key="row.id"
                    type="button"
                    class="shopify-loc-sku-dropdown__item"
                    role="option"
                    @click="selectSkuResult(row)"
                  >
                    <span class="fw-semibold d-block text-truncate">{{ row.product_title || row.title || "—" }}</span>
                    <span class="small text-secondary">{{ row.sku || "—" }}</span>
                  </button>
                  <div
                    v-if="!skuSearchLoading && !skuSearchResults.length"
                    class="px-3 py-2 small text-secondary"
                  >
                    No products found for this account.
                  </div>
                </div>
              </template>
              <p v-if="!addItemForm.client_account_id" class="small text-secondary mb-0 mt-1">
                Select an account to search SKUs.
              </p>
            </div>

            <label class="form-label" for="add-item-qty">QTY</label>
            <input id="add-item-qty" v-model="addItemForm.available" type="number" min="1" class="form-control" />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="addItemOpen = false">Cancel</button>
            <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="addItem">
              {{ busy ? "Please Wait…" : "Add Item" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <ShopifyLocationTransferModal
      :open="transferOpen"
      :busy="busy"
      :product-title="activeItem?.product_title || ''"
      :sku="activeItem?.sku || ''"
      :image-url="activeItem?.image_url || ''"
      :from-name="location?.name || ''"
      :available="Number(activeItem?.available || 0)"
      :to-location-id="transferToId"
      :quantity="transferQty"
      :locations="destLocations"
      @close="transferOpen = false"
      @submit="submitTransfer"
      @all="transferQty = String(activeItem?.available || 0)"
      @update:to-location-id="transferToId = $event"
      @update:quantity="transferQty = $event"
    />

    <ShopifyLocationBulkTransferModal
      :open="bulkTransferOpen"
      :busy="busy"
      :items="selectedItems"
      :from-name="location?.name || ''"
      :to-location-id="bulkTransferToId"
      :locations="destLocations"
      @close="bulkTransferOpen = false"
      @submit="submitBulkTransfer"
      @update:to-location-id="bulkTransferToId = $event"
    />

    <ConfirmModal
      :open="deleteLocationOpen"
      title="Delete Location?"
      :message="
        location
          ? `Delete location “${location.name}”? Remove all inventory first. This cannot be undone.`
          : 'Delete this location?'
      "
      confirm-label="Delete"
      :busy="deleteLocationBusy"
      danger
      @close="deleteLocationOpen = false"
      @confirm="confirmDeleteLocation"
    />

    <ConfirmModal
      :open="deleteItemOpen"
      title="Delete Item?"
      :message="
        deleteItemTarget
          ? `Remove ${deleteItemTarget.sku || deleteItemTarget.product_title || 'this item'} from ${location?.name || 'this location'}? This cannot be undone.`
          : 'Remove this item from the location?'
      "
      confirm-label="Delete"
      :busy="deleteItemBusy"
      danger
      @close="deleteItemOpen = false"
      @confirm="confirmDeleteItem"
    />
  </div>
</template>

<style scoped>
.shopify-loc-back {
  color: #2563eb;
  font-weight: 600;
}
.shopify-loc-summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1.25rem 1.5rem;
  padding: 1.25rem 1.35rem;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 0.9rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.shopify-loc-summary__main {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  min-width: 0;
  flex: 1 1 18rem;
}
.shopify-loc-hero-icon {
  width: 64px;
  height: 64px;
  border-radius: 0.9rem;
  background: #dbeafe;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.shopify-loc-summary__title {
  margin: 0 0 0.85rem;
  font-size: 1.45rem;
  font-weight: 700;
  line-height: 1.2;
  color: #0f172a;
}
.shopify-loc-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1.25rem 2rem;
}
.shopify-loc-meta__col {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  min-width: 4.5rem;
}
.shopify-loc-meta__label {
  font-size: 0.8rem;
  font-weight: 500;
  color: #64748b;
  line-height: 1.2;
}
.shopify-loc-meta__value {
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}
.shopify-loc-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.2rem 0.7rem;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.75rem;
  line-height: 1.2;
}
.shopify-loc-badge--yes {
  background: #dcfce7;
  color: #15803d;
}
.shopify-loc-badge--no {
  background: #fee2e2;
  color: #b91c1c;
}
.shopify-loc-summary__aside {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.75rem;
  flex-shrink: 0;
}
.shopify-loc-qty-card {
  min-width: 15rem;
  padding: 0.95rem 1.1rem 0.85rem;
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 0.85rem;
  background: #fff;
}
.shopify-loc-qty-card__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
}
.shopify-loc-qty-card__label {
  font-size: 0.8rem;
  color: #64748b;
  line-height: 1.3;
  padding-top: 0.15rem;
}
.shopify-loc-qty-card__value {
  font-size: 2.15rem;
  font-weight: 800;
  color: #2563eb;
  line-height: 1.1;
  letter-spacing: -0.02em;
}
.sip-product-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}
.sip-product-cell__img {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sip-product-cell__img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sip-product-cell__img-empty {
  color: #c0c4cc;
}
.sip-product-cell__name {
  font-size: 0.8rem;
  color: #6b7280;
  line-height: 1.25;
}
.sip-product-cell__sku {
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.shopify-loc-menu-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}
.shopify-loc-menu-item svg {
  flex-shrink: 0;
  opacity: 0.85;
}
.shopify-loc-inv-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.3;
}
.shopify-loc-inv-sub {
  font-size: 0.875rem;
  color: #64748b;
  line-height: 1.4;
}
.shopify-loc-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: stretch;
  width: 100%;
}
.shopify-loc-toolbar-account {
  width: min(100%, 16rem);
  flex-shrink: 0;
}
.shopify-loc-toolbar-search {
  min-width: min(100%, 16rem);
}
.shopify-loc-item-thumb {
  width: 36px;
  height: 36px;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #e2e8f0;
  flex-shrink: 0;
}
.shopify-loc-item-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.shopify-loc-sku-selected {
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 0.5rem;
  padding: 0.55rem 0.75rem;
  background: #f8fafc;
}
.shopify-loc-sku-dropdown {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  z-index: 20;
  max-height: 220px;
  overflow: auto;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 0.5rem;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
}
.shopify-loc-sku-dropdown__item {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
}
.shopify-loc-sku-dropdown__item:hover,
.shopify-loc-sku-dropdown__item:focus-visible {
  background: #eff6ff;
}
.shopify-loc-sku-dropdown__item:last-child {
  border-bottom: 0;
}
</style>
