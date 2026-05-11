<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const rows = ref([]);
const loading = ref(false);
const hasSearched = ref(false);
const nextCursor = ref(null);
const hasNextPage = ref(false);

const query = reactive({
  shippedDatePreset: "today",
  from: "",
  to: "",
  holdReason: "",
  orderNumber: "",
});

const tabKey = computed(() => String(route.meta?.orderTab || "awaiting"));
const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const showShippedFilters = computed(() => tabKey.value === "shipped");
const showHoldFilter = computed(() => tabKey.value === "on_hold");
const isCustomDate = computed(() => query.shippedDatePreset === "custom");

const tabTitle = computed(() => {
  if (tabKey.value === "awaiting") return "Ready To Ship";
  if (tabKey.value === "on_hold") return "On-Hold";
  if (tabKey.value === "backorder") return "Backorder";
  if (tabKey.value === "shipped") return "Shipped";
  return "Orders";
});

function toDateInput(d) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function buildDateParams() {
  if (!showShippedFilters.value) return {};
  const now = new Date();
  const today = toDateInput(now);
  if (query.shippedDatePreset === "today") return { order_date_from: today, order_date_to: today };
  if (query.shippedDatePreset === "last_7") {
    const d = new Date(now);
    d.setDate(d.getDate() - 6);
    return { order_date_from: toDateInput(d), order_date_to: today };
  }
  if (query.shippedDatePreset === "last_30") {
    const d = new Date(now);
    d.setDate(d.getDate() - 29);
    return { order_date_from: toDateInput(d), order_date_to: today };
  }
  if (query.from && query.to) return { order_date_from: query.from, order_date_to: query.to };
  return {};
}

function buildParams(withCursor = false) {
  const params = {
    client_account_id: clientAccountId.value,
    tab: tabKey.value,
    first: 100,
    ...buildDateParams(),
  };
  if (showHoldFilter.value && query.holdReason) params.hold_reason = query.holdReason;
  if (query.orderNumber) {
    const n = String(query.orderNumber).trim().replace(/^#+/, "");
    if (n) params.order_number = n;
  }
  if (withCursor && nextCursor.value) params.after = nextCursor.value;
  return params;
}

function statusClass(status) {
  if (tabKey.value === "awaiting") return "bg-success-subtle text-success-emphasis";
  const s = String(status || "").toLowerCase();
  if (s.includes("hold")) return "bg-danger-subtle text-danger-emphasis";
  if (s.includes("ship")) return "bg-success-subtle text-success-emphasis";
  if (s.includes("back")) return "bg-warning-subtle text-warning-emphasis";
  return "bg-secondary-subtle text-secondary-emphasis";
}

function normalizedHoldReasonLabel(value) {
  const v = String(value || "")
    .trim()
    .toLowerCase();
  if (v === "fraud" || v === "fraud hold") return "Fraud Hold";
  if (v === "address" || v === "address hold") return "Address Hold";
  if (v === "operator" || v === "operator hold") return "Operator Hold";
  if (v === "payment" || v === "payment hold") return "Payment Hold";
  if (v === "user" || v === "user hold" || v === "client hold") return "User Hold";
  if (v === "shipping" || v === "shipping hold" || v === "shipping method hold") return "Shipping Method Hold";
  return "";
}

function firstHoldReasonLabel(row) {
  const raw = String(row?.hold_reason || "").trim();
  if (!raw) return "";
  return String(raw.split(",")[0] || "").trim();
}

function formatStatus(row) {
  if (tabKey.value === "awaiting") return "Ready To Ship";
  if (tabKey.value === "on_hold") {
    const selected = normalizedHoldReasonLabel(query.holdReason);
    if (selected) return selected;
    return firstHoldReasonLabel(row) || row.status || "—";
  }
  if (tabKey.value === "backorder") {
    const raw = String(row?.status || "").trim();
    return raw !== "" ? raw : "backorder";
  }
  return row.status || "—";
}

function formatDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString();
}

