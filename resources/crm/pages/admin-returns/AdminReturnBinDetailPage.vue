<script setup>
import { Transition, computed, inject, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ReturnBinAddQtyModal from "../../components/admin-returns/ReturnBinAddQtyModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();
const crmUser = inject("crmUser", ref(null));

const LINE_MENU_W = 180;
const LINE_MENU_H = 120;

const loading = ref(true);
const rows = ref([]);
const binId = computed(() => Number(route.params.binId || 0));
const binName = ref("");

const lineMenuKey = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });

const transferModalOpen = ref(false);
const transferBusy = ref(false);
const transferLoading = ref(false);
const transferRow = ref(null);
const transferProduct = ref(null);
const transferForm = reactive({
  destination_mode: "current",
  to_location_id: "",
  to_location: "",
  quantity: "",
});

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

function rowKey(row) {
  return `${row.sku}|${row.client_account_id}`;
}

const transferDestinationOptions = computed(() => {
  const product = transferProduct.value;
  if (!product) return [];
  const all = flattenProductLocations(product).filter((loc) => Number(loc.quantity || 0) > 0);
  const pickable = all.filter((loc) => loc.pickable === true);
  return pickable.length ? pickable : all;
});

const transferWarehouseId = computed(() => {
  const selectedId = String(transferForm.to_location_id || "").trim();
  if (selectedId) {
    const match = transferDestinationOptions.value.find(
      (loc) => String(loc.location_id || "") === selectedId,
    );
    if (match?.warehouse_id) return String(match.warehouse_id);
  }
  const opts = transferDestinationOptions.value;
  if (opts.length) return String(opts[0].warehouse_id || "");
  const warehouses = Array.isArray(transferProduct.value?.warehouses)
    ? transferProduct.value.warehouses
    : [];
  return warehouses[0]?.warehouse_id ? String(warehouses[0].warehouse_id) : "";
});

