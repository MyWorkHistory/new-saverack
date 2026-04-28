<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
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

const orderId = computed(() => String(route.params.shipheroOrderId || ""));

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
  if (!selectedAccountId.value) {
    order.value = null;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get(`/orders/${encodeURIComponent(orderId.value)}`, {
      params: { client_account_id: Number(selectedAccountId.value) },
    });
    order.value = data?.order ?? null;
    if (!order.value) {
      toast.error("Order not found.");
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load order details.");
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero order detail.",
  });
  await loadAccounts();
  await loadOrder();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <button type="button" class="btn btn-link px-0 text-decoration-none" @click="router.back()">
          ← Orders
        </button>
        <h1 class="h4 mb-1 fw-semibold text-body">Order #{{ order?.order_number || "—" }}</h1>
        <p class="staff-page__intro mb-0">{{ order?.status || "—" }}</p>
      </div>
      <div style="min-width: 320px; max-width: 420px" class="w-100">
        <label class="form-label small text-secondary mb-1">Account</label>
        <div class="d-flex gap-2">
          <select v-model="selectedAccountId" class="form-select" :disabled="accountsLoading || loading">
            <option value="">Select account</option>
            <option v-for="a in accounts" :key="a.id" :value="String(a.id)" :disabled="!a.has_shiphero_customer">
              {{ a.company_name }}{{ a.has_shiphero_customer ? "" : " (no ShipHero ID)" }}
            </option>
          </select>
          <button type="button" class="btn btn-outline-secondary" :disabled="!selectedAccountId || loading" @click="loadOrder">
            Load
          </button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading order detail..." :center="true" />
    </div>
    <div v-else-if="!selectedAccountId" class="alert alert-light border">
      Select an account to view this order.
    </div>
    <div v-else-if="!order" class="alert alert-warning">
      Order details are unavailable.
    </div>
    <template v-else>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
              <h2 class="h6 mb-0 fw-semibold">Items</h2>
              <span class="small text-secondary">{{ order.items?.length || 0 }} items</span>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Item</th>
                    <th>SKU</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Allocated</th>
                    <th class="text-end">To Ship</th>
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

