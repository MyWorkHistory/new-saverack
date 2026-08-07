<script setup>
import { Transition, computed, inject, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import InventoryRestockTransferModal from "../../components/inventory/InventoryRestockTransferModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();
const crmUser = inject("crmUser", ref(null));

const LINE_MENU_W = 180;
const LINE_MENU_H = 140;
const PRODUCT_CACHE_TTL_MS = 30 * 60 * 1000;
const PRODUCT_CACHE_PREFIX = "return-bin-product:v1:";

const loading = ref(true);
const rows = ref([]);
const productSearch = ref("");
const binId = computed(() => Number(route.params.binId || 0));
const binName = ref("");

const lineMenuKey = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });

const transferModalOpen = ref(false);
const transferBusy = ref(false);
const transferLoading = ref(false);
const transferRow = ref(null);
const transferProduct = ref(null);
const transferFromLocationId = ref("");
const transferForm = reactive({
  destination_mode: "current",
  to_location_id: "",
  to_location: "",
  quantity: "",
  reason: "Return Restock",
});

const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteRow = ref(null);
const deleteQty = ref("");

let prefetchGeneration = 0;

const canTransfer = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("returns_bins.update") ||
    keys.includes("returns.update") ||
    keys.includes("inventory_products.update") ||
    keys.includes("inventory.update")
  );
});

const lineMenuRow = computed(() => {
  const key = lineMenuKey.value;
  if (!key) return null;
  return rows.value.find((r) => rowKey(r) === key) ?? null;
});

const transferFromEmptyLabel = computed(() => String(binName.value || "Return Cart").trim() || "Return Cart");

const transferFromEmptyMessage = computed(() => {
  const name = transferFromEmptyLabel.value;
  return `No ${name} quantity found for this SKU.`;
});

const filteredRows = computed(() => {
  const q = String(productSearch.value || "")
    .trim()
    .toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((row) => rowMatchesProductSearch(row, q));
});

function rowMatchesProductSearch(row, q) {
  const sku = String(row?.sku || "").toLowerCase();
  const name = String(row?.name || "").toLowerCase();
  if (sku.includes(q) || name.includes(q)) return true;
  const product = readProductCache(row?.sku, row?.client_account_id);
  if (!product || typeof product !== "object") return false;
  const barcode = String(product.barcode || product.barcode_search || "").toLowerCase();
  if (barcode && barcode.includes(q)) return true;
  const barcodes = Array.isArray(product.barcodes) ? product.barcodes : [];
  return barcodes.some((b) => String(b || "").toLowerCase().includes(q));
}

function rowKey(row) {
  return `${row.sku}|${row.client_account_id}`;
}

function productCacheKey(sku, accountId) {
  return `${PRODUCT_CACHE_PREFIX}${Number(accountId) || 0}:${String(sku || "").trim()}`;
}

function readProductCache(sku, accountId) {
  try {
    const raw = sessionStorage.getItem(productCacheKey(sku, accountId));
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    const fetchedAt = Number(parsed?.fetchedAt || 0);
    if (!fetchedAt || Date.now() - fetchedAt > PRODUCT_CACHE_TTL_MS) return null;
    return parsed?.product && typeof parsed.product === "object" ? parsed.product : null;
  } catch {
    return null;
  }
}

function writeProductCache(sku, accountId, product) {
  if (!product || typeof product !== "object") return;
  try {
    sessionStorage.setItem(
      productCacheKey(sku, accountId),
      JSON.stringify({ fetchedAt: Date.now(), product }),
    );
  } catch {
    /* ignore quota */
  }
}

function invalidateProductCache(sku, accountId) {
  try {
    sessionStorage.removeItem(productCacheKey(sku, accountId));
  } catch {
    /* ignore */
  }
}

function preferredWarehouseId(product) {
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  return warehouses[0]?.warehouse_id ? String(warehouses[0].warehouse_id) : "";
}

