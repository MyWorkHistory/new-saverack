<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  accountId: { type: [String, Number], required: true },
  connectionId: { type: [String, Number], required: true },
  canEdit: { type: Boolean, default: false },
});

const router = useRouter();
const toast = useToast();
const loading = ref(true);
const connection = ref(null);
const locations = ref([]);
const orderNumber = ref("");
const syncOrdersBusy = ref(false);
const importOrderBusy = ref(false);
const syncProductsBusy = ref(false);
const disconnectBusy = ref(false);
let pollTimer = null;

const shopUrl = computed(() => {
  const domain = String(connection.value?.shop_domain || "").trim();
  if (!domain) return "";
  return domain.startsWith("http") ? domain : `https://${domain}`;
});

const isImporting = computed(() => String(connection.value?.status || "") === "importing");
const isConnected = computed(() => String(connection.value?.status || "") === "connected");
const canRunSync = computed(
  () => props.canEdit && !!connection.value?.has_token && !isImporting.value && locations.value.length > 0,
);
const statusLabel = computed(() => connection.value?.status_label || "Disconnected");

function backToStores() {
  router.push({
    name: "client-account-detail",
    params: { id: String(props.accountId) },
    query: { tab: "stores" },
  });
}

async function load() {
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}`,
    );
    connection.value = data?.connection || null;
    locations.value = Array.isArray(data?.locations) ? data.locations : [];
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
  syncOrdersBusy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/sync-orders`,
    );
    toast.success(data?.message || "Synced Orders.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not sync orders.");
  } finally {
    syncOrdersBusy.value = false;
  }
}

async function importOrder() {
  const num = String(orderNumber.value || "").trim();
  if (!num) {
    toast.error("Enter an order number.");
    return;
  }
  importOrderBusy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/sync-orders`,
      { order_number: num },
    );
    toast.success(data?.message || "Imported Order.");
    orderNumber.value = "";
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not import order.");
  } finally {
    importOrderBusy.value = false;
  }
}

async function syncProducts() {
  syncProductsBusy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}/sync-products`,
    );
    toast.success(data?.message || "Synced Products.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not sync products.");
  } finally {
    syncProductsBusy.value = false;
  }
}

async function disconnectStore() {
  disconnectBusy.value = true;
  try {
    await api.delete(
      `/client-accounts/${props.accountId}/shopify-connections/${props.connectionId}`,
    );
    toast.success("Shopify disconnected.");
    backToStores();
  } catch (e) {
    toast.errorFrom(e, "Could not disconnect Shopify.");
    disconnectBusy.value = false;
  }
}

onMounted(async () => {
  await load();
  if (isImporting.value) startPoll();
});

onUnmounted(() => {
  stopPoll();
});
</script>

