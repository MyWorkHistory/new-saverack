<template>
  <div class="shopify-connection-card border rounded-3 p-3 mt-4">
    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
      <div>
        <h3 class="h6 mb-1">Shopify</h3>
        <p class="small text-secondary mb-0">
          Connect Save Rack Fulfillment to this account’s Shopify store. Inventory quantities stay in
          Shopify tables only (not ShipHero stock).
        </p>
      </div>
      <span
        class="badge"
        :class="statusBadgeClass"
      >
        {{ statusLabel }}
      </span>
    </div>

    <div
      v-if="loading"
      class="py-3"
    >
      <CrmLoadingSpinner message="Loading Shopify…" />
    </div>

    <template v-else>
      <div
        v-if="!oauthConfigured"
        class="alert alert-warning py-2 px-3 small mb-3"
        role="status"
      >
        Shopify OAuth is not configured on this server (missing
        <code>SHOPIFY_CLIENT_ID</code> / <code>SHOPIFY_CLIENT_SECRET</code>).
        You can still paste a custom-app Admin API token under Advanced.
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <label class="form-label small fw-semibold" for="shopify-shop-domain">
            Shopify Store Domain
          </label>
          <input
            id="shopify-shop-domain"
            v-model="form.shop_domain"
            type="text"
            class="form-control form-control-sm"
            placeholder="test-store-wke6tzxl.myshopify.com"
            :disabled="busy || isImporting || !canEdit"
          />
          <p class="small text-secondary mb-0 mt-1">
            Enter the store’s <code>*.myshopify.com</code> host (not the App Client ID).
            Example: <code>test-store-wke6tzxl.myshopify.com</code>
          </p>
        </div>
        <div class="col-md-4 small text-secondary pt-md-4">
          <div v-if="connection?.shop_name">Shop: {{ connection.shop_name }}</div>
          <div v-if="connection?.last_sync_at">Last Sync: {{ connection.last_sync_at }}</div>
          <div
            v-if="isImporting"
            class="text-primary"
          >
            Import did not finish. Click Full Re-Import if this is still showing.
          </div>
          <div
            v-if="connection?.last_error"
            class="text-danger"
          >
            {{ connection.last_error }}
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <button
          v-if="oauthConfigured"
          type="button"
          class="btn btn-sm btn-primary staff-page-primary fw-semibold"
          :disabled="busy || isImporting || !canEdit || !form.shop_domain"
          @click="connectWithShopify"
        >
          {{ busy ? "Redirecting…" : "Connect With Shopify" }}
        </button>
        <button
          v-else
          type="button"
          class="btn btn-sm btn-primary staff-page-primary fw-semibold"
          :disabled="busy || isImporting || !canEdit || !form.shop_domain"
          @click="connectAndImport"
        >
          {{ busy ? "Connecting…" : "Connect And Import" }}
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-primary fw-semibold"
          :disabled="busy || isImporting || !canEdit || !connection?.has_token"
          @click="openResyncModal"
        >
          Re-Sync Orders
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary fw-semibold"
          :disabled="busy || isImporting || !canEdit || !connection?.has_token"
          title="Re-import catalog and open orders (full bootstrap)"
          @click="syncNow"
        >
          Full Re-Import (Orders + Catalog)
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-danger fw-semibold"
          :disabled="busy || isImporting || !canEdit || !connection?.has_token"
          @click="disconnect"
        >
          Disconnect
        </button>
      </div>

      <details class="shopify-connection-advanced border rounded-3 p-3 bg-light">
        <summary class="fw-semibold small user-select-none" style="cursor: pointer">
          Advanced: Paste Admin API Token
        </summary>
        <p class="small text-secondary mt-2 mb-3">
          Use only for custom apps or when OAuth is unavailable. Prefer Connect With Shopify for the
          Save Rack Fulfillment public app.
        </p>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Admin API Access Token</label>
            <input
              v-model="form.admin_api_access_token"
              type="password"
              class="form-control form-control-sm"
              :placeholder="connection?.has_token ? '•••••••• (leave blank to keep)' : 'shpat_…'"
              :disabled="busy || isImporting || !canEdit"
              autocomplete="new-password"
            />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Webhook Secret (Optional)</label>
            <input
              v-model="form.webhook_secret"
              type="password"
              class="form-control form-control-sm"
              placeholder="Shared HMAC secret"
              :disabled="busy || isImporting || !canEdit"
              autocomplete="new-password"
            />
          </div>
        </div>
        <button
          type="button"
          class="btn btn-sm btn-outline-primary fw-semibold"
          :disabled="busy || isImporting || !canEdit || !form.shop_domain"
          @click="connectAndImport"
        >
          {{ busy ? "Connecting…" : "Connect And Import" }}
        </button>
      </details>
    </template>

    <Teleport to="body">
      <Transition name="modal-backdrop">
        <div
          v-if="resyncOpen"
          class="crm-vx-modal-overlay"
          aria-modal="true"
          role="dialog"
          aria-labelledby="shopify-resync-orders-title"
        >
          <div
            class="crm-vx-modal-backdrop"
            aria-hidden="true"
            @click="closeResyncModal"
          />
          <Transition name="modal-panel" appear>
            <div class="crm-vx-modal crm-vx-modal--sm">
              <button
                type="button"
                class="crm-vx-modal__close"
                aria-label="Close"
                :disabled="resyncBusy"
                @click="closeResyncModal"
              >
                <svg
                  width="20"
                  height="20"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.75"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>

              <header class="crm-vx-modal__head">
                <h2 id="shopify-resync-orders-title" class="crm-vx-modal__title">
                  Re-Sync Orders
                </h2>
                <p class="crm-vx-modal__subtitle">
                  Pull orders from Shopify into CRM. Does not change ShipHero stock.
                </p>
              </header>

              <div class="crm-vx-modal__body pt-0">
                <form
                  id="shopify-resync-orders-form"
                  class="d-flex flex-column gap-3"
                  @submit.prevent="submitResync"
                >
                  <div class="form-check">
                    <input
                      id="resync-unfulfilled"
                      v-model="resync.mode"
                      class="form-check-input"
                      type="radio"
                      value="unfulfilled"
                      :disabled="resyncBusy"
                    />
                    <label class="form-check-label" for="resync-unfulfilled">
                      Sync All Unfulfilled Orders
                    </label>
                  </div>
                  <div class="form-check">
                    <input
                      id="resync-after-date"
                      v-model="resync.mode"
                      class="form-check-input"
                      type="radio"
                      value="after_date"
                      :disabled="resyncBusy"
                    />
                    <label class="form-check-label" for="resync-after-date">
                      Sync All After Date
                    </label>
                  </div>
                  <div
                    v-if="resync.mode === 'after_date'"
                    class="ps-4"
                  >
                    <label class="form-label small mb-1 text-secondary" for="resync-date">
                      Created On Or After
                    </label>
                    <input
                      id="resync-date"
                      v-model="resync.after_date"
                      type="date"
                      class="form-control form-control-sm"
                      required
                      :disabled="resyncBusy"
                    />
                  </div>
                  <div class="form-check">
                    <input
                      id="resync-order-number"
                      v-model="resync.mode"
                      class="form-check-input"
                      type="radio"
                      value="order_number"
                      :disabled="resyncBusy"
                    />
                    <label class="form-check-label" for="resync-order-number">
                      Sync Specific Order #
                    </label>
                  </div>
                  <div
                    v-if="resync.mode === 'order_number'"
                    class="ps-4"
                  >
                    <label class="form-label small mb-1 text-secondary" for="resync-order-num">
                      Order Number
                    </label>
                    <input
                      id="resync-order-num"
                      v-model="resync.order_number"
                      type="text"
                      class="form-control form-control-sm"
                      placeholder="#1234"
                      required
                      :disabled="resyncBusy"
                    />
                  </div>
                </form>
              </div>

              <footer class="crm-vx-modal__footer">
                <button
                  type="button"
                  class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                  :disabled="resyncBusy"
                  @click="closeResyncModal"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  form="shopify-resync-orders-form"
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="resyncBusy || !canSubmitResync"
                >
                  {{ resyncBusy ? "Syncing…" : "Re-Sync Orders" }}
                </button>
              </footer>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  accountId: { type: [Number, String], required: true },
  canEdit: { type: Boolean, default: false },
});

