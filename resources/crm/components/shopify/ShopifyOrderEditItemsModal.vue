<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { formatShopifyOrderName } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  clientAccountId: { type: [Number, String], default: null },
});

const emit = defineEmits(["close", "confirm"]);

const searchQ = ref("");
const searchResults = ref([]);
const searchBusy = ref(false);
const searchOpen = ref(false);
const rowMenuId = ref(null);
let searchTimer = null;

const draftLines = ref([]);
const pendingAdds = ref([]);

const orderLabel = computed(() => formatShopifyOrderName(props.order?.name || props.order?.display_name) || "—");

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    rowMenuId.value = null;
    searchQ.value = "";
    searchResults.value = [];
    pendingAdds.value = [];
    draftLines.value = (Array.isArray(props.order?.line_items) ? props.order.line_items : []).map((li) => ({
      id: li.id,
      title: li.title || "Item",
      sku: li.sku || "",
      image_url: li.image_url || null,
      location: li.location || "—",
      line_status: li.line_status || "pending",
      quantity: Number(li.quantity || 0),
      action: "",
      shopify_variant_id: li.shopify_variant_id || null,
      crm_variant_id: li.crm_variant_id || null,
    }));
  },
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function scheduleSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void runSearch(), 250);
}

async function runSearch() {
  const q = searchQ.value.trim();
  if (q.length < 1) {
    searchResults.value = [];
    return;
  }
  searchBusy.value = true;
  try {
    const params = { q, per_page: 8 };
    if (props.clientAccountId) params.client_account_id = props.clientAccountId;
    const { data } = await api.get("/shopify/inventory", { params });
    searchResults.value = Array.isArray(data?.data) ? data.data : [];
    searchOpen.value = true;
  } catch {
    searchResults.value = [];
  } finally {
    searchBusy.value = false;
  }
}

function addVariant(v) {
  const shopifyVariantId = String(v.shopify_variant_id || v.id || "");
  pendingAdds.value.push({
    shopify_variant_id: String(v.shopify_variant_id || ""),
    quantity: 1,
    title: v.product_title || v.title || "Item",
    sku: v.sku || "",
    image_url: v.image_url || null,
    _key: `add-${shopifyVariantId}-${pendingAdds.value.length}`,
  });
  searchQ.value = "";
  searchResults.value = [];
  searchOpen.value = false;
}

function setAction(line, action) {
  line.action = action;
  if (action === "cancel") line.line_status = "cancelled";
  if (action === "fulfilled") line.line_status = "fulfilled";
  rowMenuId.value = null;
}

function onConfirm() {
  if (props.busy) return;
  emit("confirm", {
    lines: draftLines.value.map((li) => ({
      id: li.id,
      quantity: Number(li.quantity || 0),
      action: li.action || undefined,
    })),
    add: pendingAdds.value.map((a) => ({
      shopify_variant_id: a.shopify_variant_id,
      quantity: Number(a.quantity || 1),
    })),
  });
}

const statusClass = (status) => {
  if (status === "cancelled") return "so-line-status so-line-status--cancelled";
  if (status === "fulfilled") return "so-line-status so-line-status--fulfilled";
  return "so-line-status so-line-status--pending";
};

