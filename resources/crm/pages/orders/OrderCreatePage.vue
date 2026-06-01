<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref(String(route.query.client_account_id || ""));
const saving = ref(false);

const form = reactive({
  order_number: "",
  shop_name: "",
  shipping_address: {
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    country: "US",
    email: "",
    phone: "",
  },
});

const lineItems = ref([
  { sku: "", quantity: 1, price: "0.00", product_name: "" },
]);

const accountOptions = computed(() =>
  accounts.value
    .filter((a) => a.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const ordersListTo = computed(() => {
  const q = {};
  if (selectedAccountId.value) {
    q.client_account_id = selectedAccountId.value;
  }
  return { path: "/admin/orders/search", query: q };
});

function addLineItem() {
  lineItems.value.push({ sku: "", quantity: 1, price: "0.00", product_name: "" });
}

function removeLineItem(index) {
  if (lineItems.value.length <= 1) return;
  lineItems.value.splice(index, 1);
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
    if (!selectedAccountId.value && accounts.value.length === 1) {
      const only = accounts.value.find((a) => a.has_shiphero_customer);
      if (only) selectedAccountId.value = String(only.id);
    }
    const match = accounts.value.find((a) => String(a.id) === selectedAccountId.value);
    if (match?.company_name && !form.shop_name.trim()) {
      form.shop_name = String(match.company_name).trim();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

async function submit() {
  if (!selectedAccountId.value) {
    toast.error("Select a client account.");
    return;
  }
  saving.value = true;
  try {
    const { data } = await api.post("/orders", {
      client_account_id: Number(selectedAccountId.value),
      order_number: form.order_number.trim(),
      shop_name: form.shop_name.trim(),
      shipping_address: { ...form.shipping_address },
      line_items: lineItems.value.map((row) => ({
        sku: String(row.sku || "").trim(),
        quantity: Number(row.quantity) || 1,
        price: Number(row.price) || 0,
        product_name: String(row.product_name || "").trim() || undefined,
      })),
    });
    toast.success("Order created.");
    await router.push({
      name: "order-detail",
      params: { shipheroOrderId: String(data.shiphero_order_id) },
      query: { client_account_id: String(data.client_account_id) },
    });
  } catch (e) {
    toast.errorFrom(e, "Could not create order.");
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Create Order",
    description: "Create a new ShipHero order for a client account.",
  });
  loadAccounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Create Order</h1>
        <p class="text-secondary small mb-0">
          Create a manual order in ShipHero for the selected client account.
        </p>
      </div>
      <RouterLink
        :to="ordersListTo"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn fw-semibold"
      >
        Back To Orders
      </RouterLink>
    </div>

    <form class="staff-table-card staff-datatable-card staff-datatable-card--white p-3 p-md-4" @submit.prevent="submit">
      <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
          <label class="form-label small text-secondary" for="create-order-account">Account</label>
          <CrmSearchableSelect
            v-model="selectedAccountId"
            appearance="staff"
            aria-label="Client account"
            :options="accountOptions"
            :disabled="accountsLoading || saving"
            placeholder="Select account"
            search-placeholder="Search accounts…"
            :allow-empty="false"
            button-id="create-order-account-trigger"
          />
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small text-secondary" for="create-order-number">Order Number</label>
          <input
            id="create-order-number"
            v-model.trim="form.order_number"
            type="text"
            class="form-control"
            required
            :disabled="saving"
          />
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small text-secondary" for="create-order-shop">Shop Name</label>
          <input
            id="create-order-shop"
            v-model.trim="form.shop_name"
            type="text"
            class="form-control"
            required
            :disabled="saving"
          />
        </div>
      </div>

      <h2 class="h6 fw-semibold text-body mb-3">Shipping Address</h2>
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-first">First Name</label>
          <input id="ship-first" v-model.trim="form.shipping_address.first_name" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-last">Last Name</label>
          <input id="ship-last" v-model.trim="form.shipping_address.last_name" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small" for="ship-company">Company</label>
          <input id="ship-company" v-model.trim="form.shipping_address.company" type="text" class="form-control" :disabled="saving" />
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label small" for="ship-address1">Address</label>
          <input id="ship-address1" v-model.trim="form.shipping_address.address1" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small" for="ship-address2">Address 2</label>
          <input id="ship-address2" v-model.trim="form.shipping_address.address2" type="text" class="form-control" :disabled="saving" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-city">City</label>
          <input id="ship-city" v-model.trim="form.shipping_address.city" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-state">State</label>
          <input id="ship-state" v-model.trim="form.shipping_address.state" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-zip">ZIP</label>
          <input id="ship-zip" v-model.trim="form.shipping_address.zip" type="text" class="form-control" required :disabled="saving" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="ship-country">Country</label>
          <input id="ship-country" v-model.trim="form.shipping_address.country" type="text" class="form-control" required maxlength="8" :disabled="saving" />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small" for="ship-email">Email</label>
          <input id="ship-email" v-model.trim="form.shipping_address.email" type="email" class="form-control" :disabled="saving" />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small" for="ship-phone">Phone</label>
          <input id="ship-phone" v-model.trim="form.shipping_address.phone" type="text" class="form-control" :disabled="saving" />
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h2 class="h6 fw-semibold text-body mb-0">Line Items</h2>
        <button type="button" class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn" :disabled="saving" @click="addLineItem">
          Add Line
        </button>
      </div>

      <div class="table-responsive mb-4">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>SKU</th>
              <th style="width: 7rem">Qty</th>
              <th style="width: 8rem">Price</th>
              <th>Product Name</th>
              <th class="text-end" style="width: 4rem" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in lineItems" :key="idx">
              <td>
                <input v-model.trim="row.sku" type="text" class="form-control form-control-sm" required :disabled="saving" />
              </td>
              <td>
                <input v-model.number="row.quantity" type="number" min="1" class="form-control form-control-sm" required :disabled="saving" />
              </td>
              <td>
                <input v-model="row.price" type="number" min="0" step="0.01" class="form-control form-control-sm" required :disabled="saving" />
              </td>
              <td>
                <input v-model.trim="row.product_name" type="text" class="form-control form-control-sm" :disabled="saving" />
              </td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-link btn-sm text-danger text-decoration-none p-0"
                  :disabled="saving || lineItems.length <= 1"
                  @click="removeLineItem(idx)"
                >
                  Remove
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="d-flex flex-wrap gap-2 justify-content-end">
        <RouterLink :to="ordersListTo" class="btn btn-outline-secondary orders-toolbar-outline-btn" :class="{ disabled: saving }">
          Cancel
        </RouterLink>
        <button type="submit" class="btn btn-primary staff-page-primary" :disabled="saving || accountsLoading">
          {{ saving ? "Creating…" : "Create Order" }}
        </button>
      </div>
    </form>
  </div>
</template>
