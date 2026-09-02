<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmMaterialIcon from "../../components/common/CrmMaterialIcon.vue";
import {
  SUPPLY_TYPES,
  supplyTypeIcon,
  supplyTypeLabel,
} from "../../constants/supplies.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin, crmIsPortalUser } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const CART_STORAGE_PREFIX = "crm.resources.supplies.cart";
const NOTE_STORAGE_PREFIX = "crm.resources.supplies.note";

function noteStorageKey() {
  const id = crmUser.value?.id;
  return id != null ? `${NOTE_STORAGE_PREFIX}.${id}` : `${NOTE_STORAGE_PREFIX}.guest`;
}

function loadNoteFromStorage() {
  try {
    return String(localStorage.getItem(noteStorageKey()) || "");
  } catch {
    return "";
  }
}

function saveNoteToStorage(note) {
  try {
    localStorage.setItem(noteStorageKey(), String(note || ""));
  } catch {
    /* ignore */
  }
}

function clearNoteStorage() {
  try {
    localStorage.removeItem(noteStorageKey());
  } catch {
    /* ignore */
  }
}

function cartStorageKey() {
  const id = crmUser.value?.id;
  return id != null ? `${CART_STORAGE_PREFIX}.${id}` : `${CART_STORAGE_PREFIX}.guest`;
}

function loadCartFromStorage() {
  try {
    const raw = localStorage.getItem(cartStorageKey());
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .map((line) => ({
        supply_id: Number(line.supply_id),
        name: String(line.name || ""),
        type: String(line.type || ""),
        type_label: String(line.type_label || supplyTypeLabel(line.type)),
        link: line.link ? String(line.link) : null,
        quantity: Math.max(1, Math.min(99999999, Number(line.quantity) || 1)),
      }))
      .filter((line) => Number.isFinite(line.supply_id) && line.supply_id > 0);
  } catch {
    return [];
  }
}

function saveCartToStorage(lines) {
  try {
    localStorage.setItem(cartStorageKey(), JSON.stringify(lines));
  } catch {
    /* ignore quota / private mode */
  }
}

function clearCartStorage() {
  try {
    localStorage.removeItem(cartStorageKey());
  } catch {
    /* ignore */
  }
}

function isStaffAdminUser(user) {
  if (!user || typeof user !== "object") return false;
  if (
    user.is_admin === true ||
    user.is_admin === 1 ||
    user.is_admin === "1" ||
    user.is_crm_owner === true ||
    user.is_crm_owner === 1 ||
    user.is_crm_owner === "1"
  ) {
    return true;
  }
  return crmIsAdmin(user);
}

const canManageOrders = computed(() => isStaffAdminUser(crmUser.value));

const canSubmitOrder = computed(() => canManageOrders.value);

const canEditHistory = computed(() => canManageOrders.value);

const canViewSupplies = computed(() => {
  const u = crmUser.value;
  if (!u || crmIsPortalUser(u)) return false;
  return true;
});

setCrmPageMeta({
  title: "Save Rack | Supplies",
  description: "Order warehouse and office supplies.",
});

const catalogLoading = ref(true);
const historyLoading = ref(true);
const catalog = ref([]);
const cart = ref(loadCartFromStorage());
const history = ref([]);
const historyQ = ref("");
const historyQDebounced = ref("");
const searchQ = ref("");
const typeFilter = ref("");
const selectedSupplyId = ref(null);
const pickerOpen = ref(false);
const submitting = ref(false);
const savingNote = ref(false);
const orderNote = ref(loadNoteFromStorage());
const historyMenuOpenId = ref(null);
const historyMenuRect = ref({ top: 0, left: 0 });
const historyEditOpen = ref(false);
const historyEditBusy = ref(false);
const historyEditTarget = ref(null);
const historyEditForm = reactive({
  name: "",
  type: "",
  quantity: 1,
  link: "",
});
const deleteHistoryOpen = ref(false);
const deleteHistoryTarget = ref(null);
const deleteHistoryBusy = ref(false);
const searchRoot = ref(null);

const MENU_W = 168;
const MENU_H = 88;

const historyMenuRow = computed(
  () => history.value.find((r) => r.id === historyMenuOpenId.value) ?? null,
);

let historySearchTimer = null;
let cartPersistReady = false;

watch(
  cart,
  (lines) => {
    if (!cartPersistReady) return;
    saveCartToStorage(lines);
  },
  { deep: true },
);

