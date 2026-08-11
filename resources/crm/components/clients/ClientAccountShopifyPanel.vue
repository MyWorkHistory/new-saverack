<template>
  <div class="shopify-connection-card border rounded-3 p-3 mt-4">
    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
      <div>
        <h3 class="h6 mb-1">Shopify</h3>
        <p class="small text-secondary mb-0">
          Connect this account’s Shopify custom app. Inventory quantities stay in Shopify tables only
          (not ShipHero stock).
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
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Shop Domain</label>
          <input
            v-model="form.shop_domain"
            type="text"
            class="form-control form-control-sm"
            placeholder="your-store.myshopify.com"
            :disabled="busy || isImporting || !canEdit"
          />
        </div>
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
        <div class="col-md-6 small text-secondary pt-md-4">
          <div v-if="connection?.shop_name">Shop: {{ connection.shop_name }}</div>
          <div v-if="connection?.last_sync_at">Last Sync: {{ connection.last_sync_at }}</div>
          <div
            v-if="isImporting"
            class="text-primary"
          >
            Import running in the background… this page will update when it finishes.
          </div>
          <div
            v-if="connection?.last_error"
            class="text-danger"
          >
            {{ connection.last_error }}
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <button
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
          @click="syncNow"
        >
          Sync Now
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
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  accountId: { type: [Number, String], required: true },
  canEdit: { type: Boolean, default: false },
});

const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const connection = ref(null);
const form = reactive({
  shop_domain: "",
  admin_api_access_token: "",
  webhook_secret: "",
});

let pollTimer = null;

const isImporting = computed(() => connection.value?.status === "importing");

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
    toast.success(data?.message || "Shopify Import Queued.");
    startPollingIfNeeded();
  } catch (e) {
    toast.errorFrom(e, "Could not sync Shopify.");
  } finally {
    busy.value = false;
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
  void load();
});

onUnmounted(() => {
  stopPolling();
});
</script>
