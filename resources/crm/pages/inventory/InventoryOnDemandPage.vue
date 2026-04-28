<script setup>
import { computed, inject, onMounted, reactive, ref } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const categories = ["Capsules", "Gummies", "Skin Cream", "Liquids"];
const products = ref([]);
const accounts = ref([]);
const loading = ref(true);
const saving = ref(false);
const deletingId = ref(null);
const modalOpen = ref(false);
const editingProduct = ref(null);

const filters = reactive({
  q: "",
  client_account_id: "",
  category: "",
});

const form = reactive({
  client_account_id: "",
  sku: "",
  name: "",
  category: "Capsules",
  price_dollars: "",
});

const canUpdateInventory = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const k = u.permission_keys;
  return Array.isArray(k) && k.includes("inventory.update");
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | On-Demand Inventory",
    description: "Account On-Demand SKU catalog.",
  });
  await Promise.all([loadProducts(), loadAccounts()]);
});

async function loadProducts() {
  loading.value = true;
  try {
    const params = {};
    if (filters.q.trim()) params.q = filters.q.trim();
    if (filters.client_account_id) params.client_account_id = filters.client_account_id;
    if (filters.category) params.category = filters.category;
    const { data } = await api.get("/inventory/on-demand-products", { params });
    products.value = Array.isArray(data?.products) ? data.products : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load On-Demand products.");
  } finally {
    loading.value = false;
  }
}

async function loadAccounts() {
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  }
}

function resetFilters() {
  filters.q = "";
  filters.client_account_id = "";
  filters.category = "";
  loadProducts();
}

function openCreate() {
  editingProduct.value = null;
  form.client_account_id = "";
  form.sku = "";
  form.name = "";
  form.category = "Capsules";
  form.price_dollars = "";
  modalOpen.value = true;
}

function openEdit(product) {
  editingProduct.value = product;
  form.client_account_id = String(product.client_account_id ?? "");
  form.sku = product.sku ?? "";
  form.name = product.name ?? "";
  form.category = product.category ?? "Capsules";
  form.price_dollars = centsToDollars(product.price_cents);
  modalOpen.value = true;
}

function closeModal() {
  if (!saving.value) modalOpen.value = false;
}

function centsToDollars(cents) {
  const value = Number(cents);
  return Number.isFinite(value) ? (value / 100).toFixed(2) : "";
}

function dollarsToCents(value) {
  const normalized = String(value ?? "").replace(/[$,\s]/g, "");
  const amount = Number(normalized);
  if (!Number.isFinite(amount)) return null;
  return Math.round(amount * 100);
}

async function saveProduct() {
  const priceCents = dollarsToCents(form.price_dollars);
  if (!form.client_account_id) {
    toast.error("Select an account.");
    return;
  }
  if (!form.sku.trim() || !form.name.trim()) {
    toast.error("Enter SKU and name.");
    return;
  }
  if (!priceCents || priceCents < 1) {
    toast.error("Enter a positive price.");
    return;
  }

  saving.value = true;
  try {
    const payload = {
      client_account_id: Number(form.client_account_id),
      sku: form.sku.trim().toUpperCase(),
      name: form.name.trim(),
      category: form.category,
      price_cents: priceCents,
    };
    if (editingProduct.value?.id) {
      await api.patch(`/inventory/on-demand-products/${editingProduct.value.id}`, payload);
      toast.success("On-Demand SKU updated.");
    } else {
      await api.post("/inventory/on-demand-products", payload);
      toast.success("On-Demand SKU added.");
    }
    modalOpen.value = false;
    await loadProducts();
  } catch (e) {
    toast.errorFrom(e, "Could not save On-Demand SKU.");
  } finally {
    saving.value = false;
  }
}

