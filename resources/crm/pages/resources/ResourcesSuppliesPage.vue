<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmMaterialIcon from "../../components/common/CrmMaterialIcon.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import {
  SUPPLY_TYPES,
  supplyTypeIcon,
  supplyTypeLabel,
} from "../../constants/supplies.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(
  () =>
    userHasPerm("resources_supplies.create") ||
    userHasPerm("resources.create") ||
    userHasPerm("resources_supplies.update") ||
    userHasPerm("resources.update"),
);

setCrmPageMeta({
  title: "Save Rack | Supplies",
  description: "Order warehouse and office supplies.",
});

const catalogLoading = ref(true);
const historyLoading = ref(true);
const catalog = ref([]);
const cart = ref([]);
const history = ref([]);
const historyQ = ref("");
const historyQDebounced = ref("");
const selectedSupplyId = ref("");
const typeFilter = ref("");
const submitting = ref(false);

let historySearchTimer = null;

const typeFilterOptions = computed(() => [
  { id: "", name: "All Types" },
  ...SUPPLY_TYPES.map((t) => ({ id: t, name: supplyTypeLabel(t) })),
]);

const filteredCatalog = computed(() => {
  const type = typeFilter.value;
  if (!type) return catalog.value;
  return catalog.value.filter((s) => s.type === type);
});

const pickerOptions = computed(() =>
  filteredCatalog.value.map((s) => ({
    id: s.id,
    name: `${s.name} (${s.type_label || supplyTypeLabel(s.type)})`,
  })),
);

const filteredHistory = computed(() => {
  const q = historyQDebounced.value.trim().toLowerCase();
  if (!q) return history.value;
  return history.value.filter((row) => {
    const name = String(row.name || "").toLowerCase();
    const type = String(row.type || "").toLowerCase();
    const label = String(row.type_label || supplyTypeLabel(row.type)).toLowerCase();
    const display = String(row.display_name || "").toLowerCase();
    return (
      name.includes(q) ||
      type.includes(q) ||
      label.includes(q) ||
      display.includes(q)
    );
  });
});

watch(historyQ, (v) => {
  clearTimeout(historySearchTimer);
  historySearchTimer = setTimeout(() => {
    historyQDebounced.value = String(v || "").trim();
  }, 280);
});

watch(typeFilter, () => {
  const id = selectedSupplyId.value;
  if (!id) return;
  if (!filteredCatalog.value.some((s) => String(s.id) === String(id))) {
    selectedSupplyId.value = "";
  }
});

async function loadCatalog() {
  catalogLoading.value = true;
  try {
    const { data } = await api.get("/admin/supplies");
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
    const { data } = await api.get("/admin/supply-orders", {
      params: { per_page: 100 },
    });
    history.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load order history.");
    history.value = [];
  } finally {
    historyLoading.value = false;
  }
}

function addToOrder() {
  if (!canCreate.value) return;
  const id = Number(selectedSupplyId.value);
  if (!id) {
    toast.error("Select a supply to add.");
    return;
  }
  const supply = catalog.value.find((s) => Number(s.id) === id);
  if (!supply) {
    toast.error("Supply not found.");
    return;
  }
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
  selectedSupplyId.value = "";
}

function removeFromCart(supplyId) {
  cart.value = cart.value.filter((line) => Number(line.supply_id) !== Number(supplyId));
}

function clampQty(line) {
  let n = parseInt(String(line.quantity), 10);
  if (!Number.isFinite(n) || n < 1) n = 1;
  if (n > 99999999) n = 99999999;
  line.quantity = n;
}

function openItemLink(link) {
  const url = String(link || "").trim();
  if (!url) return;
  window.open(url, "_blank", "noopener,noreferrer");
}

function historyDisplayName(row) {
  const name = String(row.name || "").trim();
  const type = String(row.type_label || supplyTypeLabel(row.type)).trim();
  if (name && type) return `${name} ${type}`;
  return name || type || "—";
}

