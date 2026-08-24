<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ShopifyLocationTransferModal from "../../components/shopify/ShopifyLocationTransferModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const MENU_W = 180;
const MENU_H = 132;

const props = defineProps({
  id: { type: String, default: "" },
});

const route = useRoute();
const router = useRouter();
const toast = useToast();
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

const locationId = computed(() => String(props.id || route.params.id || ""));
const manageMenuItem = computed(() => items.value.find((r) => r.id === manageOpenId.value) ?? null);

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

async function deleteItem(row) {
  if (!window.confirm(`Remove ${row.sku || "this item"} from this location?`)) return;
  manageOpenId.value = null;
  try {
    await api.delete(`/shopify/locations/${locationId.value}/items/${row.id}`);
    toast.success("Item removed.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not remove item.");
  }
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

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  if (skuSearchTimer) clearTimeout(skuSearchTimer);
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
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-start gap-3 min-w-0">
          <div class="shopify-loc-hero-icon" aria-hidden="true">
            <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="#2563eb" stroke-width="1.6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
          </div>
          <div class="min-w-0">
            <h1 class="h4 mb-2 fw-semibold text-body">{{ location.name }}</h1>
            <div class="d-flex flex-wrap align-items-center gap-3 small">
              <span class="text-secondary">Type: <strong class="text-body">{{ location.type || "—" }}</strong></span>
              <span class="text-secondary">Pickable: <span :class="yesNoBadge(location.pickable)">{{ location.pickable ? "Yes" : "No" }}</span></span>
              <span class="text-secondary">Sellable: <span :class="yesNoBadge(location.sellable)">{{ location.sellable ? "Yes" : "No" }}</span></span>
              <span class="text-secondary">Active: <span :class="activeBadge(location.active)">{{ location.active ? "Active" : "Inactive" }}</span></span>
            </div>
          </div>
        </div>
        <div class="shopify-loc-qty-card">
          <button type="button" class="shopify-loc-qty-card__edit" @click="openEdit">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897z" />
            </svg>
            Edit
          </button>
          <div class="shopify-loc-qty-card__label">Total QTY at Location</div>
          <div class="shopify-loc-qty-card__value">{{ totalQty }}</div>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
        <div class="px-3 px-md-4 pt-4 pb-2 d-flex flex-wrap align-items-start justify-content-between gap-2">
          <div>
            <h2 class="h6 fw-semibold mb-1">Inventory at this location</h2>
            <p class="small text-secondary mb-0">All inventory items currently stored at this location.</p>
          </div>
          <button type="button" class="btn btn-sm btn-primary staff-page-primary" @click="openAddItem">
            Add Item
          </button>
        </div>

        <div class="staff-table-toolbar px-3 px-md-4">
          <div class="staff-table-toolbar--row shopify-loc-toolbar-row">
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
            <div class="shopify-loc-toolbar-search flex-grow-1">
              <div class="input-group orders-toolbar-search-group">
                <input
                  v-model="searchQuery"
                  type="search"
                  class="form-control"
                  placeholder="Search by name or SKU…"
                  autocomplete="off"
                  enterkeyhint="search"
                  aria-label="Search products at this location"
                  :disabled="loading"
                  @keydown.enter.prevent="applyFilters"
                />
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                  :disabled="loading"
                  @click="applyFilters"
                >
                  Search
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th">Product</th>
                <th class="staff-table-head__th">SKU</th>
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
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="shopify-loc-item-thumb">
                      <img v-if="row.image_url" :src="row.image_url" alt="" />
                    </div>
                    <span class="fw-semibold">{{ row.product_title || "—" }}</span>
                  </div>
                </td>
                <td>{{ row.sku || "—" }}</td>
                <td>{{ row.account_name || "—" }}</td>
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
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openQty(manageMenuItem)">Edit</button>
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openTransfer(manageMenuItem)">Transfer</button>
        <button type="button" class="staff-row-menu__item text-danger" role="menuitem" @click="deleteItem(manageMenuItem)">Delete</button>
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
  </div>
</template>

<style scoped>
.shopify-loc-back {
  color: #2563eb;
  font-weight: 600;
}
.shopify-loc-hero-icon {
  width: 56px;
  height: 56px;
  border-radius: 0.85rem;
  background: #dbeafe;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.shopify-loc-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.65rem;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.75rem;
}
.shopify-loc-badge--yes {
  background: #dcfce7;
  color: #166534;
}
.shopify-loc-badge--no {
  background: #fee2e2;
  color: #991b1b;
}
.shopify-loc-qty-card {
  position: relative;
  min-width: 14rem;
  padding: 1rem 1.15rem 0.9rem;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 0.85rem;
  background: #fff;
}
.shopify-loc-qty-card__label {
  font-size: 0.8rem;
  color: #64748b;
}
.shopify-loc-qty-card__value {
  font-size: 1.85rem;
  font-weight: 800;
  color: #2563eb;
  line-height: 1.15;
}
.shopify-loc-qty-card__edit {
  position: absolute;
  top: 0.65rem;
  right: 0.65rem;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border: 1px solid rgba(37, 99, 235, 0.4);
  color: #2563eb;
  background: #fff;
  border-radius: 0.45rem;
  font-size: 0.8rem;
  font-weight: 700;
  padding: 0.2rem 0.5rem;
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
