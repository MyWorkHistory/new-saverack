<script setup>
import { computed, inject, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../components/common/CrmRefreshToolbarButton.vue";
import HomeCalendarPanel from "../components/home/HomeCalendarPanel.vue";
import HomePausedAccountsPanel from "../components/home/HomePausedAccountsPanel.vue";
import HomePendingAccountsPanel from "../components/home/HomePendingAccountsPanel.vue";
import HomePendingAsnPanel from "../components/home/HomePendingAsnPanel.vue";
import HomeRestocksPanel from "../components/home/HomeRestocksPanel.vue";
import HomeSummaryStatCards from "../components/home/HomeSummaryStatCards.vue";
import { setCrmPageMeta } from "../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../composables/useAdminHomeDashboard.js";
import { useToast } from "../composables/useToast.js";
import { crmIsAdmin } from "../utils/crmUser.js";

const toast = useToast();
const crmUser = inject("crmUser", ref(null));

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canViewClients = computed(() => userHasPerm("clients.view"));
const canViewInventory = computed(() => userHasPerm("inventory.view"));

const {
  loading,
  refreshing,
  totals,
  sections,
  pausedAccounts,
  pendingNewAccounts,
  pendingAsnPreview,
  restockPreview,
  restockActiveCount,
  anySectionPending,
  load,
  refreshSection,
} = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load Home dashboard."),
});

function refreshToastMessage(data, fallbackQueued) {
  if (data?.refresh_index_only) {
    return "Counts updated from database. Full sync queued if index was empty.";
  }
  if (data?.refresh_synced) {
    return "Section refreshed.";
  }
  if (data?.refresh_enqueued || fallbackQueued) {
    return "Refresh queued — counts will update shortly.";
  }
  return "Refresh started.";
}

async function onRefreshAll() {
  try {
    const data = await refreshSection("all", { sync: false });
    toast.success(refreshToastMessage(data, true));
  } catch {
    /* toast handled */
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Home",
    description: "Operations overview and ASN.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-home-dashboard">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h1 class="h4 mb-0 fw-semibold text-body">Home</h1>
      <CrmRefreshToolbarButton
        :disabled="loading || refreshing"
        :loading="refreshing"
        label="Refresh All"
        title="Refresh all sections"
        @click="onRefreshAll"
      />
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading Home…" :center="true" />
    </div>

    <template v-else>
      <div
        v-if="anySectionPending"
        class="user-inv-sync-banner small text-secondary px-3 py-2 border rounded mb-3 bg-body-tertiary"
        role="status"
        aria-live="polite"
      >
        Syncing dashboard…
      </div>

      <HomeSummaryStatCards
        :totals="totals"
        :sections="sections"
        :restock-active-count="restockActiveCount"
      />

      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-4">
          <HomeCalendarPanel />
        </div>
        <div v-if="canViewClients" class="col-12 col-lg-4">
          <HomePausedAccountsPanel :accounts="pausedAccounts" />
        </div>
        <div v-if="canViewClients" class="col-12 col-lg-4">
          <HomePendingAccountsPanel :accounts="pendingNewAccounts" />
        </div>
      </div>

      <div class="row g-3">
        <div v-if="canViewInventory" class="col-12 col-lg-6">
          <HomeRestocksPanel :items="restockPreview" />
        </div>
        <div v-if="canViewClients" class="col-12 col-lg-6">
          <HomePendingAsnPanel :items="pendingAsnPreview" />
        </div>
      </div>
    </template>
  </div>
</template>