async function submitOrder() {
  if (!canCreate.value || submitting.value) return;
  if (!cart.value.length) {
    toast.error("Add at least one supply to the order.");
    return;
  }
  for (const line of cart.value) {
    clampQty(line);
  }
  submitting.value = true;
  try {
    const { data } = await api.post("/admin/supply-orders", {
      lines: cart.value.map((line) => ({
        supply_id: line.supply_id,
        quantity: Number(line.quantity),
      })),
    });
    cart.value = [];
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

onMounted(async () => {
  await Promise.all([loadCatalog(), loadHistory()]);
});
</script>

<template>
  <div class="staff-page staff-page--wide resources-supplies">
    <section class="resources-supplies__card mb-4">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="min-w-0">
          <h1 class="h4 mb-1 fw-semibold text-body">Supplies needed</h1>
          <p class="text-secondary small mb-0">
            Search and add the packaging supplies you need for this order.
          </p>
        </div>
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold d-inline-flex align-items-center gap-2"
          :disabled="submitting || !cart.length"
          @click="submitOrder"
        >
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
          </svg>
          {{ submitting ? "Submitting…" : "Submit Order" }}
        </button>
      </div>

      <div
        v-if="canCreate"
        class="d-flex flex-column flex-lg-row align-items-stretch gap-2 mb-3"
      >
        <div class="flex-grow-1" style="min-width: 12rem">
          <CrmSearchableSelect
            v-model="selectedSupplyId"
            :options="pickerOptions"
            appearance="staff"
            placeholder="Search by name or type…"
            search-placeholder="Search by name or type…"
            empty-label="Select a supply…"
            aria-label="Search supplies"
            :disabled="catalogLoading"
            teleport-panel
          />
        </div>
        <div style="min-width: 10rem; max-width: 14rem">
          <select
            v-model="typeFilter"
            class="form-select"
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
        </div>
        <button
          type="button"
          class="btn btn-outline-primary fw-semibold text-nowrap"
          :disabled="catalogLoading || !selectedSupplyId"
          @click="addToOrder"
        >
          + Add to Order
        </button>
      </div>

      <div v-if="catalogLoading" class="py-4">
        <CrmLoadingSpinner message="Loading catalog…" :center="true" />
      </div>
      <div v-else-if="!cart.length" class="text-secondary small py-3">
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
                class="btn btn-link p-0 fw-semibold text-decoration-none text-start resources-supplies__name-btn"
                @click="openItemLink(line.link)"
              >
                {{ line.name }}
              </button>
              <span v-else class="fw-semibold">{{ line.name }}</span>
              <span class="resources-supplies__badge">
                {{ line.type_label || supplyTypeLabel(line.type) }}
              </span>
            </div>
          </div>
          <div class="resources-supplies__qty">
            <label class="form-label small mb-1 text-secondary" :for="`qty-${line.supply_id}`">
              QTY
            </label>
            <input
              :id="`qty-${line.supply_id}`"
              v-model.number="line.quantity"
              type="number"
              min="1"
              max="99999999"
              class="form-control form-control-sm resources-supplies__qty-input"
              @change="clampQty(line)"
              @blur="clampQty(line)"
            />
          </div>
          <button
            type="button"
            class="btn btn-outline-danger resources-supplies__trash"
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
    </section>

    <section class="resources-supplies__card">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="min-w-0">
          <h2 class="h5 mb-1 fw-semibold text-body">Order history</h2>
          <p class="text-secondary small mb-0">View your previous supply orders.</p>
        </div>
        <input
          v-model="historyQ"
          type="search"
          class="form-control staff-toolbar-search"
          style="max-width: 280px"
          placeholder="Search history…"
          aria-label="Search order history"
          autocomplete="off"
        />
      </div>

      <div v-if="historyLoading" class="py-4">
        <CrmLoadingSpinner message="Loading history…" :center="true" />
      </div>
      <div v-else class="table-responsive">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th" scope="col">Item Name</th>
              <th class="staff-table-head__th" scope="col">QTY</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredHistory.length">
              <td colspan="3" class="text-center text-secondary py-4">
                No orders yet.
              </td>
            </tr>
            <tr v-for="row in filteredHistory" :key="row.id">
              <td class="text-nowrap">
                {{ formatDateTimeUs(row.submitted_at) || "—" }}
              </td>
              <td>
                <button
                  v-if="row.link"
                  type="button"
                  class="btn btn-link p-0 text-decoration-none text-start fw-semibold"
                  @click="openItemLink(row.link)"
                >
                  {{ historyDisplayName(row) }}
                </button>
                <span v-else>{{ historyDisplayName(row) }}</span>
              </td>
              <td>{{ row.quantity }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.resources-supplies__card {
  background: #fff;
  border: 1px solid var(--bs-border-color, #dee2e6);
  border-radius: 0.75rem;
  padding: 1.25rem 1.35rem;
}
.resources-supplies__cart {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}
.resources-supplies__cart-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 0.65rem;
  background: #fff;
}
.resources-supplies__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
  background: #f3f4f6;
  color: #2563eb;
  flex-shrink: 0;
}
.resources-supplies__badge {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  background: #dbeafe;
  color: #1d4ed8;
}
.resources-supplies__name-btn {
  color: inherit;
}
.resources-supplies__name-btn:hover {
  color: #2563eb;
}
.resources-supplies__qty {
  width: 5.5rem;
  flex-shrink: 0;
}
.resources-supplies__qty-input {
  text-align: center;
}
.resources-supplies__trash {
  padding: 0.35rem 0.45rem;
  flex-shrink: 0;
}
</style>