function flattenProductLocations(product, { includeEmpty = false } = {}) {
  const out = [];
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      const qty = Number(loc?.quantity || 0);
      if (!includeEmpty && qty <= 0) return;
      out.push({
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
}

const transferFromOptions = computed(() => {
  const staging = String(binName.value || "").trim().toLowerCase();
  if (!staging) return [];
  return flattenProductLocations(transferProduct.value, { includeEmpty: false }).filter(
    (loc) => String(loc.location_name || "").trim().toLowerCase() === staging,
  );
});

const transferFromLocation = computed(() => {
  const id = String(transferFromLocationId.value || "");
  if (!id) return null;
  return (
    transferFromOptions.value.find((loc) => String(loc.location_id || "") === id) || null
  );
});

const transferPickOptions = computed(() => {
  const source = transferFromLocation.value;
  const whId = source
    ? String(source.warehouse_id || "")
    : preferredWarehouseId(transferProduct.value);
  const fromId = String(source?.location_id || "");
  return flattenProductLocations(transferProduct.value, { includeEmpty: true }).filter(
    (loc) =>
      loc.pickable === true &&
      (!whId || String(loc.warehouse_id || "") === whId) &&
      String(loc.location_id || "") !== fromId,
  );
});

watch(transferFromLocationId, (id, prev) => {
  if (String(id || "") === String(prev || "")) return;
  transferForm.to_location_id = "";
});

watch(
  () => transferForm.destination_mode,
  () => {
    transferForm.to_location_id = "";
    transferForm.to_location = "";
  },
);

function splitPickLocations(text) {
  const raw = String(text || "").trim();
  if (!raw || raw === "—") return [];
  return raw.split(",").map((part) => part.trim()).filter(Boolean);
}

function formatQty(value) {
  if (value === null || value === undefined || value === "") return "0";
  const n = Number(value);
  if (Number.isNaN(n)) return "0";
  return n.toLocaleString();
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  const query = {};
  const accountId = Number(row?.client_account_id || 0);
  if (accountId > 0) query.client_account_id = String(accountId);
  return router.resolve({ name: "inventory-detail", params: { sku }, query }).href;
}

function closeLineMenu() {
  lineMenuKey.value = null;
}

function onRowMenuClick(row, event) {
  const target = event?.currentTarget;
  if (!target) return;
  const rect = target.getBoundingClientRect();
  const left = Math.min(rect.left, window.innerWidth - LINE_MENU_W - 8);
  const top = Math.min(rect.bottom + 4, window.innerHeight - LINE_MENU_H - 8);
  lineMenuKey.value = rowKey(row);
  lineMenuRect.value = { top, left };
}

function onDocumentClick(event) {
  if (!lineMenuKey.value) return;
  const el = event?.target;
  if (el instanceof Element && el.closest("[data-return-bin-row-actions]")) return;
  closeLineMenu();
}

function applyTransferFromSelection() {
  const opts = transferFromOptions.value;
  transferFromLocationId.value = opts.length
    ? String(opts[0].location_id || "")
    : "";
  if (!String(transferForm.quantity || "").trim()) {
    transferForm.quantity = String(
      transferFromLocation.value?.quantity ?? transferRow.value?.qty ?? 0,
    );
  }
}

async function fetchProductForRow(row, { force = false } = {}) {
  const sku = String(row?.sku || "").trim();
  const accountId = Number(row?.client_account_id || 0);
  if (!sku || accountId <= 0) return null;
  if (!force) {
    const cached = readProductCache(sku, accountId);
    if (cached) return cached;
  }
  const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}`, {
    params: { client_account_id: accountId },
  });
  const product = data?.product ?? null;
  if (product) writeProductCache(sku, accountId, product);
  return product;
}

async function prefetchProductLocations() {
  const generation = ++prefetchGeneration;
  const unique = [];
  const seen = new Set();
  for (const row of rows.value) {
    const key = rowKey(row);
    if (seen.has(key)) continue;
    seen.add(key);
    unique.push(row);
  }
  for (const row of unique) {
    if (generation !== prefetchGeneration) return;
    const sku = String(row?.sku || "").trim();
    const accountId = Number(row?.client_account_id || 0);
    if (!sku || accountId <= 0) continue;
    if (readProductCache(sku, accountId)) continue;
    try {
      await fetchProductForRow(row);
    } catch {
      /* keep going */
    }
  }
}

async function load() {
  if (binId.value <= 0) {
    toast.error("Invalid return bin.");
    router.replace({ name: "admin-return-bins" });
    return;
  }
  loading.value = true;
  prefetchGeneration += 1;
  try {
    const { data } = await api.get(`/admin/returns/bins/${binId.value}/items`);
    binName.value = String(data?.bin?.name || "").trim() || `Bin ${binId.value}`;
    rows.value = Array.isArray(data?.data) ? data.data : [];
    setCrmPageMeta({
      title: `Save Rack | ${binName.value}`,
      description: "Items in a return bin awaiting restock.",
    });
    void prefetchProductLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not load bin items.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function openTransferFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  const accountId = Number(row.client_account_id || 0);
  if (accountId <= 0) {
    toast.error("Account is missing for this item.");
    return;
  }
  transferRow.value = row;
  transferForm.destination_mode = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = String(row.qty ?? "");
  transferForm.reason = "Return Restock";
  transferBusy.value = false;
  transferModalOpen.value = true;

  const cached = readProductCache(row.sku, accountId);
  if (cached) {
    transferProduct.value = cached;
    applyTransferFromSelection();
    transferLoading.value = false;
    return;
  }

  transferProduct.value = null;
  transferFromLocationId.value = "";
  transferLoading.value = true;
  try {
    const product = await fetchProductForRow(row);
    transferProduct.value = product;
    if (!transferProduct.value) {
      transferModalOpen.value = false;
      toast.error("Could not load product locations.");
      return;
    }
    await Promise.resolve();
    applyTransferFromSelection();
  } catch (e) {
    transferModalOpen.value = false;
    toast.errorFrom(e, "Could not load product for transfer.");
  } finally {
    transferLoading.value = false;
  }
}

function fillTransferAllQty() {
  const fromQty = Number(transferFromLocation.value?.quantity ?? 0);
  const crmQty = Number(transferRow.value?.qty ?? 0);
  const max = Math.min(
    fromQty > 0 ? fromQty : crmQty,
    crmQty > 0 ? crmQty : fromQty,
  );
  transferForm.quantity = String(max > 0 ? max : transferFromLocation.value?.quantity ?? crmQty ?? 0);
}

async function submitTransfer() {
  if (!transferRow.value || !transferFromLocation.value) return;
  const qty = parseInt(String(transferForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid transfer quantity.");
    return;
  }

  const destMode = String(transferForm.destination_mode || "current");
  const body = {
    sku: transferRow.value.sku,
    client_account_id: Number(transferRow.value.client_account_id || 0),
    warehouse_id: transferFromLocation.value.warehouse_id,
    from_location_id: transferFromLocation.value.location_id,
    quantity: qty,
  };

  if (destMode === "new") {
    if (!String(transferForm.to_location || "").trim()) {
      toast.error("Enter destination location.");
      return;
    }
    body.to_location = String(transferForm.to_location).trim();
  } else {
    if (!String(transferForm.to_location_id || "").trim()) {
      toast.error("Select a pick location.");
      return;
    }
    body.to_location_id = String(transferForm.to_location_id).trim();
  }

  transferBusy.value = true;
  try {
    const { data } = await api.post(`/admin/returns/bins/${binId.value}/transfer`, body);
    rows.value = Array.isArray(data?.data) ? data.data : rows.value;
    invalidateProductCache(transferRow.value.sku, transferRow.value.client_account_id);
    transferModalOpen.value = false;
    toast.success("Transferred to inventory.");
    void prefetchProductLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not transfer item.");
  } finally {
    transferBusy.value = false;
  }
}

function openDeleteFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  deleteRow.value = row;
  deleteQty.value = String(row.qty ?? 1);
  deleteOpen.value = true;
}

function closeDelete() {
  if (deleteBusy.value) return;
  deleteOpen.value = false;
  deleteRow.value = null;
  deleteQty.value = "";
}

async function confirmDelete() {
  const row = deleteRow.value;
  if (!row) return;
  const qty = parseInt(String(deleteQty.value || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid quantity.");
    return;
  }
  const max = Number(row.qty || 0);
  if (max > 0 && qty > max) {
    toast.error(`Quantity cannot exceed ${max}.`);
    return;
  }

  deleteBusy.value = true;
  try {
    const { data } = await api.post(`/admin/returns/bins/${binId.value}/remove`, {
      sku: row.sku,
      client_account_id: Number(row.client_account_id || 0),
      quantity: qty,
    });
    rows.value = Array.isArray(data?.data) ? data.data : rows.value;
    invalidateProductCache(row.sku, row.client_account_id);
    deleteOpen.value = false;
    deleteRow.value = null;
    toast.success("Item removed from bin.");
    void prefetchProductLocations();
  } catch (e) {
    toast.errorFrom(e, "Could not delete item.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Return Bin",
    description: "Items in a return bin awaiting restock.",
  });
  document.addEventListener("click", onDocumentClick);
  load();
});

onUnmounted(() => {
  prefetchGeneration += 1;
  document.removeEventListener("click", onDocumentClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-return-bins-page admin-return-bin-detail-page">
    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">
          {{ binName || "Return Bin" }}
        </h1>
        <button
          type="button"
          class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
          @click="router.push({ name: 'admin-return-bins' })"
        >
          &lt; Return Bins
        </button>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0 w-100 w-md-auto"
      >
        <input
          v-model="productSearch"
          type="search"
          class="form-control staff-toolbar-search staff-toolbar-search--inline"
          style="min-width: 14rem; max-width: 22rem"
          placeholder="Search product, SKU, or barcode"
          aria-label="Search bin items by product name, SKU, or barcode"
          autocomplete="off"
        />
        <CrmRefreshToolbarButton
          :disabled="loading"
          :loading="loading"
          title="Refresh bin items"
          @click="load"
        />
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-start" scope="col">Product</th>
              <th class="staff-table-head__th text-center" scope="col" style="width: 6rem">Qty</th>
              <th class="staff-table-head__th text-start" scope="col">Pick Location</th>
              <th
                class="staff-table-head__th staff-actions-col return-bins-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading bin items…" />
                </div>
              </td>
            </tr>
            <tr v-for="row in filteredRows" v-else :key="`${row.sku}-${row.client_account_id}`">
              <td class="return-bin-product-col text-start">
                <div class="return-bin-product-cell">
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    alt=""
                    class="return-bin-product-thumb"
                    loading="lazy"
                  />
                  <div v-else class="return-bin-product-thumb return-bin-product-thumb--empty" aria-hidden="true" />
                  <div class="return-bin-product-copy">
                    <a
                      class="return-bin-product-sku return-bin-product-sku--link"
                      :href="inventoryDetailHref(row)"
                      :title="row.sku || undefined"
                      @click.stop
                    >
                      {{ row.sku || "—" }}
                    </a>
                    <div class="return-bin-product-name" :title="row.name || undefined">
                      {{ row.name || "—" }}
                    </div>
                  </div>
                </div>
              </td>
              <td class="text-center text-body">{{ row.qty ?? 0 }}</td>
              <td class="return-bin-pick-col text-start">
                <template v-if="splitPickLocations(row.pick_location).length">
                  <div
                    v-for="(location, index) in splitPickLocations(row.pick_location)"
                    :key="`${row.sku}-pick-${index}`"
                    class="staff-table-cell__meta text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="staff-actions-cell return-bins-actions-cell" @click.stop>
                <div
                  data-return-bin-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': lineMenuKey === rowKey(row) }"
                    aria-haspopup="true"
                    :aria-expanded="lineMenuKey === rowKey(row) ? 'true' : 'false'"
                    aria-label="Row actions"
                    @click.stop="onRowMenuClick(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && !filteredRows.length">
              <td colspan="4" class="px-4 py-5 text-center text-secondary">
                {{
                  productSearch.trim()
                    ? "No items match your search."
                    : "No items in this bin."
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="crm-mobile-item-cards d-lg-none" aria-label="Bin items">
        <div v-if="loading" class="crm-mobile-item-card__empty">
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading bin items…" />
          </div>
        </div>
        <div v-else-if="!filteredRows.length" class="crm-mobile-item-card__empty">
          {{
            productSearch.trim()
              ? "No items match your search."
              : "No items in this bin."
          }}
        </div>
        <template v-else>
          <article
            v-for="row in filteredRows"
            :key="`mobile-${row.sku}-${row.client_account_id}`"
            class="crm-mobile-item-card"
          >
            <div class="crm-mobile-item-card__head">
              <div
                data-return-bin-row-actions
                class="staff-actions-inner staff-actions-inner--single ms-auto"
              >
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': lineMenuKey === rowKey(row) }"
                  aria-haspopup="true"
                  :aria-expanded="lineMenuKey === rowKey(row) ? 'true' : 'false'"
                  aria-label="Row actions"
                  @click.stop="onRowMenuClick(row, $event)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>

            <div class="crm-mobile-item-card__product">
              <img
                v-if="row.image_url"
                :src="row.image_url"
                alt=""
                class="crm-mobile-item-card__thumb"
                loading="lazy"
              />
              <div
                v-else
                class="crm-mobile-item-card__thumb crm-mobile-item-card__thumb--empty"
                aria-hidden="true"
              />
              <div class="crm-mobile-item-card__copy">
                <a
                  class="crm-mobile-item-card__sku return-bin-product-sku--link"
                  :href="inventoryDetailHref(row)"
                  @click.stop
                >
                  {{ row.sku || "—" }}
                </a>
                <div class="crm-mobile-item-card__name">{{ row.name || "—" }}</div>
              </div>
            </div>

            <div class="crm-mobile-item-card__qty">
              <span class="crm-mobile-item-card__qty-label">Qty</span>
              <span class="crm-mobile-item-card__qty-value">{{ formatQty(row.qty) }}</span>
            </div>

            <div class="return-bin-mobile-pick">
              <div class="return-bin-mobile-pick__label">Pick Location</div>
              <template v-if="splitPickLocations(row.pick_location).length">
                <div
                  v-for="(location, index) in splitPickLocations(row.pick_location)"
                  :key="`${row.sku}-m-pick-${index}`"
                  class="return-bin-mobile-pick__loc"
                >
                  {{ location }}
                </div>
              </template>
              <div v-else class="return-bin-mobile-pick__loc text-secondary">—</div>
            </div>

            <div v-if="canTransfer" class="crm-mobile-item-card__footer">
              <button
                type="button"
                class="btn btn-primary btn-sm staff-page-primary fw-semibold w-100"
                @click="openTransferFromMenu(row)"
              >
                Transfer
              </button>
            </div>
          </article>
        </template>
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
          v-if="lineMenuRow"
          data-return-bin-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px` }"
          @click.stop
        >
          <button
            v-if="canTransfer"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openTransferFromMenu(lineMenuRow)"
          >
            Transfer
          </button>
          <button
            v-if="canTransfer"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteFromMenu(lineMenuRow)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>

    <InventoryRestockTransferModal
      :open="transferModalOpen"
      :busy="transferBusy"
      :loading="transferLoading"
      mode="pending"
      :allow-transfer-cart="false"
      :from-options="transferFromOptions"
      v-model:from-location-id="transferFromLocationId"
      :from-empty-label="transferFromEmptyLabel"
      :from-empty-message="transferFromEmptyMessage"
      v-model:destination-mode="transferForm.destination_mode"
      v-model:to-location-id="transferForm.to_location_id"
      v-model:to-location="transferForm.to_location"
      v-model:quantity="transferForm.quantity"
      v-model:reason="transferForm.reason"
      :pick-options="transferPickOptions"
      :reason-options="['Return Restock']"
      @close="transferModalOpen = false"
      @submit="submitTransfer"
      @transfer-all="fillTransferAllQty"
    />

    <ConfirmModal
      :open="deleteOpen"
      form
      danger
      title="Delete From Bin"
      :subtitle="deleteRow?.sku || ''"
      confirm-label="Delete"
      :busy="deleteBusy"
      @close="closeDelete"
      @confirm="confirmDelete"
    >
      <label class="form-label small" for="return-bin-delete-qty">Quantity</label>
      <input
        id="return-bin-delete-qty"
        v-model="deleteQty"
        type="number"
        min="1"
        class="form-control"
        :disabled="deleteBusy"
        :max="deleteRow?.qty || undefined"
      />
      <p class="small text-secondary mt-2 mb-0">
        Removes quantity from this {{ binName || "bin" }} in CRM and ShipHero.
      </p>
    </ConfirmModal>
  </div>
</template>

<style scoped>
.return-bin-product-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.return-bin-product-thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 0.375rem;
  border: 1px solid var(--bs-border-color, #dee2e6);
  flex-shrink: 0;
}

.return-bin-product-thumb--empty {
  background: var(--bs-tertiary-bg, #f8f9fa);
}

.return-bin-product-copy {
  min-width: 0;
}

.return-bin-product-sku {
  display: block;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.return-bin-product-sku--link {
  color: var(--bs-primary, #0d6efd);
  text-decoration: none;
}

.return-bin-product-sku--link:hover {
  text-decoration: underline;
}

.return-bin-product-name {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-size: 0.8125rem;
  color: var(--bs-secondary-color, #6c757d);
}

.return-bin-pick-col {
  min-width: 8rem;
}

.return-bin-mobile-pick {
  padding: 0.5rem 0.75rem 0;
}

.return-bin-mobile-pick__label {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--bs-secondary-color, #6c757d);
  margin-bottom: 0.25rem;
}

.return-bin-mobile-pick__loc {
  font-size: 0.875rem;
  line-height: 1.35;
}
</style>