const route = useRoute();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const connection = ref(null);
const oauthConfigured = ref(false);
const form = reactive({
  shop_domain: "",
  admin_api_access_token: "",
  webhook_secret: "",
});

const resyncOpen = ref(false);
const resyncBusy = ref(false);
const resync = reactive({
  mode: "unfulfilled",
  after_date: "",
  order_number: "",
});

let pollTimer = null;

const isImporting = computed(() => connection.value?.status === "importing");

const canSubmitResync = computed(() => {
  if (resync.mode === "after_date") return Boolean(resync.after_date);
  if (resync.mode === "order_number") return Boolean(String(resync.order_number || "").trim());
  return true;
});

const statusLabel = computed(() => {
  const s = connection.value?.status || "disconnected";
  if (s === "connected") return "Connected";
  if (s === "importing") return "Importing";
  if (s === "error") return "Error";
  return "Disconnected";
});

const statusBadgeClass = computed(() => {
  const s = connection.value?.status || "disconnected";
  if (s === "connected") return "text-bg-success";
  if (s === "importing") return "text-bg-primary";
  if (s === "error") return "text-bg-danger";
  return "text-bg-secondary";
});

function stopPolling() {
  if (pollTimer != null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function startPollingIfNeeded() {
  stopPolling();
  if (!isImporting.value) return;
  pollTimer = setInterval(() => {
    void refreshQuiet();
  }, 3000);
}

async function refreshQuiet() {
  try {
    const { data } = await api.get(`/client-accounts/${props.accountId}/shopify-connection`);
    const prev = connection.value?.status;
    connection.value = data?.connection || null;
    if (prev === "importing" && connection.value?.status === "connected") {
      toast.success("Shopify Import Completed.");
      stopPolling();
    } else if (prev === "importing" && connection.value?.status === "error") {
      toast.error(connection.value?.last_error || "Shopify Import Failed.");
      stopPolling();
    } else if (connection.value?.status !== "importing") {
      stopPolling();
    }
  } catch {
    // Keep polling; transient errors are fine while workers run.
  }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/client-accounts/${props.accountId}/shopify-connection`);
    connection.value = data?.connection || null;
    oauthConfigured.value = Boolean(data?.oauth_configured);
    form.shop_domain = connection.value?.shop_domain || "";
    form.admin_api_access_token = "";
    form.webhook_secret = "";
    startPollingIfNeeded();
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify connection.");
  } finally {
    loading.value = false;
  }
}

function consumeOauthQueryToast() {
  const status = String(route.query.shopify_oauth || "");
  if (!status) return;
  if (status === "success") {
    toast.success("Shopify Connected.");
    void syncNow();
  } else if (status === "error") {
    const msg = String(route.query.shopify_oauth_message || "").trim();
    toast.error(msg || "Shopify OAuth failed.");
  }
  const nextQuery = { ...route.query };
  delete nextQuery.shopify_oauth;
  delete nextQuery.shopify_oauth_message;
  router.replace({ query: nextQuery });
}

async function connectWithShopify() {
  const shop = String(form.shop_domain || "").trim();
  if (!shop) {
    toast.error("Enter the Shopify store domain (e.g. test-store-wke6tzxl.myshopify.com).");
    return;
  }
  busy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connection/oauth/start`,
      {
        shop_domain: shop,
        import: true,
      },
    );
    const url = String(data?.authorization_url || "").trim();
    if (!url) {
      toast.error("Could not start Shopify OAuth.");
      return;
    }
    window.location.assign(url);
  } catch (e) {
    toast.errorFrom(e, "Could not start Shopify OAuth.");
    busy.value = false;
  }
}

