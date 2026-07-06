import { computed, onUnmounted, ref } from "vue";
import api from "../services/api";

const POLL_MS = 3000;

const SHIPHERO_SECTION_KEYS = [
  "ready_to_ship",
  "shipped",
  "hold_operator",
  "hold_address",
  "hold_fraud",
  "hold_payment",
  "hold_user",
  "hold_backorder",
];

function sectionNeedsPolling(section) {
  if (!section || typeof section !== "object") return false;
  if (section.status === "running") return true;
  return section.refreshed_at == null || section.refreshed_at === "";
}

/**
 * Admin Home dashboard — reads precomputed order/ASN snapshots from the API.
 */
export function useAdminHomeDashboard({ onError } = {}) {
  const loading = ref(false);
  const refreshing = ref(false);
  const totals = ref({
    ready_to_ship: 0,
    on_hold: 0,
    shipped: 0,
    asn_pending: 0,
  });
  const sections = ref({});
  const pausedAccounts = ref([]);
  const pendingNewAccounts = ref([]);
  const pendingAsnPreview = ref([]);
  const putAwayByAccount = ref([]);
  const restockPreview = ref([]);
  const restockActiveCount = ref(0);

  let pollTimer = null;

  const anySectionRunning = computed(() =>
    Object.values(sections.value || {}).some((s) => s?.status === "running"),
  );

  const anySectionPending = computed(() =>
    SHIPHERO_SECTION_KEYS.some((key) => sectionNeedsPolling(sections.value?.[key])),
  );

  function applyPayload(data) {
    const t = data?.totals;
    totals.value = {
      ready_to_ship: Number(t?.ready_to_ship || 0),
      on_hold: Number(t?.on_hold || 0),
      shipped: Number(t?.shipped || 0),
      asn_pending: Number(t?.asn_pending || 0),
    };
    sections.value =
      data?.sections && typeof data.sections === "object" ? { ...data.sections } : {};
    pausedAccounts.value = Array.isArray(data?.paused_accounts) ? [...data.paused_accounts] : [];
    pendingNewAccounts.value = Array.isArray(data?.pending_new_accounts)
      ? [...data.pending_new_accounts]
      : [];
    pendingAsnPreview.value = Array.isArray(data?.pending_asn_preview)
      ? [...data.pending_asn_preview]
      : [];
    putAwayByAccount.value = Array.isArray(data?.put_away_by_account)
      ? [...data.put_away_by_account]
      : [];
    restockPreview.value = Array.isArray(data?.restock_preview) ? [...data.restock_preview] : [];
    restockActiveCount.value = Number(data?.restock_active_count || 0);
  }

  async function load() {
    loading.value = true;
    try {
      const { data } = await api.get("/home-dashboard");
      applyPayload(data);
      syncPolling();
    } catch (e) {
      onError?.(e);
      throw e;
    } finally {
      loading.value = false;
    }
  }

  async function refreshSection(section = "all", { sync = section !== "all" } = {}) {
    if (refreshing.value) return;
    refreshing.value = true;
    try {
      const { data } = await api.post("/home-dashboard/refresh", { section, sync });
      applyPayload(data);
      syncPolling();
      return data;
    } catch (e) {
      onError?.(e);
      throw e;
    } finally {
      refreshing.value = false;
    }
  }

  function shouldPoll() {
    return anySectionRunning.value || anySectionPending.value;
  }

  function syncPolling() {
    stopPolling();
    if (!shouldPoll()) return;
    pollTimer = window.setInterval(() => {
      void api
        .get("/home-dashboard")
        .then(({ data }) => {
          applyPayload(data);
          if (!shouldPoll()) {
            stopPolling();
          }
        })
        .catch(() => {
          /* keep polling on transient errors */
        });
    }, POLL_MS);
  }

  function stopPolling() {
    if (pollTimer !== null) {
      window.clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  onUnmounted(stopPolling);

  return {
    loading,
    refreshing,
    totals,
    sections,
    pausedAccounts,
    pendingNewAccounts,
    pendingAsnPreview,
    putAwayByAccount,
    restockPreview,
    restockActiveCount,
    anySectionRunning,
    anySectionPending,
    load,
    refreshSection,
    stopPolling,
  };
}
