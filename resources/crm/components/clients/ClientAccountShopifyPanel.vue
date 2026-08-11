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
      <CrmLoadingSpinner label="Loading Shopify…" />
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
            :disabled="busy || !canEdit"
          />
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Admin API Access Token</label>
          <input
            v-model="form.admin_api_access_token"
            type="password"
            class="form-control form-control-sm"
            :placeholder="connection?.has_token ? '•••••••• (leave blank to keep)' : 'shpat_…'"
            :disabled="busy || !canEdit"
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
            :disabled="busy || !canEdit"
            autocomplete="new-password"
          />
        </div>
        <div class="col-md-6 small text-secondary pt-md-4">
          <div v-if="connection?.shop_name">Shop: {{ connection.shop_name }}</div>
          <div v-if="connection?.last_sync_at">Last Sync: {{ connection.last_sync_at }}</div>
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
          class="btn btn-sm btn-primary"
          :disabled="busy || !canEdit || !form.shop_domain"
          @click="connectAndImport"
        >
          Connect And Import
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-primary"
          :disabled="busy || !canEdit || !connection?.has_token"
          @click="syncNow"
        >
          Sync Now
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-danger"
          :disabled="busy || !canEdit || !connection?.has_token"
          @click="disconnect"
        >
          Disconnect
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
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

const statusLabel = computed(() => {
  const s = connection.value?.status || "disconnected";
  if (s === "connected") return "Connected";
  if (s === "error") return "Error";
  return "Disconnected";
});

const statusBadgeClass = computed(() => {
  const s = connection.value?.status || "disconnected";
  if (s === "connected") return "text-bg-success";
  if (s === "error") return "text-bg-danger";
  return "text-bg-secondary";
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/client-accounts/${props.accountId}/shopify-connection`);
    connection.value = data?.connection || null;
    form.shop_domain = connection.value?.shop_domain || "";
    form.admin_api_access_token = "";
    form.webhook_secret = "";
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
    toast.success(data?.message || "Shopify Sync Completed.");
  } catch (e) {
    toast.errorFrom(e, "Could not sync Shopify.");
  } finally {
    busy.value = false;
  }
}

async function disconnect() {
  busy.value = true;
  try {
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
    void load();
  },
);

onMounted(() => {
  void load();
});
</script>