<template>
  <div class="shopify-store-settings">
    <div
      v-if="loading"
      class="d-flex justify-content-center py-5"
    >
      <CrmLoadingSpinner message="Loading store…" />
    </div>

    <template v-else>
      <div class="shopify-store-settings__card">
        <div class="shopify-store-settings__head">
          <div class="min-w-0">
            <h2 class="shopify-store-settings__title">Store Settings</h2>
            <p class="shopify-store-settings__lede mb-0">
              Connect and manage your store integration, sync orders and products.
            </p>
          </div>
          <button
            type="button"
            class="shopify-store-settings__back"
            @click="backToStores"
          >
            Back To Stores
          </button>
        </div>

        <section class="shopify-store-settings__section">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span
              class="shopify-store-settings__shopify-mark"
              aria-hidden="true"
            >
              <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="currentColor"
              >
                <path d="M15.2 3.4c-.1-.5-.4-.6-.8-.6s-2.1.6-2.1.6-1.5-.5-1.9-.5c-.8 0-1.6.5-2.1 1.3-.7 1.1-.6 3 .2 5.1-.5.1-1.2.3-1.7.3-.4 0-.7-.1-.8-.5L5 7.2S3.2 12.2 3 12.8c-.2.6.1 1 .6 1.2.5.2 1.4.4 2.3.6.8.2 1.7.4 2.3.4.4 0 .7 0 1-.1.1.4.3.8.5 1.1.6 1 1.6 1.7 2.7 1.7.8 0 1.6-.4 2.1-1 .6.6 1.4 1 2.3 1 1.8 0 3.1-1.5 3.6-4.2.3-1.8.1-3.1-.5-3.7-.5-.5-1.2-.5-1.8-.3.1-.6 0-1.3-.3-1.9-.4-.9-1.1-1.5-1.9-1.5-.3 0-.6.1-.9.3 0 0 1.6-6.2 1.2-6.6z" />
              </svg>
            </span>
            <h3 class="shopify-store-settings__section-title mb-0">Connected Store</h3>
            <span
              class="shopify-store-settings__status"
              :class="{
                'shopify-store-settings__status--on': isConnected,
                'shopify-store-settings__status--wait': isImporting,
              }"
            >{{ statusLabel }}</span>
          </div>
          <p
            v-if="connection?.last_error"
            class="small text-danger mb-3"
          >
            {{ connection.last_error }}
          </p>
          <label
            class="shopify-store-settings__label"
            for="shopify-store-url"
          >Shopify Store URL</label>
          <div class="shopify-store-settings__row">
            <input
              id="shopify-store-url"
              class="form-control shopify-store-settings__input"
              type="text"
              :value="shopUrl"
              placeholder="https://your-store.myshopify.com"
              readonly
            >
            <button
              type="button"
              class="btn staff-page-primary shopify-store-settings__btn"
              :disabled="!canRunSync || syncOrdersBusy"
              @click="syncOrders"
            >
              <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"
                />
              </svg>
              {{ syncOrdersBusy ? "Syncing…" : "Sync Orders" }}
            </button>
          </div>
          <p class="shopify-store-settings__hint mb-0">
            This will sync all unfulfilled orders from the last 30 days.
          </p>
        </section>

        <section class="shopify-store-settings__section">
          <h3 class="shopify-store-settings__section-title">Import Order</h3>
          <p class="shopify-store-settings__lede">
            Import a specific order by entering the order number.
          </p>
          <label
            class="shopify-store-settings__label"
            for="shopify-import-order"
          >Order Number</label>
          <div class="shopify-store-settings__row">
            <input
              id="shopify-import-order"
              v-model="orderNumber"
              class="form-control shopify-store-settings__input"
              type="text"
              placeholder="Enter order number (e.g. #1001)"
              :disabled="!canRunSync || importOrderBusy"
              @keydown.enter.prevent="importOrder"
            >
            <button
              type="button"
              class="btn staff-page-primary shopify-store-settings__btn"
              :disabled="!canRunSync || importOrderBusy"
              @click="importOrder"
            >
              <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                />
              </svg>
              {{ importOrderBusy ? "Importing…" : "Import Order" }}
            </button>
          </div>
        </section>

        <section class="shopify-store-settings__section">
          <h3 class="shopify-store-settings__section-title">Products</h3>
          <p class="shopify-store-settings__lede">
            Sync products from your store to Save Rack.
          </p>
          <button
            type="button"
            class="btn staff-page-primary shopify-store-settings__btn"
            :disabled="!canRunSync || syncProductsBusy"
            @click="syncProducts"
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"
              />
            </svg>
            {{ syncProductsBusy ? "Syncing…" : "Sync All Products" }}
          </button>
        </section>
      </div>

      <div class="shopify-store-settings__card mt-3">
        <div class="shopify-store-settings__head shopify-store-settings__head--block">
          <h2 class="shopify-store-settings__title">Shopify Location Settings</h2>
          <p class="shopify-store-settings__lede mb-0">
            Choose which Shopify locations to import orders from and sync inventory.
          </p>
        </div>

        <div class="shopify-loc-table-wrap">
          <table class="shopify-loc-table">
            <thead>
              <tr>
                <th scope="col">Warehouse Location</th>
                <th
                  class="text-center"
                  scope="col"
                >
                  Import Orders From Location
                  <span
                    class="shopify-loc-info"
                    title="When on, unfulfilled orders assigned to this Shopify location are imported into Save Rack."
                  >i</span>
                </th>
                <th
                  class="text-center"
                  scope="col"
                >
                  Sync Inventory
                  <span
                    class="shopify-loc-info"
                    title="When on, CRM inventory quantities are pushed to this Shopify location."
                  >i</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!locations.length">
                <td
                  class="text-secondary py-4"
                  colspan="3"
                >
                  {{ isImporting ? "Importing locations…" : "No locations imported yet." }}
                </td>
              </tr>
              <tr
                v-for="row in locations"
                v-else
                :key="row.id"
              >
                <td>
                  <div class="shopify-loc-name">{{ row.name || row.shopify_location_id }}</div>
                  <div
                    v-if="row.address_line"
                    class="shopify-loc-addr"
                  >{{ row.address_line }}</div>
                </td>
                <td class="text-center">
                  <label class="shopify-loc-switch">
                    <input
                      type="checkbox"
                      :checked="row.import_orders"
                      :disabled="!canEdit"
                      @change="patchLocation(row, 'import_orders', $event.target.checked)"
                    >
                    <span class="shopify-loc-switch__track" />
                    <span class="shopify-loc-switch__label">{{ row.import_orders ? "ON" : "OFF" }}</span>
                  </label>
                </td>
                <td class="text-center">
                  <label class="shopify-loc-switch">
                    <input
                      type="checkbox"
                      :checked="row.sync_inventory"
                      :disabled="!canEdit"
                      @change="patchLocation(row, 'sync_inventory', $event.target.checked)"
                    >
                    <span class="shopify-loc-switch__track" />
                    <span class="shopify-loc-switch__label">{{ row.sync_inventory ? "ON" : "OFF" }}</span>
                  </label>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="shopify-store-settings__disconnect">
          <button
            type="button"
            class="shopify-store-settings__disconnect-btn"
            :disabled="!canEdit || disconnectBusy"
            @click="disconnectStore"
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l2.189 2.19a3.004 3.004 0 01-.621 4.72"
              />
            </svg>
            {{ disconnectBusy ? "Disconnecting…" : "Disconnect Store" }}
          </button>
          <p class="shopify-store-settings__disconnect-note mb-0">
            Disconnecting will stop all order and inventory syncs for this store.
          </p>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.shopify-store-settings__card {
  background: #fff;
  border: 1px solid var(--bs-border-color);
  border-radius: 0.75rem;
  overflow: hidden;
}