const typeFilterOptions = computed(() => [
  { id: "", name: "All Types" },
  ...SUPPLY_TYPES.map((t) => ({ id: t, name: supplyTypeLabel(t) })),
]);

const filteredCatalog = computed(() => {
  const type = typeFilter.value;
  const q = searchQ.value.trim().toLowerCase();
  return catalog.value.filter((s) => {
    if (type && s.type !== type) return false;
    if (!q) return true;
    const name = String(s.name || "").toLowerCase();
    const label = String(s.type_label || supplyTypeLabel(s.type)).toLowerCase();
    const key = String(s.type || "").toLowerCase();
    return name.includes(q) || label.includes(q) || key.includes(q);
  });
});

const selectedSupply = computed(() => {
  const id = selectedSupplyId.value;
  if (id == null) return null;
  return catalog.value.find((s) => Number(s.id) === Number(id)) || null;
});

const filteredHistory = computed(() => history.value);

watch(historyQDebounced, () => {
  loadHistory();
});

watch(historyQ, (v) => {
  clearTimeout(historySearchTimer);
  historySearchTimer = setTimeout(() => {
    historyQDebounced.value = String(v || "").trim();
  }, 280);
});

watch([searchQ, typeFilter], () => {
  if (selectedSupply.value) {
    const stillVisible = filteredCatalog.value.some(
      (s) => Number(s.id) === Number(selectedSupply.value.id),
    );
    if (!stillVisible) selectedSupplyId.value = null;
  }
});

async function loadCatalog() {
  catalogLoading.value = true;
  try {
    const { data } = await api.get("/admin/supplies", {
      params: { _t: Date.now() },
    });
    catalog.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load supplies catalog.");
    catalog.value = [];
  } finally {
    catalogLoading.value = false;
  }
}

async function loadHistory() {
  historyLoading.value = true;
  try {
    const params = { per_page: 500 };
    const q = historyQDebounced.value.trim();
    if (q) params.q = q;

    const all = [];
    let page = 1;
    let lastPage = 1;
    do {
      const { data } = await api.get("/admin/supply-orders", {
        params: { ...params, page },
      });
      const rows = Array.isArray(data?.data) ? data.data : [];
      all.push(...rows);
      lastPage = Math.max(1, Number(data?.meta?.last_page) || 1);
      page += 1;
    } while (page <= lastPage);

    history.value = all.map((row) => ({
      ...row,
      _originalQty: Number(row.quantity || 0),
    }));
  } catch (e) {
    toast.errorFrom(e, "Could not load order history.");
    history.value = [];
  } finally {
    historyLoading.value = false;
  }
}

function openPicker() {
  if (!canViewSupplies.value || catalogLoading.value) return;
  pickerOpen.value = true;
}

function closePicker() {
  pickerOpen.value = false;
}

function pickSupply(supply) {
  selectedSupplyId.value = supply.id;
  searchQ.value = supply.name;
  closePicker();
}

function addToOrder() {
  if (!canSubmitOrder.value) return;
  let supply = selectedSupply.value;
  if (!supply) {
    const matches = filteredCatalog.value;
    if (matches.length === 1) {
      supply = matches[0];
    } else if (matches.length > 1 && searchQ.value.trim()) {
      toast.error("Select a supply from the list, then click Add to Order.");
      openPicker();
      return;
    } else {
      toast.error("Search and select a supply first.");
      openPicker();
      return;
    }
  }
  const id = Number(supply.id);
  const existing = cart.value.find((line) => Number(line.supply_id) === id);
  if (existing) {
    existing.quantity = Math.min(99999999, Number(existing.quantity || 0) + 1);
  } else {
    cart.value.push({
      supply_id: supply.id,
      name: supply.name,
      type: supply.type,
      type_label: supply.type_label || supplyTypeLabel(supply.type),
      link: supply.link || null,
      quantity: 1,
    });
  }
  selectedSupplyId.value = null;
  searchQ.value = "";
  closePicker();
}

function onSearchKeydown(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    addToOrder();
  } else if (e.key === "Escape") {
    closePicker();
  }
}

function removeFromCart(supplyId) {
  cart.value = cart.value.filter((line) => Number(line.supply_id) !== Number(supplyId));
}

function clampQty(line) {
  let n = parseInt(String(line.quantity), 10);
  if (!Number.isFinite(n) || n < 1) n = 1;
  if (n > 99999999) n = 99999999;
  line.quantity = n;
  saveCartToStorage(cart.value);
}

