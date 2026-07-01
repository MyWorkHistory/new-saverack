import { computed, onUnmounted, ref } from "vue";
import api from "../services/api";

const POLL_MS = 3000;

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

  let pollTimer = null;

  const anySectionRunning = computed(() =>
    Object.values(sections.value || {}).some((s) => s?.status === "running"),
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

  async function refreshSection(section = "all") {
    if (refreshing.value) return;
    refreshing.value = true;
    try {
      const { data } = await api.post("/home-dashboard/refresh", { section });
      applyPayload(data);
      syncPolling();
    } catch (e) {
      onError?.(e);
      throw e;
    } finally {
      refreshing.value = false;
    }
  }

  function syncPolling() {
    stopPolling();
    if (!anySectionRunning.value) return;
    pollTimer = window.setInterval(() => {
      void api
        .get("/home-dashboard")
        .then(({ data }) => {
          applyPayload(data);
          if (!anySectionRunning.value) {
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
    anySectionRunning,
    load,
    refreshSection,
    stopPolling,
  };
}