.shopify-store-settings__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.5rem 0.25rem;
}

.shopify-store-settings__head--block {
  display: block;
  padding-bottom: 1rem;
}

.shopify-store-settings__title {
  font-size: 1.125rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 0.25rem;
}

.shopify-store-settings__lede {
  color: #6b7280;
  font-size: 0.875rem;
}

.shopify-store-settings__back {
  border: 0;
  background: none;
  color: #2563eb;
  font-weight: 600;
  font-size: 0.875rem;
  padding: 0.25rem 0;
  white-space: nowrap;
}

.shopify-store-settings__back:hover {
  text-decoration: underline;
}

.shopify-store-settings__section {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--bs-border-color);
}

.shopify-store-settings__section:last-child {
  border-bottom: 0;
}

.shopify-store-settings__section-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 0.25rem;
}

.shopify-store-settings__shopify-mark {
  color: #95bf47;
  display: inline-flex;
}

.shopify-store-settings__status {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  background: #e5e7eb;
  color: #4b5563;
}

.shopify-store-settings__status--on {
  background: #dcfce7;
  color: #166534;
}

.shopify-store-settings__status--wait {
  background: #fef3c7;
  color: #92400e;
}

.shopify-store-settings__label {
  display: block;
  font-size: 0.8125rem;
  font-weight: 600;
  color: #374151;
  margin-bottom: 0.4rem;
}

.shopify-store-settings__row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: stretch;
}

.shopify-store-settings__input {
  flex: 1 1 16rem;
  min-width: 12rem;
}

.shopify-store-settings__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 600;
  white-space: nowrap;
}

.shopify-store-settings__hint {
  margin-top: 0.5rem;
  font-size: 0.8125rem;
  color: #6b7280;
}

.shopify-loc-table-wrap {
  overflow-x: auto;
}

.shopify-loc-table {
  width: 100%;
  border-collapse: collapse;
}

.shopify-loc-table th {
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-weight: 700;
  color: #6b7280;
  background: #f3f4f6;
  padding: 0.7rem 1.25rem;
  border-bottom: 1px solid var(--bs-border-color);
  white-space: nowrap;
}

.shopify-loc-table td {
  padding: 0.95rem 1.25rem;
  border-bottom: 1px solid var(--bs-border-color);
  vertical-align: middle;
}

.shopify-loc-name {
  font-weight: 700;
  color: #111827;
}

.shopify-loc-addr {
  font-size: 0.8125rem;
  color: #6b7280;
  margin-top: 0.15rem;
}

.shopify-loc-info {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 0.9rem;
  height: 0.9rem;
  margin-left: 0.25rem;
  border-radius: 50%;
  border: 1px solid #9ca3af;
  color: #6b7280;
  font-size: 0.65rem;
  font-weight: 700;
  font-style: normal;
  vertical-align: 1px;
  cursor: help;
}

.shopify-loc-switch {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin: 0;
  cursor: pointer;
}

.shopify-loc-switch input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.shopify-loc-switch__track {
  width: 2.4rem;
  height: 1.25rem;
  border-radius: 999px;
  background: #d1d5db;
  position: relative;
  flex-shrink: 0;
}

.shopify-loc-switch__track::after {
  content: "";
  position: absolute;
  top: 0.125rem;
  left: 0.125rem;
  width: 1rem;
  height: 1rem;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.shopify-loc-switch input:checked + .shopify-loc-switch__track {
  background: #2563eb;
}

.shopify-loc-switch input:checked + .shopify-loc-switch__track::after {
  left: 1.25rem;
}

.shopify-loc-switch__label {
  font-size: 0.75rem;
  font-weight: 700;
  color: #6b7280;
  min-width: 1.75rem;
}

.shopify-loc-switch input:checked ~ .shopify-loc-switch__label {
  color: #2563eb;
}

.shopify-store-settings__disconnect {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.85rem 1.25rem;
  padding: 1.1rem 1.5rem 1.25rem;
}

.shopify-store-settings__disconnect-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #dc2626;
  color: #dc2626;
  background: #fff;
  border-radius: 0.5rem;
  padding: 0.45rem 0.85rem;
  font-weight: 600;
  font-size: 0.875rem;
}

.shopify-store-settings__disconnect-btn:hover:not(:disabled) {
  background: #fef2f2;
}

.shopify-store-settings__disconnect-note {
  color: #6b7280;
  font-size: 0.8125rem;
}
</style>