function openItemLink(link) {
  const url = String(link || "").trim();
  if (!url) return;
  window.open(url, "_blank", "noopener,noreferrer");
}

/** e.g. 9x9x4 → "Size: 9 × 9 × 4 in" */
function sizeSubtitle(name) {
  const m = String(name || "").match(/(\d+)\s*[x×]\s*(\d+)\s*[x×]\s*(\d+)/i);
  if (!m) return "";
  return `Size: ${m[1]} × ${m[2]} × ${m[3]} in`;
}

function historyDisplayName(row) {
  const name = String(row.name || "").trim();
  const type = String(row.type_label || supplyTypeLabel(row.type)).trim();
  if (name && type) return `${name} ${type}`;
  return name || type || "—";
}

function saveOrderNote() {
  savingNote.value = true;
  saveNoteToStorage(orderNote.value);
  toast.success("Note saved.");
  savingNote.value = false;
}

function placeHistoryMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  historyMenuRect.value = { top, left };
}

function closeHistoryMenu() {
  historyMenuOpenId.value = null;
}

async function toggleHistoryMenu(row, e) {
  e?.stopPropagation?.();
  const rowId = row?.id;
  if (historyMenuOpenId.value === rowId) {
    closeHistoryMenu();
    return;
  }
  const btn = e?.currentTarget;
  historyMenuOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeHistoryMenu(btn);
  });
}

function openHistoryEdit(row) {
  closeHistoryMenu();
  historyEditTarget.value = row;
  historyEditForm.name = String(row?.name || "");
  historyEditForm.type = String(row?.type || SUPPLY_TYPES[0] || "");
  historyEditForm.quantity = Math.max(1, Number(row?.quantity) || 1);
  historyEditForm.link = String(row?.link || "");
  historyEditOpen.value = true;
}

