<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

inject("crmUser", ref(null));
const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(false);
const order = ref(null);
const accounts = ref([]);
const accountsLoading = ref(false);
const selectedAccountId = ref(String(route.query.client_account_id || ""));
const loadError = ref("");

const orderId = computed(() => String(route.params.shipheroOrderId || ""));

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const headingOrderNumber = computed(() => order.value?.order_number || "—");

function fmtMoney(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "—";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(n);
}

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString();
}

function extractErrorMessage(e) {
  const payload = e?.response?.data;
  if (payload && typeof payload === "object") {
    const title = typeof payload.title === "string" ? payload.title.trim() : "";
    const detail = typeof payload.detail === "string" ? payload.detail.trim() : "";
    const ownerAction =
      typeof payload.what_you_should_do === "string"
        ? payload.what_you_should_do.replace(/\*\*/g, "").trim()
        : "";
    if (title && ownerAction) return `${title} ${ownerAction}`;
    if (title && detail) return `${title} ${detail}`;
    if (title) return title;
    if (detail) return detail;
    if (ownerAction) return ownerAction;
  }
  const msg = e?.response?.data?.message;
  if (typeof msg === "string" && msg.trim() !== "") return msg;
  if (e?.message) return String(e.message);
  return "Could not load order details.";
}

function fallbackOrderSnapshot() {
  if (!selectedAccountId.value || !orderId.value) return null;
  const key = `orders.snapshot.${selectedAccountId.value}.${orderId.value}`;
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return null;
    const row = JSON.parse(raw);
    if (!row || typeof row !== "object") return null;
    return {
      id: String(row.id || orderId.value),
      legacy_id: row.legacy_id ?? null,
      order_number: row.order_number || "",
      partner_order_id: "",
      status: row.status || "",
      order_date: row.order_date || null,
      required_ship_date: null,
      account: row.account || "",
      email: row.email || "",
      shipping_carrier: row.shipping_carrier || "",
      method: row.method || "",
      shipping_cost: null,
      subtotal: null,
      total_tax: null,
      total_discounts: null,
      total_price: null,
      shipping_address: { country: row.country || "" },
      billing_address: {},
      items: [],
      history: [],
    };
  } catch (_) {
    return null;
  }
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

async function loadOrder() {
  loadError.value = "";
  if (!selectedAccountId.value || !orderId.value) {
    order.value = null;
    return;
  }
  loading.value = true;
  order.value = null;
  try {
    const { data } = await api.get(`/orders/${encodeURIComponent(orderId.value)}`, {
      params: { client_account_id: Number(selectedAccountId.value) },
    });
    order.value = data?.order ?? null;
    if (!order.value) {
      loadError.value = "ShipHero returned no order for this id and account.";
      toast.error("Order not found.");
    }
  } catch (e) {
    const fallback = fallbackOrderSnapshot();
    if (fallback) {
      order.value = fallback;
      loadError.value = "Live order details are temporarily unavailable. Showing cached summary from the list.";
    } else {
      loadError.value = extractErrorMessage(e);
      order.value = null;
    }
    toast.errorFrom(e, "Could not load order details.");
  } finally {
    loading.value = false;
  }
}

watch(
  () => route.query.client_account_id,
  (q) => {
    const next = q != null && String(q) !== "" ? String(q) : "";
    if (next !== selectedAccountId.value) {
      selectedAccountId.value = next;
    }
  },
);

watch(selectedAccountId, (id) => {
  const current = route.query.client_account_id != null ? String(route.query.client_account_id) : "";
  if (id === current) return;
  const nextQuery = { ...route.query };
  if (id) {
    nextQuery.client_account_id = id;
  } else {
    delete nextQuery.client_account_id;
  }
  router.replace({ query: nextQuery });
});