async function connectAndImport() {
  busy.value = true;
  try {
    const body = {
      shop_domain: form.shop_domain,
      import: true,
    };
    if (form.admin_api_access_token.trim()) {
      body.admin_api_access_token = form.admin_api_access_token.trim();
    }
    if (form.webhook_secret.trim()) {
      body.webhook_secret = form.webhook_secret.trim();
    }
    const { data } = await api.put(`/client-accounts/${props.accountId}/shopify-connection`, body);
    connection.value = data?.connection || null;
    form.admin_api_access_token = "";
    form.webhook_secret = "";
    toast.success(data?.message || "Shopify Connected.");
    startPollingIfNeeded();
  } catch (e) {
    toast.errorFrom(e, "Could not connect Shopify.");
    await load();
  } finally {
    busy.value = false;
  }
}

async function syncNow() {
  busy.value = true;
  try {
    const { data } = await api.post(`/client-accounts/${props.accountId}/shopify-connection/sync`);
    connection.value = data?.connection || null;
    toast.success(data?.message || "Shopify Import Completed.");
    startPollingIfNeeded();
  } catch (e) {
    toast.errorFrom(e, "Could not start full re-import.");
  } finally {
    busy.value = false;
  }
}

function openResyncModal() {
  resync.mode = "unfulfilled";
  resync.after_date = "";
  resync.order_number = "";
  resyncOpen.value = true;
}

