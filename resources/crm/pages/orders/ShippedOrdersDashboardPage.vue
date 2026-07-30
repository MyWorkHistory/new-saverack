<script setup>
import { computed, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import OrdersAccountSectionPanel from "../../components/orders/OrdersAccountSectionPanel.vue";
import ShippedSummaryCards from "../../components/orders/ShippedSummaryCards.vue";
import { SHIPPED_PANELS } from "../../constants/shippedDashboardSections.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";
import api from "../../services/api";

const toast = useToast();

const loading = ref(true);
const refreshing = ref(false);
const totals = ref({
  today: 0,
  yesterday: 0,
  this_week: 0,
  last_week: 0,
  this_month: 0,
});
const todayPanel = ref(emptyPanel());
const yesterdayPanel = ref(emptyPanel());
const dates = ref({});

function emptyPanel() {
  return {
    total_count: 0,
    accounts: [],
    refreshed_at: null,
    status: "idle",
    from_snapshot: false,
    truncated: false,
  };
}

function applyPayload(data) {
  totals.value = {
    today: Number(data?.totals?.today || 0),
    yesterday: Number(data?.totals?.yesterday || 0),
    this_week: Number(data?.totals?.this_week || 0),
    last_week: Number(data?.totals?.last_week || 0),
    this_month: Number(data?.totals?.this_month || 0),
  };
  todayPanel.value = {
    ...emptyPanel(),
    ...(data?.today || {}),
    accounts: Array.isArray(data?.today?.accounts) ? data.today.accounts : [],
  };
  yesterdayPanel.value = {
    ...emptyPanel(),
    ...(data?.yesterday || {}),
    accounts: Array.isArray(data?.yesterday?.accounts) ? data.yesterday.accounts : [],
  };
  dates.value = data?.dates || {};
}

function panelFor(key) {
  return key === "yesterday" ? yesterdayPanel.value : todayPanel.value;
}

function lastUpdatedLabel(key) {
  const at = panelFor(key).refreshed_at;
  if (!at) {
    return panelFor(key).from_snapshot ? "From daily snapshot" : "Not refreshed yet";
  }
  return formatDateTimeUs(at);
}

const lastSyncedLabel = computed(() => {
  const candidates = [todayPanel.value.refreshed_at, yesterdayPanel.value.refreshed_at].filter(Boolean);
  let latestMs = null;
  for (const at of candidates) {
    const ms = new Date(at).getTime();
    if (!Number.isFinite(ms)) continue;
    if (latestMs === null || ms > latestMs) latestMs = ms;
  }
  return latestMs !== null ? formatDateTimeUs(new Date(latestMs).toISOString()) : "";
});

function isPanelRefreshing(key) {
  return refreshing.value || panelFor(key).status === "running";
}

function accountRoute(panel, accountId) {
  return {
    name: panel.routeName,
    query: {
      client_account_id: String(accountId),
      date_preset: panel.datePreset,
    },
  };
}

function scrollToPanel(key) {
  if (key !== "today" && key !== "yesterday") return;
  const el = document.getElementById(`shipped-${key}`);
  el?.scrollIntoView({ behavior: "smooth", block: "start" });
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/orders/shipped-dashboard");
    applyPayload(data);
  } catch (e) {
    toast.errorFrom(e, "Could not load shipped dashboard.");
    throw e;
  } finally {
    loading.value = false;
  }
}

async function onRefresh() {
  refreshing.value = true;
  try {
    const { data } = await api.post("/orders/shipped-dashboard/refresh", {
      from_index: true,
    });
    applyPayload(data);
    toast.success(
      data?.refresh_index_only
        ? "Counts updated from database."
        : "Shipped today refreshed.",
    );
  } catch (e) {
    toast.errorFrom(e, "Could not refresh shipped dashboard.");
  } finally {
    refreshing.value = false;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Shipped",
    description: "Shipped totals by day and account.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-on-hold-overview orders-fulfillment-overview orders-shipped-dashboard">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Shipped</h1>
        <p class="staff-page__intro mb-0">Shipments by account for today and yesterday.</p>
      </div>
      <CrmSyncToolbar :last-synced-label="lastSyncedLabel">
        <CrmRefreshToolbarButton
          :disabled="loading || refreshing"
          :loading="refreshing"
          label="Refresh"
          title="Refresh shipped today from the local index"
          @click="onRefresh"
        />
      </CrmSyncToolbar>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading shipped…" :center="true" />
    </div>

    <template v-else>
      <ShippedSummaryCards :totals="totals" @select="scrollToPanel" />

      <div class="row g-3">
        <div
          v-for="panel in SHIPPED_PANELS"
          :key="panel.key"
          class="col-12 col-lg-6"
        >
          <OrdersAccountSectionPanel
            :section-key="panel.key"
            :label="panel.label"
            :icon="panel.icon"
            :icon-style="panel.iconStyle"
            :accounts="panelFor(panel.key).accounts"
            :last-updated="lastUpdatedLabel(panel.key)"
            :meta-suffix="panel.metaSuffix || ''"
            :refreshing="isPanelRefreshing(panel.key)"
            :account-route="(id) => accountRoute(panel, id)"
            :pill-variant="panel.pillVariant || 'success'"
            :empty-message="panel.emptyMessage || ''"
            :show-view-all-footer="false"
            anchor-prefix="shipped"
            @refresh="onRefresh"
          />
        </div>
      </div>
    </template>
  </div>
</template>