watch(
  () => [orderId.value, selectedAccountId.value],
  () => {
    if (selectedAccountId.value && orderId.value) {
      loadOrder();
    } else {
      order.value = null;
      loadError.value = "";
    }
  },
  { immediate: true },
);

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero order detail.",
  });
  await loadAccounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
      <div>
        <button type="button" class="btn btn-link px-0 text-decoration-none" @click="router.back()">
          ← Orders
        </button>
        <h1 class="h4 mb-1 fw-semibold text-body">Order #{{ headingOrderNumber }}</h1>
        <p class="staff-page__intro mb-0">{{ order?.status || "—" }}</p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 mb-4">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <div class="flex-grow-1" style="min-width: 280px">
            <label class="form-label small text-secondary mb-1" for="order-detail-account-trigger">Account</label>
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="Select account"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="Select account"
              button-id="order-detail-account-trigger"
            />
          </div>
          <button
            type="button"
            class="btn btn-outline-secondary staff-toolbar-btn align-self-end"
            :disabled="!selectedAccountId || !orderId || loading"
            @click="loadOrder"
          >
            Refresh
          </button>
        </div>
        <p class="small text-secondary mb-0 mt-2">
          Only accounts with a ShipHero customer ID can load orders.
        </p>
      </div>
    </div>

    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading order detail..." :center="true" />
    </div>
    <div v-else-if="!selectedAccountId" class="alert alert-light border mb-0">
      Select an account above to view this order.
    </div>
    <div v-else-if="loadError" class="alert alert-warning small mb-0" role="alert">
      {{ loadError }}
    </div>
    <div v-else-if="!order" class="alert alert-warning mb-0">
      No order data loaded. Choose another account or use Refresh.
    </div>
    <template v-else>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
              <h2 class="h6 mb-0 fw-semibold">Items</h2>
              <span class="small text-secondary">{{ order.items?.length || 0 }} items</span>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th">Item</th>
                    <th class="staff-table-head__th">SKU</th>
                    <th class="staff-table-head__th text-end">Quantity</th>
                    <th class="staff-table-head__th text-end">Allocated</th>
                    <th class="staff-table-head__th text-end">To Ship</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in order.items || []" :key="item.id || item.sku">
                    <td>{{ item.name || "—" }}</td>
                    <td>{{ item.sku || "—" }}</td>
                    <td class="text-end">{{ item.quantity ?? 0 }}</td>
                    <td class="text-end">{{ item.quantity_allocated ?? 0 }}</td>
                    <td class="text-end">{{ item.quantity_pending_fulfillment ?? 0 }}</td>
                  </tr>
                  <tr v-if="!(order.items || []).length">
                    <td colspan="5" class="text-center text-secondary py-4">No items</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
              Scroll sideways or swipe to see all columns.
            </p>
            <div class="px-4 py-3 border-top d-flex justify-content-end">
              <dl class="mb-0 small" style="min-width: 260px">
                <div class="d-flex justify-content-between mb-1"><dt>Subtotal</dt><dd>{{ fmtMoney(order.subtotal) }}</dd></div>
                <div class="d-flex justify-content-between mb-1"><dt>Shipping</dt><dd>{{ fmtMoney(order.shipping_cost) }}</dd></div>
                <div class="d-flex justify-content-between mb-1"><dt>Discount</dt><dd>{{ fmtMoney(order.total_discounts) }}</dd></div>
                <div class="d-flex justify-content-between mb-1"><dt>Tax</dt><dd>{{ fmtMoney(order.total_tax) }}</dd></div>
                <div class="d-flex justify-content-between fw-semibold"><dt>Total</dt><dd>{{ fmtMoney(order.total_price) }}</dd></div>
              </dl>
            </div>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0">
            <div class="px-4 py-3 border-bottom">
              <h2 class="h6 mb-0 fw-semibold">History</h2>
            </div>
            <div class="px-4 py-3">
              <div v-for="(h, i) in order.history || []" :key="`${h.created_at}-${i}`" class="border-bottom py-3">
                <div class="small text-secondary mb-1">{{ fmtDate(h.created_at) }}</div>
                <div class="small">{{ h.information || "—" }}</div>
              </div>
              <p v-if="!(order.history || []).length" class="small text-secondary mb-0">No history available.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <h3 class="h6 fw-semibold mb-3">Order details</h3>
            <dl class="small mb-0">
              <dt class="text-secondary">Order date</dt>
              <dd>{{ fmtDate(order.order_date) }}</dd>
              <dt class="text-secondary">Required ship date</dt>
              <dd>{{ fmtDate(order.required_ship_date) }}</dd>
              <dt class="text-secondary">Account</dt>
              <dd>{{ order.account || "—" }}</dd>
              <dt class="text-secondary">Email</dt>
              <dd>{{ order.email || "—" }}</dd>
              <dt class="text-secondary">Shipping Carrier</dt>
              <dd>{{ order.shipping_carrier || "—" }}</dd>
              <dt class="text-secondary">Method</dt>
              <dd>{{ order.method || "—" }}</dd>
            </dl>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <h3 class="h6 fw-semibold mb-3">Shipping details</h3>
            <p class="small mb-0">
              {{ order.shipping_address?.name || "" }}<br />
              {{ order.shipping_address?.address1 || "" }} {{ order.shipping_address?.address2 || "" }}<br />
              {{ order.shipping_address?.city || "" }}, {{ order.shipping_address?.state || "" }} {{ order.shipping_address?.zip || "" }}<br />
              {{ order.shipping_address?.country || "—" }}
            </p>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <h3 class="h6 fw-semibold mb-2">Fraud analysis</h3>
            <p class="small text-secondary mb-0">Not available from ShipHero API.</p>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
            <h3 class="h6 fw-semibold mb-2">Attachments</h3>
            <p class="small text-secondary mb-0">Not available from ShipHero API.</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