function closeResyncModal() {
  if (resyncBusy.value) return;
  resyncOpen.value = false;
}

async function submitResync() {
  if (!canSubmitResync.value) return;
  resyncBusy.value = true;
  try {
    const body = { mode: resync.mode };
    if (resync.mode === "after_date") {
      body.after_date = resync.after_date;
    }
    if (resync.mode === "order_number") {
      body.order_number = String(resync.order_number || "").trim();
    }
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/shopify-connection/sync-orders`,
      body,
    );
    if (data?.connection) {
      connection.value = data.connection;
    }
    if (data?.queued) {
      toast.success(data?.message || "Order Re-Sync Queued.");
    } else {
      toast.success(data?.message || `Synced ${data?.synced ?? 1} Order.`);
    }
    resyncOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not re-sync orders.");
  } finally {
    resyncBusy.value = false;
  }
}

async function disconnect() {
  busy.value = true;
  try {
    stopPolling();
    const { data } = await api.delete(`/client-accounts/${props.accountId}/shopify-connection`);
    connection.value = data?.connection || null;
    form.admin_api_access_token = "";
    toast.success(data?.message || "Shopify Disconnected.");
  } catch (e) {
    toast.errorFrom(e, "Could not disconnect Shopify.");
  } finally {
    busy.value = false;
  }
}

watch(
  () => props.accountId,
  () => {
    stopPolling();
    void load();
  },
);

onMounted(() => {
  void load().then(() => {
    consumeOauthQueryToast();
  });
});

onUnmounted(() => {
  stopPolling();
});
</script>

<style scoped>
.modal-backdrop-enter-active,
.modal-backdrop-leave-active {
  transition: opacity 0.2s ease;
}
.modal-backdrop-enter-active .crm-vx-modal-backdrop,
.modal-backdrop-leave-active .crm-vx-modal-backdrop {
  transition: inherit;
}
.modal-backdrop-enter-from,
.modal-backdrop-leave-to {
  opacity: 0;
}

.modal-panel-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.modal-panel-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}
.modal-panel-enter-from,
.modal-panel-leave-to {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