async function deleteProduct(product) {
  if (!product?.id || deletingId.value) return;
  const ok = window.confirm(`Delete ${product.name} (${product.sku})?`);
  if (!ok) return;

  deletingId.value = product.id;
  try {
    await api.delete(`/inventory/on-demand-products/${product.id}`);
    products.value = products.value.filter((p) => p.id !== product.id);
    toast.success("On-Demand SKU deleted.");
  } catch (e) {
    toast.errorFrom(e, "Could not delete On-Demand SKU.");
  } finally {
    deletingId.value = null;
  }
}
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">On-Demand Inventory</h1>
        <p class="text-secondary small mb-0">
          Account SKU catalog used to aggregate on-demand products during billing import.
        </p>
      </div>
      <button
        v-if="canUpdateInventory"
        type="button"
        class="btn btn-primary"
        @click="openCreate"
      >
        Add SKU
      </button>
    </div>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-lg-4">
            <label class="form-label small text-secondary mb-1">Search</label>
            <input
              v-model="filters.q"
              type="search"
              class="form-control"
              placeholder="SKU, name, or account"
              @keyup.enter="loadProducts"
            />
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label small text-secondary mb-1">Account</label>
            <select v-model="filters.client_account_id" class="form-select">
              <option value="">All accounts</option>
              <option v-for="account in accounts" :key="account.id" :value="String(account.id)">
                {{ account.company_name }}
              </option>
            </select>
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label small text-secondary mb-1">Category</label>
            <select v-model="filters.category" class="form-select">
              <option value="">All</option>
              <option v-for="category in categories" :key="category" :value="category">
                {{ category }}
              </option>
            </select>
          </div>
          <div class="col-12 col-lg-2 d-flex gap-2">
            <button type="button" class="btn btn-primary flex-grow-1" @click="loadProducts">
              Filter
            </button>
            <button type="button" class="btn btn-outline-secondary" @click="resetFilters">
              Reset
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading On-Demand SKUs..." :center="true" />
    </div>

    <div v-else class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>SKU</th>
              <th>Account</th>
              <th>Name</th>
              <th>Category</th>
              <th class="text-end">Price</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="products.length === 0">
              <td colspan="6" class="text-center text-secondary py-4">
                No On-Demand SKUs found.
              </td>
            </tr>
            <tr v-for="product in products" :key="product.id">
              <td class="fw-semibold">{{ product.sku }}</td>
              <td>{{ product.account_name || "—" }}</td>
              <td>{{ product.name }}</td>
              <td>{{ product.category }}</td>
              <td class="text-end">{{ formatCents(product.price_cents) }}</td>
              <td class="text-end">
                <div v-if="canUpdateInventory" class="btn-group btn-group-sm">
                  <button type="button" class="btn btn-outline-primary" @click="openEdit(product)">
                    Edit
                  </button>
                  <button
                    type="button"
                    class="btn btn-outline-danger"
                    :disabled="deletingId === product.id"
                    @click="deleteProduct(product)"
                  >
                    Delete
                  </button>
                </div>
                <span v-else class="text-secondary small">View only</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="modalOpen"
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
      >
        <div class="modal-backdrop fade show" @click="closeModal"></div>
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content position-relative">
            <div class="modal-header">
              <h2 class="modal-title h5 mb-0">
                {{ editingProduct ? "Edit On-Demand SKU" : "Add On-Demand SKU" }}
              </h2>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                :disabled="saving"
                @click="closeModal"
              ></button>
            </div>
            <form @submit.prevent="saveProduct">
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label">Account</label>
                  <select v-model="form.client_account_id" class="form-select" required>
                    <option value="">Select account</option>
                    <option
                      v-for="account in accounts"
                      :key="account.id"
                      :value="String(account.id)"
                    >
                      {{ account.company_name }}
                    </option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">SKU</label>
                  <input
                    v-model="form.sku"
                    type="text"
                    class="form-control text-uppercase"
                    maxlength="128"
                    required
                  />
                </div>
                <div class="mb-3">
                  <label class="form-label">Name</label>
                  <input v-model="form.name" type="text" class="form-control" required />
                </div>
                <div class="mb-3">
                  <label class="form-label">Category</label>
                  <select v-model="form.category" class="form-select" required>
                    <option v-for="category in categories" :key="category" :value="category">
                      {{ category }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="form-label">Price</label>
                  <input
                    v-model="form.price_dollars"
                    type="number"
                    class="form-control"
                    min="0.01"
                    step="0.01"
                    placeholder="3.25"
                    required
                  />
                </div>
              </div>
              <div class="modal-footer">
                <button
                  type="button"
                  class="btn btn-outline-secondary"
                  :disabled="saving"
                  @click="closeModal"
                >
                  Cancel
                </button>
                <button type="submit" class="btn btn-primary" :disabled="saving">
                  {{ saving ? "Saving..." : "Save" }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