function flattenProductLocations(product) {
  const out = [];
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      if (Number(loc?.quantity || 0) <= 0) return;
      out.push({
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
}

function splitPickLocations(text) {
  const raw = String(text || "").trim();
  if (!raw || raw === "—") return [];
  return raw.split(",").map((part) => part.trim()).filter(Boolean);
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

async function load() {
  if (binId.value <= 0) {
    toast.error("Invalid return bin.");
    router.replace({ name: "admin-return-bins" });
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/returns/bins/${binId.value}/items`);
    binName.value = String(data?.bin?.name || "").trim() || `Bin ${binId.value}`;
    rows.value = Array.isArray(data?.data) ? data.data : [];
    setCrmPageMeta({
      title: `Save Rack | ${binName.value}`,
      description: "Items in a return bin awaiting restock.",
    });
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
  transferProduct.value = null;
  transferForm.destination_mode = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferModalOpen.value = true;
  transferLoading.value = true;
  transferBusy.value = false;
  try {
    const { data } = await api.get(`/inventory/products/${encodeURIComponent(row.sku)}`, {
      params: { client_account_id: accountId },
    });
    transferProduct.value = data?.product ?? null;
    if (!transferProduct.value) {
      transferModalOpen.value = false;
      toast.error("Could not load product locations.");
    }
  } catch (e) {
    transferModalOpen.value = false;
    toast.errorFrom(e, "Could not load product for transfer.");
  } finally {
    transferLoading.value = false;
  }
}

function fillTransferAllQty() {
  transferForm.quantity = String(transferRow.value?.qty ?? 0);
}

async function submitTransfer() {
  if (!transferRow.value) return;
  const qty = parseInt(String(transferForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid transfer quantity.");
    return;
  }
  if (transferForm.destination_mode === "current") {
    if (!String(transferForm.to_location_id || "").trim()) {
      toast.error("Select a destination location.");
      return;
    }
  } else if (!transferForm.to_location.trim()) {
    toast.error("Enter destination location.");
    return;
  }
  const warehouseId = transferWarehouseId.value;
  if (!warehouseId) {
    toast.error("Warehouse could not be resolved for this product.");
    return;
  }
  transferBusy.value = true;
  try {
    const body = {
      sku: transferRow.value.sku,
      client_account_id: Number(transferRow.value.client_account_id || 0),
      quantity: qty,
      warehouse_id: warehouseId,
    };
    if (transferForm.destination_mode === "current") {
      body.to_location_id = transferForm.to_location_id;
    } else {
      body.to_location = transferForm.to_location.trim();
    }
    const { data } = await api.post(`/admin/returns/bins/${binId.value}/transfer`, body);
    rows.value = Array.isArray(data?.data) ? data.data : rows.value;
    transferModalOpen.value = false;
    toast.success("Transferred to inventory.");
  } catch (e) {
    toast.errorFrom(e, "Could not transfer item.");
  } finally {
    transferBusy.value = false;
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
  document.removeEventListener("click", onDocumentClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page admin-return-bin-detail-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">{{ binName || "Return Bin" }}</h1>
        <button
          type="button"
          class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
          @click="router.push({ name: 'admin-return-bins' })"
        >
          &lt; Return Bins
        </button>
      </div>
    </div>

    <div class="admin-returns-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Product</th>
              <th class="staff-table-head__th text-center" scope="col">Qty</th>
              <th class="staff-table-head__th" scope="col">Pick Location</th>
              <th class="staff-table-head__th text-center" scope="col">Actions</th>
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
            <tr v-else-if="!rows.length">
              <td colspan="4" class="text-center text-secondary py-5">No items in this bin.</td>
            </tr>
            <tr v-for="row in rows" v-else :key="`${row.sku}-${row.client_account_id}`">
              <td class="order-detail-page__items-col">
                <div class="order-detail-page__item-cell">
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    alt=""
                    class="asn-line-thumb"
                    loading="lazy"
                  />
                  <div v-else class="asn-line-thumb asn-line-thumb--empty" aria-hidden="true" />
                  <div class="order-detail-page__item-copy">
                    <div class="order-detail-page__item-sku fw-semibold" :title="row.sku || undefined">
                      {{ row.sku || "—" }}
                    </div>
                    <div class="order-detail-page__item-name text-secondary small" :title="row.name || undefined">
                      {{ row.name || "—" }}
                    </div>
                  </div>
                </div>
              </td>
              <td class="text-center">{{ row.qty ?? 0 }}</td>
              <td class="return-bin-pick-col">
                <template v-if="splitPickLocations(row.pick_location).length">
                  <div
                    v-for="(location, index) in splitPickLocations(row.pick_location)"
                    :key="`${row.sku}-pick-${index}`"
                    class="small text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="staff-actions-cell text-center" @click.stop>
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
          </tbody>
        </table>
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
        </div>
      </Transition>
    </Teleport>

    <ReturnBinAddQtyModal
      :open="transferModalOpen"
      :busy="transferBusy"
      :loading="transferLoading"
      :bin-name="binName"
      :sku="transferRow?.sku || ''"
      :name="transferRow?.name || ''"
      :available-qty="Number(transferRow?.qty || 0)"
      v-model:destination-mode="transferForm.destination_mode"
      v-model:to-location-id="transferForm.to_location_id"
      v-model:to-location="transferForm.to_location"
      v-model:quantity="transferForm.quantity"
      :destination-options="transferDestinationOptions"
      @close="transferModalOpen = false"
      @submit="submitTransfer"
      @transfer-all="fillTransferAllQty"
    />
  </div>
</template>

<style scoped>
.return-bin-pick-col {
  max-width: 14rem;
}

.admin-return-bin-detail-page .asn-line-thumb {
  width: 48px;
  height: 48px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.admin-return-bin-detail-page .asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.admin-return-bin-detail-page .order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.admin-return-bin-detail-page .order-detail-page__item-copy {
  min-width: 0;
}

.admin-return-bin-detail-page .order-detail-page__item-sku,
.admin-return-bin-detail-page .order-detail-page__item-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