const statusLabel = (status) => {
  if (status === "cancelled") return "Cancelled";
  if (status === "fulfilled") return "Fulfilled";
  return "Pending";
};
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="so-modal-overlay" role="dialog" aria-modal="true" @click.self="onClose">
      <div class="so-modal so-modal--wide" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>
        <h2 class="so-modal__title mb-1">Edit Items</h2>
        <p class="so-modal__lead">Update the items for Order #{{ orderLabel }}</p>

        <div class="so-edit-search d-flex gap-2 mb-3">
          <div class="position-relative flex-grow-1">
            <input
              v-model="searchQ"
              type="search"
              class="form-control"
              placeholder="Search products by name, SKU, or barcode"
              @input="scheduleSearch"
              @focus="searchOpen = true"
            >
            <div v-if="searchOpen && (searchResults.length || searchBusy)" class="so-edit-search__menu">
              <div v-if="searchBusy" class="px-3 py-2 text-secondary small">Searching…</div>
              <button
                v-for="v in searchResults"
                :key="v.id"
                type="button"
                class="so-edit-search__item"
                @click="addVariant(v)"
              >
                <span class="fw-semibold">{{ v.product_title || v.title || "Product" }}</span>
                <span class="text-secondary small">{{ v.sku || "—" }}</span>
              </button>
            </div>
          </div>
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold text-nowrap" :disabled="busy || !searchResults.length" @click="searchResults[0] && addVariant(searchResults[0])">
            + Add Item
          </button>
        </div>

        <div class="table-responsive so-edit-table-wrap">
          <table class="table align-middle mb-0 so-edit-table">
            <thead>
              <tr>
                <th>Item</th>
                <th style="width: 6rem">Qty</th>
                <th>Location</th>
                <th>Status</th>
                <th style="width: 3rem" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in draftLines" :key="line.id">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img v-if="line.image_url" :src="line.image_url" alt="" class="so-item-thumb">
                    <div class="so-item-thumb so-item-thumb--empty" v-else />
                    <div>
                      <div class="fw-semibold">{{ line.title }}</div>
                      <div class="small text-secondary">SKU: {{ line.sku || "—" }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <input v-model.number="line.quantity" type="number" min="0" class="form-control form-control-sm" :disabled="busy || line.action === 'cancel'">
                </td>
                <td>{{ line.location || "—" }}</td>
                <td><span :class="statusClass(line.line_status)">{{ statusLabel(line.line_status) }}</span></td>
                <td class="position-relative">
                  <button type="button" class="btn btn-sm btn-link text-secondary px-1" :disabled="busy" @click.stop="rowMenuId = rowMenuId === line.id ? null : line.id">⋯</button>
                  <div v-if="rowMenuId === line.id" class="staff-row-menu so-edit-row-menu" @click.stop>
                    <button type="button" class="staff-row-menu__item staff-row-menu__item--danger" @click="setAction(line, 'cancel')">Cancel</button>
                    <button type="button" class="staff-row-menu__item" @click="setAction(line, 'fulfilled')">Fulfilled</button>
                  </div>
                </td>
              </tr>
              <tr v-for="add in pendingAdds" :key="add._key">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img v-if="add.image_url" :src="add.image_url" alt="" class="so-item-thumb">
                    <div class="so-item-thumb so-item-thumb--empty" v-else />
                    <div>
                      <div class="fw-semibold">{{ add.title }} <span class="badge text-bg-primary-subtle text-primary">New</span></div>
                      <div class="small text-secondary">SKU: {{ add.sku || "—" }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <input v-model.number="add.quantity" type="number" min="1" class="form-control form-control-sm" :disabled="busy">
                </td>
                <td>—</td>
                <td><span class="so-line-status so-line-status--pending">Pending</span></td>
                <td>
                  <button type="button" class="btn btn-sm btn-link text-danger px-1" :disabled="busy" @click="pendingAdds = pendingAdds.filter((x) => x._key !== add._key)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="so-modal__foot mt-3">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">Cancel</button>
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold" :disabled="busy" @click="onConfirm">
            {{ busy ? "Updating…" : "Update Order" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.so-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.so-modal {
  position: relative;
  width: 100%;
  max-width: 26rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal--wide { max-width: 52rem; }
.so-modal__close {
  position: absolute;
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 1.4rem;
}
.so-modal__title { margin: 0; font-size: 1.2rem; font-weight: 700; }
.so-modal__lead { margin: 0 0 1rem; color: #4b5563; font-size: 0.95rem; }
.so-modal__foot { display: flex; justify-content: flex-end; gap: 0.55rem; }
.so-edit-search__menu {
  position: absolute;
  z-index: 5;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  max-height: 14rem;
  overflow: auto;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
}
.so-edit-search__item {
  display: flex;
  flex-direction: column;
  width: 100%;
  text-align: left;
  border: 0;
  background: #fff;
  padding: 0.55rem 0.85rem;
}
.so-edit-search__item:hover { background: #f8fafc; }
.so-item-thumb {
  width: 40px;
  height: 40px;
  border-radius: 0.4rem;
  object-fit: cover;
  flex-shrink: 0;
  background: #f3f4f6;
}
.so-item-thumb--empty { border: 1px solid #e5e7eb; }
.so-line-status {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}
.so-line-status--pending { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
.so-line-status--cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
.so-line-status--fulfilled { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
.so-edit-row-menu { position: absolute; right: 0; top: 100%; z-index: 6; min-width: 9rem; }
</style>
