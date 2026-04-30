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
const loadNotice = ref("");
const activeLoadKey = ref("");
const itemSortKey = ref("name");
const itemSortDir = ref("asc");

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

const headingOrderNumber = computed(() => String(order.value?.order_number || "—").replace(/^#\s*/, ""));
const statusClass = computed(() => {
  const raw = String(order.value?.status || "").toLowerCase();
  if (raw.includes("hold") || raw.includes("backorder")) return "text-danger bg-danger-subtle";
  if (raw.includes("ship")) return "text-success bg-success-subtle";
  return "text-secondary bg-secondary-subtle";
});
const sortedItems = computed(() => {
  const rows = Array.isArray(order.value?.items) ? [...order.value.items] : [];
  const dir = itemSortDir.value === "desc" ? -1 : 1;
  rows.sort((a, b) => {
    const av = a?.[itemSortKey.value];
    const bv = b?.[itemSortKey.value];
    if (typeof av === "number" || typeof bv === "number") {
      const na = Number(av ?? 0);
      const nb = Number(bv ?? 0);
      return (na - nb) * dir;
    }
    const sa = String(av ?? "").toLowerCase();
    const sb = String(bv ?? "").toLowerCase();
    return sa.localeCompare(sb) * dir;
  });
  return rows;
});
const taxPercentLabel = computed(() => {
  const subtotal = Number(order.value?.subtotal ?? 0);
  const tax = Number(order.value?.total_tax ?? 0);
  if (!Number.isFinite(subtotal) || subtotal <= 0 || !Number.isFinite(tax)) return "0.00%";
  return `${((tax / subtotal) * 100).toFixed(2)}%`;
});

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

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function sanitizeHistoryHtml(value) {
  const raw = String(value || "");
  if (!raw.trim()) return "—";
  if (typeof window === "undefined" || typeof DOMParser === "undefined") {
    return escapeHtml(raw);
  }

  const allowedTags = new Set(["P", "UL", "OL", "LI", "BR", "STRONG", "EM", "B", "I"]);
  const parser = new DOMParser();
  const doc = parser.parseFromString(raw, "text/html");
  const nodes = [doc.body];

  while (nodes.length > 0) {
    const current = nodes.pop();
    if (!current || !current.childNodes) continue;
    const children = Array.from(current.childNodes);
    for (const child of children) {
      if (child.nodeType === Node.ELEMENT_NODE) {
        const el = child;
        const tag = el.tagName.toUpperCase();
        if (!allowedTags.has(tag)) {
          const replacement = doc.createTextNode(el.textContent || "");
          el.replaceWith(replacement);
          continue;
        }
        Array.from(el.attributes || []).forEach((attr) => {
          el.removeAttribute(attr.name);
        });
        nodes.push(el);
      }
    }
  }

  const cleaned = (doc.body.innerHTML || "").trim();
  return cleaned !== "" ? cleaned : escapeHtml(raw);
}

function toggleItemSort(key) {
  if (itemSortKey.value === key) {
    itemSortDir.value = itemSortDir.value === "asc" ? "desc" : "asc";
    return;
  }
  itemSortKey.value = key;
  itemSortDir.value = "asc";
}

function sortIndicator(key) {
  if (itemSortKey.value !== key) return "↕";
  return itemSortDir.value === "asc" ? "↑" : "↓";
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
  loadNotice.value = "";
  if (!selectedAccountId.value || !orderId.value) {
    order.value = null;
    return;
  }
  const requestKey = `${selectedAccountId.value}:${orderId.value}`;
  if (loading.value && activeLoadKey.value === requestKey) {
    return;
  }
  activeLoadKey.value = requestKey;
  loading.value = true;
  order.value = null;
  try {
    const { data } = await api.get(`/orders/${encodeURIComponent(orderId.value)}`, {
      params: { client_account_id: Number(selectedAccountId.value) },
    });
    order.value = data?.order ?? null;
    if (data?.fallback?.source) {
      loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing summary data from orders list.";
    }
    if (!order.value) {
      loadError.value = "ShipHero returned no order for this id and account.";
      toast.error("Order not found.");
    }
  } catch (e) {
    const cached = fallbackOrderSnapshot();
    if (cached) {
      order.value = cached;
      loadNotice.value = "Live detail endpoint was temporarily unavailable. Showing cached summary from this browser.";
    } else {
      loadError.value = extractErrorMessage(e);
      order.value = null;
    }
    toast.errorFrom(e, "Could not load order details.");
  } finally {
    loading.value = false;
    if (activeLoadKey.value === requestKey) {
      activeLoadKey.value = "";
    }
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
  <div class="staff-page staff-page--wide order-detail-page">
    <div v-if="loading" class="order-detail-page__fullscreen-loading">
      <CrmLoadingSpinner message="Loading order detail..." :center="true" />
    </div>
    <template v-else>
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
      <div>
        <button type="button" class="btn btn-link px-0 text-decoration-none" @click="router.back()">
          ← Orders
        </button>
        <h1 class="h4 mb-1 fw-semibold text-body">Order {{ headingOrderNumber }}</h1>
        <p class="staff-page__intro mb-0">
          <span class="badge rounded-pill fw-medium" :class="statusClass">{{ order?.status || "—" }}</span>
        </p>
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

    <div v-if="!selectedAccountId" class="alert alert-light border mb-0">
      Select an account above to view this order.
    </div>
    <div v-else-if="loadError" class="alert alert-warning small mb-0" role="alert">
      {{ loadError }}
    </div>
    <div v-else-if="!order" class="alert alert-warning mb-0">
      No order data loaded. Choose another account or use Refresh.
    </div>
    <template v-else>
      <div v-if="loadNotice" class="alert alert-warning small mb-4" role="status">
        {{ loadNotice }}
      </div>
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
                    <th class="staff-table-head__th">
                      <button class="order-detail-page__sort-btn" type="button" @click="toggleItemSort('name')">
                        Item <span class="order-detail-page__sort-icon">{{ sortIndicator("name") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th">
                      <button class="order-detail-page__sort-btn" type="button" @click="toggleItemSort('sku')">
                        SKU <span class="order-detail-page__sort-icon">{{ sortIndicator("sku") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity')">
                        Quantity <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity_allocated')">
                        Allocated <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity_allocated") }}</span>
                      </button>
                    </th>
                    <th class="staff-table-head__th text-end">
                      <button class="order-detail-page__sort-btn order-detail-page__sort-btn--right" type="button" @click="toggleItemSort('quantity_pending_fulfillment')">
                        To Ship <span class="order-detail-page__sort-icon">{{ sortIndicator("quantity_pending_fulfillment") }}</span>
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in sortedItems" :key="item.id || item.sku">
                    <td>
                      <div class="order-detail-page__item-cell">
                        <img
                          v-if="item.image_url"
                          :src="item.image_url"
                          alt=""
                          class="order-detail-page__item-thumb"
                          loading="lazy"
                        />
                        <div v-else class="order-detail-page__item-thumb order-detail-page__item-thumb--empty" aria-hidden="true"></div>
                        <span>{{ item.name || "—" }}</span>
                      </div>
                    </td>
                    <td>{{ item.sku || "—" }}</td>
                    <td class="text-end">{{ item.quantity ?? 0 }}</td>
                    <td class="text-end">{{ item.quantity_allocated ?? 0 }}</td>
                    <td class="text-end">{{ item.quantity_pending_fulfillment ?? 0 }}</td>
                  </tr>
                  <tr v-if="!sortedItems.length">
                    <td colspan="5" class="text-center text-secondary py-4">No items</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
              Scroll sideways or swipe to see all columns.
            </p>
            <div class="px-4 py-3 border-top d-flex justify-content-end">
              <dl class="mb-0 small order-detail-page__items-summary">
                <div class="order-detail-page__summary-row"><dt>Subtotal</dt><dd class="text-secondary">{{ sortedItems.length }} items</dd><dd>{{ fmtMoney(order.subtotal) }}</dd></div>
                <div class="order-detail-page__summary-row"><dt>Shipping</dt><dd class="text-secondary"></dd><dd>{{ fmtMoney(order.shipping_cost) }}</dd></div>
                <div class="order-detail-page__summary-row"><dt>Discount</dt><dd class="text-secondary"></dd><dd>{{ fmtMoney(order.total_discounts) }}</dd></div>
                <div class="order-detail-page__summary-row"><dt>Tax</dt><dd class="text-secondary">{{ taxPercentLabel }}</dd><dd>{{ fmtMoney(order.total_tax) }}</dd></div>
                <div class="order-detail-page__summary-row fw-semibold order-detail-page__summary-total"><dt>Total</dt><dd class="text-secondary"></dd><dd>{{ fmtMoney(order.total_price) }}</dd></div>
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
                <div class="small order-detail-page__history-html" v-html="sanitizeHistoryHtml(h.information || '')"></div>
              </div>
              <p v-if="!(order.history || []).length" class="small text-secondary mb-0">No history available.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
            <h3 class="h6 fw-semibold mb-3">Order details</h3>
            <dl class="small mb-3">
              <dt class="text-secondary">Order date</dt>
              <dd>{{ fmtDate(order.order_date) }}</dd>
              <dt class="text-secondary">Required ship date</dt>
              <dd>{{ fmtDate(order.required_ship_date) }}</dd>
              <dt class="text-secondary">Account</dt>
              <dd>{{ order.account || "—" }}</dd>
              <dt class="text-secondary">Email</dt>
              <dd>{{ order.email || "—" }}</dd>
            </dl>

            <h3 class="h6 fw-semibold mb-2 mt-3">Shipping details</h3>
            <dl class="small mb-3">
              <dt class="text-secondary">Carrier</dt>
              <dd>{{ order.shipping_carrier || "—" }}</dd>
              <dt class="text-secondary">Method</dt>
              <dd>{{ order.method || "—" }}</dd>
            </dl>
            <p class="small mb-3">
              {{ order.shipping_address?.address1 || "" }} {{ order.shipping_address?.address2 || "" }}<br />
              {{ order.shipping_address?.city || "" }}, {{ order.shipping_address?.state || "" }} {{ order.shipping_address?.zip || "" }}<br />
              {{ order.shipping_address?.country || "—" }}
            </p>

            <h3 class="h6 fw-semibold mb-2">Billing details</h3>
            <p class="small mb-3">
              {{ order.billing_address?.address1 || "" }} {{ order.billing_address?.address2 || "" }}<br />
              {{ order.billing_address?.city || "" }}, {{ order.billing_address?.state || "" }} {{ order.billing_address?.zip || "" }}<br />
              {{ order.billing_address?.country || "—" }}
            </p>

          </div>
        </div>
      </div>
    </template>
    </template>
  </div>
</template>

<style scoped>
.order-detail-page__fullscreen-loading {
  min-height: 70vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-detail-page__sort-btn {
  background: transparent;
  border: 0;
  padding: 0;
  font: inherit;
  color: inherit;
  text-align: left;
}

.order-detail-page__sort-icon {
  opacity: 0.7;
  font-size: 0.8em;
  margin-left: 0.2rem;
}

.order-detail-page__sort-btn--right {
  width: 100%;
  text-align: right;
}

.order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.order-detail-page__item-thumb {
  width: 32px;
  height: 32px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.order-detail-page__item-thumb--empty {
  background: rgba(0, 0, 0, 0.04);
}

.order-detail-page__history-html :deep(p) {
  margin-bottom: 0.4rem;
}

.order-detail-page__history-html :deep(ul),
.order-detail-page__history-html :deep(ol) {
  margin: 0.25rem 0 0.35rem 1.1rem;
  padding: 0;
}

.order-detail-page__history-html :deep(li) {
  margin-bottom: 0.2rem;
}

.order-detail-page__side-panel {
  position: sticky;
  top: 1rem;
}

.order-detail-page__summary-total {
  border-top: 1px solid rgba(0, 0, 0, 0.08);
  margin-top: 0.35rem;
  padding-top: 0.35rem;
}

.order-detail-page__items-summary {
  min-width: 300px;
}

.order-detail-page__summary-row {
  display: grid;
  grid-template-columns: minmax(90px, 1fr) minmax(60px, auto) minmax(80px, auto);
  align-items: baseline;
  gap: 0.75rem;
  margin-bottom: 0.3rem;
}

.order-detail-page__summary-row dt {
  margin: 0;
}

.order-detail-page__summary-row dd {
  margin: 0;
  text-align: right;
}
</style>