function openOrder(row) {
  const href = router.resolve({
    name: "user-order-detail",
    params: { shipheroOrderId: String(row.id) },
    query: { client_account_id: String(clientAccountId.value) },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
}

async function fetchOrders(reset = true) {
  if (!clientAccountId.value) return;
  loading.value = true;
  if (reset) {
    rows.value = [];
    hasNextPage.value = false;
    nextCursor.value = null;
  }
  try {
    const { data } = await api.get("/orders", { params: buildParams(!reset) });
    const incoming = Array.isArray(data?.rows) ? data.rows : [];
    rows.value = reset ? incoming : [...rows.value, ...incoming];
    hasNextPage.value = Boolean(data?.pagination?.has_next_page);
    nextCursor.value = data?.pagination?.end_cursor || null;
    hasSearched.value = true;
  } catch (e) {
    toast.errorFrom(e, "Could not load orders.");
  } finally {
    loading.value = false;
  }
}

watch(
  () => [tabKey.value, query.shippedDatePreset, query.from, query.to, query.holdReason, query.orderNumber, clientAccountId.value],
  () => {
    fetchOrders(true);
  },
  { immediate: true },
);

onMounted(() => {
  setCrmPageMeta({
    title: `Save Rack | Orders | ${tabTitle.value}`,
    description: "Your account orders.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Orders - {{ tabTitle }}</h1>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div style="min-width: 220px; max-width: 320px">
            <label class="form-label small text-secondary mb-1" for="user-orders-order-number-search">Order Number</label>
            <input
              id="user-orders-order-number-search"
              v-model.trim="query.orderNumber"
              type="search"
              class="form-control"
              placeholder="Search by Order #"
              :disabled="loading"
              autocomplete="off"
            />
          </div>

          <div v-if="showShippedFilters" class="d-flex gap-2 flex-wrap">
            <select v-model="query.shippedDatePreset" class="form-select" style="max-width: 180px">
              <option value="today">Today</option>
              <option value="last_7">Last 7 Days</option>
              <option value="last_30">Last 30 Days</option>
              <option value="custom">Custom range</option>
            </select>
            <template v-if="isCustomDate">
              <input v-model="query.from" type="date" class="form-control" style="max-width: 180px" />
              <input v-model="query.to" type="date" class="form-control" style="max-width: 180px" />
            </template>
          </div>

          <div v-if="showHoldFilter">
            <select v-model="query.holdReason" class="form-select" style="max-width: 220px">
              <option value="">All Hold Reasons</option>
              <option value="fraud">Fraud Hold</option>
              <option value="address">Address Hold</option>
              <option value="operator">Operator Hold</option>
              <option value="payment">Payment Hold</option>
              <option value="user">User Hold</option>
            </select>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th">{{ tabKey === "on_hold" ? "Hold Reason" : "Status" }}</th>
              <th class="staff-table-head__th">Order #</th>
              <th class="staff-table-head__th">Order Date</th>
              <th class="staff-table-head__th">Country</th>
              <th class="staff-table-head__th">Shipping Carrier</th>
              <th class="staff-table-head__th">Method</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="6" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading orders..." />
                </div>
              </td>
            </tr>
            <tr v-else-if="hasSearched && rows.length === 0">
              <td colspan="6" class="text-center text-secondary py-5">No orders found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <span class="badge rounded-pill fw-medium" :class="statusClass(formatStatus(row))">
                  {{ formatStatus(row) }}
                </span>
              </td>
              <td class="fw-semibold">
                <a href="#" class="text-decoration-none" @click.prevent="openOrder(row)">{{ row.order_number || "—" }}</a>
              </td>
              <td>{{ formatDate(row.order_date) }}</td>
              <td>{{ row.country || "—" }}</td>
              <td>{{ row.shipping_carrier || "—" }}</td>
              <td>{{ row.method || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="staff-table-footer card-footer d-flex justify-content-end">
        <button type="button" class="btn btn-outline-secondary" :disabled="loading || !hasNextPage" @click="fetchOrders(false)">
          {{ hasNextPage ? "Load More" : "No more orders" }}
        </button>
      </div>
    </div>
  </div>
</template>
