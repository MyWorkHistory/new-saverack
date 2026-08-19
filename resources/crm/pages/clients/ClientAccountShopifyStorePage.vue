<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import AccountDetailSectionHead from "../../components/clients/AccountDetailSectionHead.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  accountId: { type: [String, Number], required: true },
  connectionId: { type: [String, Number], required: true },
});

const router = useRouter();
const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const connection = ref(null);
const locations = ref([]);
let pollTimer = null;

const storeName = computed(() => {
  return connection.value?.shop_name || connection.value?.shop_domain || "Shopify Store";
});

const statusLabel = computed(() => connection.value?.status_label || "Disconnected");

const statusBadgeClass = computed(() => {
  const s = String(connection.value?.status || "");
  if (s === "importing") return "badge bg-warning-subtle text-warning-emphasis";
  if (s === "connected") return "badge bg-success-subtle text-success-emphasis";
  return "badge bg-secondary-subtle text-secondary";
});

const isImporting = computed(() => String(connection.value?.status || "") === "importing");
const canSync = computed(() => locations.value.length > 0 && !isImporting.value && !!connection.value?.has_token);

function accountStoresRoute() {
  return {
    name: "client-account-detail",
    params: { id: String(props.accountId) },
    query: { tab: "stores" },
  };
}

async function load() {
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}`,
    );
    connection.value = data?.connection || null;
    locations.value = Array.isArray(data?.locations) ? data.locations : [];
    setCrmPageMeta({
      title: `Save Rack | ${storeName.value}`,
      description: "Shopify store locations and sync.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify store.");
  } finally {
    loading.value = false;
  }
}

function startPoll() {
  stopPoll();
  pollTimer = window.setInterval(() => {
    if (!isImporting.value) {
      stopPoll();
      return;
    }
    void load();
  }, 3000);
}

function stopPoll() {
  if (pollTimer) {
    window.clearInterval(pollTimer);
    pollTimer = null;
  }
}

async function patchLocation(row, field, value) {
  const prev = row[field];
  row[field] = value;
  try {
    await api.patch(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/locations/${row.id}`,
      { [field]: value },
    );
  } catch (e) {
    row[field] = prev;
    toast.errorFrom(e, "Could not update location.");
  }
}

async function syncOrders() {
  busy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/sync-orders`,
    );
    toast.success(data?.message || "Synced Orders.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not sync orders.");
  } finally {
    busy.value = false;
  }
}

async function syncProducts() {
  busy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/sync-products`,
    );
    toast.success(data?.message || "Synced Products.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not sync products.");
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Store",
    description: "Shopify store locations and sync.",
  });
  await load();
  if (isImporting.value) startPoll();
});

onUnmounted(() => {
  stopPoll();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      v-if="loading"
      class="p-5 d-flex justify-content-center"
    >
      <CrmLoadingSpinner message="Loading store…" />
    </div>

    <template v-else>
      <header class="order-detail-page__hero mb-4">
        <button
          type="button"
          class="btn btn-link btn-sm text-secondary px-0 py-0 mb-2 text-decoration-none order-detail-page__back-link"
          @click="router.push(accountStoresRoute())"
        >
          &lt; Back To Stores
        </button>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-bold text-body">
                {{ storeName }}
              </h1>
              <span
                class="badge"
                :class="statusBadgeClass"
              >{{ statusLabel }}</span>
            </div>
            <p class="small text-secondary mb-0">
              {{ connection?.shop_domain || "—" }}
            </p>
            <p
              v-if="connection?.last_error"
              class="small text-danger mb-0 mt-2"
            >
              {{ connection.last_error }}
            </p>
            <p
              v-if="isImporting"
              class="small text-primary mb-0 mt-2"
            >
              Importing locations…
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center flex-shrink-0">
            <button
              type="button"
              class="btn btn-outline-primary fw-semibold"
              :disabled="busy || !canSync"
              @click="syncOrders"
            >
              Sync Orders
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="busy || !canSync"
              @click="syncProducts"
            >
              Sync Products
            </button>
          </div>
        </div>
      </header>

      <AccountDetailSectionHead
        title="Locations"
        icon="address"
      />
      <div class="staff-table-card staff-datatable-card">
        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Name</th>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Import Orders</th>
                <th
                  class="staff-table-head__th"
                  scope="col"
                >Sync Inventory</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!locations.length">
                <td
                  colspan="3"
                  class="px-4 py-5 text-center text-secondary"
                >
                  {{ isImporting ? "Importing locations…" : "No locations imported yet." }}
                </td>
              </tr>
              <tr
                v-for="row in locations"
                v-else
                :key="row.id"
                class="align-middle"
              >
                <td class="fw-semibold">{{ row.name || row.shopify_location_id }}</td>
                <td>
                  <div class="form-check form-switch mb-0">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      :id="`import-orders-${row.id}`"
                      :checked="row.import_orders"
                      @change="patchLocation(row, 'import_orders', $event.target.checked)"
                    >
                    <label
                      class="form-check-label"
                      :for="`import-orders-${row.id}`"
                    >Import Orders</label>
                  </div>
                </td>
                <td>
                  <div class="form-check form-switch mb-0">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      :id="`sync-inventory-${row.id}`"
                      :checked="row.sync_inventory"
                      @change="patchLocation(row, 'sync_inventory', $event.target.checked)"
                    >
                    <label
                      class="form-check-label"
                      :for="`sync-inventory-${row.id}`"
                    >Sync Inventory</label>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