async function saveHistoryEdit() {
  const row = historyEditTarget.value;
  if (!row?.id || historyEditBusy.value) return;
  const name = String(historyEditForm.name || "").trim();
  if (!name) {
    toast.error("Item name is required.");
    return;
  }
  if (!historyEditForm.type) {
    toast.error("Select a type.");
    return;
  }
  historyEditBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/supply-order-lines/${row.id}`, {
      name,
      type: historyEditForm.type,
      quantity: Math.max(1, Number(historyEditForm.quantity) || 1),
      link: String(historyEditForm.link || "").trim() || null,
    });
    const idx = history.value.findIndex((r) => r.id === row.id);
    if (idx >= 0) {
      history.value[idx] = {
        ...history.value[idx],
        ...data,
        type_label: data?.type_label || supplyTypeLabel(data?.type),
        _originalQty: Number(data?.quantity || 0),
      };
    }
    toast.success("History updated.");
    historyEditOpen.value = false;
    historyEditTarget.value = null;
  } catch (e) {
    toast.errorFrom(e, "Could not update history.");
  } finally {
    historyEditBusy.value = false;
  }
}

function openDeleteHistory(row) {
  closeHistoryMenu();
  deleteHistoryTarget.value = row;
  deleteHistoryOpen.value = true;
}

async function confirmDeleteHistory() {
  const row = deleteHistoryTarget.value;
  if (!row?.id || deleteHistoryBusy.value) return;
  deleteHistoryBusy.value = true;
  try {
    await api.delete(`/admin/supply-order-lines/${row.id}`);
    history.value = history.value.filter((r) => r.id !== row.id);
    toast.success("History row deleted.");
    deleteHistoryOpen.value = false;
    deleteHistoryTarget.value = null;
  } catch (e) {
    toast.errorFrom(e, "Could not delete history row.");
  } finally {
    deleteHistoryBusy.value = false;
  }
}

function formatHistoryDate(val) {
  if (val == null || val === "") return "—";
  const d = val instanceof Date ? val : new Date(val);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(d);
}

async function submitOrder() {
  if (!canSubmitOrder.value || submitting.value) return;
  if (!cart.value.length) {
    toast.error("Add at least one supply to the order.");
    return;
  }
  for (const line of cart.value) {
    clampQty(line);
  }
  submitting.value = true;
  try {
    const note = String(orderNote.value || "").trim();
    const { data } = await api.post("/admin/supply-orders", {
      lines: cart.value.map((line) => ({
        supply_id: line.supply_id,
        quantity: Number(line.quantity),
      })),
      note: note || undefined,
    });
    cart.value = [];
    clearCartStorage();
    orderNote.value = "";
    clearNoteStorage();
    if (data?.slack_warning) {
      toast.warning(data.slack_warning);
    } else {
      toast.success("Order submitted.");
    }
    await loadHistory();
  } catch (e) {
    toast.errorFrom(e, "Could not submit order.");
  } finally {
    submitting.value = false;
  }
}

function onDocClick(event) {
  if (!searchRoot.value?.contains?.(event.target)) {
    closePicker();
  }
  if (!event.target?.closest?.("[data-supply-history-actions]")) {
    closeHistoryMenu();
  }
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  // Re-load after crmUser is available so the key is user-scoped.
  cart.value = loadCartFromStorage();
  orderNote.value = loadNoteFromStorage();
  cartPersistReady = true;
  await Promise.all([loadCatalog(), loadHistory()]);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(historySearchTimer);
});
</script>

<template>
  <div class="staff-page staff-page--wide resources-supplies">
    <!-- Admin order builder + notes -->
    <section v-if="canManageOrders" class="resources-supplies__panel mb-4">
      <div class="resources-supplies__split">
        <div class="resources-supplies__split-col resources-supplies__split-col--order">
          <div class="mb-3">
            <h1 class="resources-supplies__title mb-1">Supplies needed</h1>
            <p class="resources-supplies__subtitle mb-0">
              Search the catalog, add items to your order, then submit. Submitted orders appear in team history.
            </p>
          </div>

          <div v-if="canViewSupplies" class="resources-supplies__order-tools mb-3">
            <div v-if="canSubmitOrder" class="resources-supplies__submit-row">
              <button
                type="button"
                class="btn btn-primary staff-page-primary resources-supplies__submit fw-semibold"
                :disabled="submitting"
                @click="submitOrder"
              >
                <svg
                  width="16"
                  height="16"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"
                  />
                </svg>
                {{ submitting ? "Submitting…" : "Submit Order" }}
              </button>
            </div>

            <div class="resources-supplies__toolbar">
              <div ref="searchRoot" class="resources-supplies__search">
                <span class="resources-supplies__search-icon" aria-hidden="true">
                  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                  </svg>
                </span>
                <input
                  v-model="searchQ"
                  type="search"
                  class="form-control resources-supplies__search-input"
                  placeholder="Search by name or type..."
                  aria-label="Search supplies"
                  autocomplete="off"
                  :disabled="catalogLoading"
                  @focus="openPicker"
                  @input="openPicker"
                  @keydown="onSearchKeydown"
                />
                <div v-if="pickerOpen" class="resources-supplies__picker" role="listbox">
                  <button
                    v-for="s in filteredCatalog"
                    :key="s.id"
                    type="button"
                    class="resources-supplies__picker-item"
                    :class="{ 'is-selected': Number(selectedSupplyId) === Number(s.id) }"
                    role="option"
                    @click="pickSupply(s)"
                  >
                    <span class="fw-semibold">{{ s.name }}</span>
                    <span class="resources-supplies__badge resources-supplies__badge--sm">
                      {{ s.type_label || supplyTypeLabel(s.type) }}
                    </span>
                  </button>
                  <div v-if="!filteredCatalog.length" class="resources-supplies__picker-empty">
                    No matching supplies.
                  </div>
                </div>
              </div>

              <select
                v-model="typeFilter"
                class="form-select resources-supplies__type"
                aria-label="Filter by type"
                :disabled="catalogLoading"
              >
                <option
                  v-for="opt in typeFilterOptions"
                  :key="opt.id === '' ? 'all' : opt.id"
                  :value="opt.id"
                >
                  {{ opt.name }}
                </option>
              </select>

              <button
                type="button"
                class="btn resources-supplies__add fw-semibold"
                :disabled="catalogLoading"
                @click="addToOrder"
              >
                + Add to Order
              </button>
            </div>
          </div>

          <div v-if="catalogLoading" class="py-4">
            <CrmLoadingSpinner message="Loading catalog…" :center="true" />
          </div>
          <div v-else-if="!cart.length" class="resources-supplies__empty">
            No items in this order yet. Search the catalog and click Add to Order.
          </div>
          <ul v-else class="list-unstyled mb-0 resources-supplies__cart">
            <li
              v-for="line in cart"
              :key="line.supply_id"
              class="resources-supplies__cart-row"
            >
              <div class="resources-supplies__icon" aria-hidden="true">
                <CrmMaterialIcon :name="supplyTypeIcon(line.type)" :size="22" />
              </div>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <button
                    v-if="line.link"
                    type="button"
                    class="btn btn-link p-0 resources-supplies__name"
                    @click="openItemLink(line.link)"
                  >
                    {{ line.name }}
                  </button>
                  <span v-else class="resources-supplies__name">{{ line.name }}</span>
                  <span class="resources-supplies__badge">
                    {{ line.type_label || supplyTypeLabel(line.type) }}
                  </span>
                </div>
                <div v-if="sizeSubtitle(line.name)" class="resources-supplies__size">
                  {{ sizeSubtitle(line.name) }}
                </div>
              </div>
              <div class="resources-supplies__qty">
                <label class="resources-supplies__qty-label" :for="`qty-${line.supply_id}`">
                  QTY
                </label>
                <input
                  :id="`qty-${line.supply_id}`"
                  v-model.number="line.quantity"
                  type="number"
                  min="1"
                  max="99999999"
                  class="form-control resources-supplies__qty-input"
                  @change="clampQty(line)"
                  @blur="clampQty(line)"
                />
              </div>
              <button
                type="button"
                class="btn resources-supplies__trash"
                aria-label="Remove from order"
                @click="removeFromCart(line.supply_id)"
              >
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                  />
                </svg>
              </button>
            </li>
          </ul>
        </div>

        <div class="resources-supplies__split-col resources-supplies__split-col--notes">
          <div class="resources-supplies__notes">
            <h2 class="resources-supplies__title resources-supplies__title--sm mb-1">Notes</h2>
            <p class="resources-supplies__subtitle mb-3">
              Add a note for this order. It is included when you submit and clears after submit.
            </p>
            <label class="form-label" for="supply-order-note">Note</label>
            <textarea
              id="supply-order-note"
              v-model="orderNote"
              class="form-control resources-supplies__notes-input"
              rows="8"
              placeholder="Enter note for this order…"
              :disabled="submitting"
            />
            <div class="d-flex justify-content-end mt-3">
              <button
                type="button"
                class="btn btn-primary btn-sm staff-page-primary fw-semibold w-100"
                :disabled="savingNote || submitting"
                @click="saveOrderNote"
              >
                {{ savingNote ? "Saving…" : "Save Note" }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Order history -->
    <section class="resources-supplies__panel">
      <div class="resources-supplies__history-head mb-3">
        <div class="resources-supplies__history-head-left">
          <h2 class="resources-supplies__title resources-supplies__title--sm mb-1">
            Order history
          </h2>
          <p class="resources-supplies__subtitle mb-3">
            All supply orders submitted by staff.
          </p>
          <div class="resources-supplies__history-search">
            <span class="resources-supplies__search-icon" aria-hidden="true">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
            </span>
            <input
              v-model="historyQ"
              type="search"
              class="form-control resources-supplies__search-input"
              placeholder="Search history…"
              aria-label="Search order history"
              autocomplete="off"
            />
          </div>
        </div>
      </div>

      <div v-if="historyLoading" class="py-4">
        <CrmLoadingSpinner message="Loading history…" :center="true" />
      </div>
      <div v-else class="table-responsive">
        <table class="table align-middle mb-0 resources-supplies__history-table">
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Ordered By</th>
              <th scope="col">Item Name</th>
              <th scope="col">QTY</th>
              <th v-if="canEditHistory" scope="col" class="staff-actions-col text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredHistory.length">
              <td :colspan="canEditHistory ? 5 : 4" class="text-center text-secondary py-4">
                No orders yet.
              </td>
            </tr>
            <tr v-for="row in filteredHistory" :key="row.id">
              <td class="text-nowrap">{{ formatHistoryDate(row.submitted_at) }}</td>
              <td class="text-nowrap">{{ row.submitted_by_name || "—" }}</td>
              <td>
                <button
                  v-if="row.link"
                  type="button"
                  class="btn btn-link p-0 resources-supplies__history-link"
                  @click="openItemLink(row.link)"
                >
                  {{ historyDisplayName(row) }}
                </button>
                <span v-else>{{ historyDisplayName(row) }}</span>
                <div
                  v-if="row.order_note"
                  class="resources-supplies__history-note small text-secondary mt-1"
                >
                  Note: {{ row.order_note }}
                </div>
              </td>
              <td>{{ row.quantity }}</td>
              <td v-if="canEditHistory" class="staff-actions-cell text-center">
                <div
                  data-supply-history-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': historyMenuOpenId === row.id }"
                    :aria-expanded="historyMenuOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleHistoryMenu(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Teleport to="body">
      <div
        v-if="historyMenuRow"
        data-supply-history-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${historyMenuRect.top}px`,
          left: `${historyMenuRect.left}px`,
          minWidth: `${MENU_W}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openHistoryEdit(historyMenuRow)"
        >
          Edit
        </button>
        <hr class="staff-row-menu__divider" />
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteHistory(historyMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="historyEditOpen"
        class="crm-vx-modal-overlay"
        @click.self="historyEditOpen = false"
      >
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <header class="crm-vx-modal__head" style="text-align: left">
            <h2 class="crm-vx-modal__title">Edit History</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label" for="history-edit-name">Item Name</label>
            <input
              id="history-edit-name"
              v-model="historyEditForm.name"
              type="text"
              class="form-control mb-3"
              :disabled="historyEditBusy"
            />
            <label class="form-label" for="history-edit-type">Type</label>
            <select
              id="history-edit-type"
              v-model="historyEditForm.type"
              class="form-select mb-3"
              :disabled="historyEditBusy"
            >
              <option v-for="t in SUPPLY_TYPES" :key="t" :value="t">
                {{ supplyTypeLabel(t) }}
              </option>
            </select>
            <label class="form-label" for="history-edit-qty">QTY</label>
            <input
              id="history-edit-qty"
              v-model.number="historyEditForm.quantity"
              type="number"
              min="1"
              max="99999999"
              class="form-control mb-3"
              :disabled="historyEditBusy"
            />
            <label class="form-label" for="history-edit-link">Link</label>
            <input
              id="history-edit-link"
              v-model="historyEditForm.link"
              type="url"
              class="form-control"
              placeholder="https://"
              :disabled="historyEditBusy"
            />
          </div>
          <footer class="crm-vx-modal__footer justify-content-end">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="historyEditBusy"
              @click="historyEditOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="historyEditBusy"
              @click="saveHistoryEdit"
            >
              {{ historyEditBusy ? "Saving…" : "Save" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <ConfirmModal
      :open="deleteHistoryOpen"
      title="Delete History Row?"
      :message="
        deleteHistoryTarget
          ? `Delete ${historyDisplayName(deleteHistoryTarget)} from order history? This cannot be undone.`
          : 'Delete this history row?'
      "
      confirm-label="Delete"
      :busy="deleteHistoryBusy"
      danger
      @close="deleteHistoryOpen = false"
      @confirm="confirmDeleteHistory"
    />
  </div>
</template>

<style scoped>
.resources-supplies {
  width: 100%;
}
.resources-supplies__panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.85rem;
  padding: 1.35rem 1.5rem 1.5rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.resources-supplies__title {
  font-size: 1.35rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.resources-supplies__title--sm {
  font-size: 1.15rem;
}
.resources-supplies__subtitle {
  font-size: 0.875rem;
  color: #6b7280;
}
.resources-supplies__split {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
  align-items: start;
}
.resources-supplies__split-col--notes {
  min-height: 100%;
}
.resources-supplies__notes {
  padding: 1rem 1.15rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  background: #f9fafb;
  height: 100%;
}
.resources-supplies__notes-input {
  min-height: 10.5rem;
  resize: vertical;
  border-radius: 0.5rem;
  border-color: #e5e7eb;
}
.resources-supplies__history-head-left {
  min-width: 0;
  max-width: 28rem;
}
.resources-supplies__history-note {
  max-width: 28rem;
  line-height: 1.35;
}
.resources-supplies__submit {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  border-radius: 0.5rem;
  padding: 0.55rem 1rem;
  white-space: nowrap;
}
.resources-supplies__order-tools {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.resources-supplies__submit-row {
  display: flex;
  justify-content: flex-end;
}
.resources-supplies__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: stretch;
  gap: 0.5rem;
}
.resources-supplies__search {
  position: relative;
  flex: 1 1 14rem;
  min-width: 12rem;
  max-width: 100%;
}
.resources-supplies__history-search {
  position: relative;
  width: 100%;
  max-width: 100%;
}
.resources-supplies__search-icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  pointer-events: none;
  display: inline-flex;
  z-index: 1;
}
.resources-supplies__search-input {
  padding-left: 2.35rem;
  border-radius: 0.5rem;
  border-color: #e5e7eb;
  min-height: 2.5rem;
}
.resources-supplies__type {
  flex: 0 0 8.5rem;
  width: 8.5rem;
  max-width: 100%;
  border-radius: 0.5rem;
  border-color: #e5e7eb;
  min-height: 2.5rem;
}
.resources-supplies__add {
  flex: 0 0 auto;
  border-radius: 0.5rem;
  border: 1px solid #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
  min-height: 2.5rem;
  padding: 0.4rem 0.9rem;
  white-space: nowrap;
}
.resources-supplies__add:hover:not(:disabled) {
  background: #dbeafe;
  border-color: #60a5fa;
  color: #1e40af;
}
.resources-supplies__picker {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  z-index: 30;
  max-height: 240px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.55rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
}
.resources-supplies__picker-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  padding: 0.55rem 0.75rem;
  border: 0;
  background: transparent;
  text-align: left;
  font-size: 0.9rem;
  color: #111827;
}
.resources-supplies__picker-item:hover,
.resources-supplies__picker-item.is-selected {
  background: #f3f4f6;
}
.resources-supplies__picker-empty {
  padding: 0.75rem;
  font-size: 0.85rem;
  color: #6b7280;
}
.resources-supplies__empty {
  padding: 1.25rem 0.25rem;
  font-size: 0.875rem;
  color: #6b7280;
}
.resources-supplies__cart {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}
.resources-supplies__cart-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem 1rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.7rem;
  background: #fff;
}
.resources-supplies__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.65rem;
  height: 2.65rem;
  border-radius: 0.55rem;
  background: #f3f4f6;
  color: #2563eb;
  flex-shrink: 0;
}
.resources-supplies__name {
  font-weight: 700;
  font-size: 0.95rem;
  color: #111827;
  text-decoration: none;
  line-height: 1.2;
}
button.resources-supplies__name:hover {
  color: #2563eb;
}
.resources-supplies__badge {
  display: inline-flex;
  align-items: center;
  padding: 0.12rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
  background: #dbeafe;
  color: #1d4ed8;
}
.resources-supplies__badge--sm {
  font-size: 0.68rem;
  padding: 0.08rem 0.45rem;
}
.resources-supplies__size {
  margin-top: 0.2rem;
  font-size: 0.8rem;
  color: #6b7280;
}
.resources-supplies__qty {
  width: 5.25rem;
  flex-shrink: 0;
}
.resources-supplies__qty-label {
  display: block;
  margin-bottom: 0.2rem;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: #6b7280;
}
.resources-supplies__qty-input {
  text-align: center;
  border-radius: 0.45rem;
  border-color: #e5e7eb;
  padding: 0.35rem 0.4rem;
}
.resources-supplies__trash {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.35rem;
  height: 2.35rem;
  padding: 0;
  border-radius: 0.45rem;
  border: 1px solid #fecaca;
  color: #dc2626;
  background: #fff;
  flex-shrink: 0;
}
.resources-supplies__trash:hover {
  background: #fef2f2;
  border-color: #f87171;
  color: #b91c1c;
}
.resources-supplies__history-table {
  --bs-table-bg: transparent;
}
.resources-supplies__history-table > thead > tr > th {
  font-size: 0.78rem;
  font-weight: 600;
  color: #6b7280;
  border-bottom: 1px solid #e5e7eb;
  background: transparent;
  padding: 0.65rem 0.75rem;
}
.resources-supplies__history-table > tbody > tr > td {
  padding: 0.85rem 0.75rem;
  border-bottom: 1px solid #f3f4f6;
  color: #111827;
  font-size: 0.9rem;
}
.resources-supplies__history-link {
  font-weight: 600;
  color: #111827;
  text-decoration: none;
}
.resources-supplies__history-link:hover {
  color: #2563eb;
}
@media (max-width: 991.98px) {
  .resources-supplies__split {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 767.98px) {
  .resources-supplies__search {
    flex: 1 1 10rem;
    min-width: 8rem;
  }
  .resources-supplies__type {
    flex: 1 1 7rem;
    width: auto;
  }
  .resources-supplies__add {
    flex: 1 1 auto;
  }
  .resources-supplies__history-search {
    max-width: none;
  }
}
</style>
