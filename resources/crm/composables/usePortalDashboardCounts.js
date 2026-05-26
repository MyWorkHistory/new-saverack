import { ref } from "vue";
import api from "../services/api";
import { usePortalLastRefreshed } from "./usePortalLastRefreshed.js";

const CACHE_KEY_PREFIX = "portal:dashboard:queue-counts:v5:";

function storageKey(clientAccountId) {
  return `${CACHE_KEY_PREFIX}${clientAccountId}`;
}

export function parsePortalQueueCounts(data) {
  return {
    ready_to_ship: Number(data?.ready_to_ship ?? 0),
    on_hold: Number(data?.on_hold ?? 0),
    backorder: Number(data?.backorder ?? 0),
    shipped: Number(data?.shipped ?? 0),
    truncated: Boolean(data?.truncated),
    stale: Boolean(data?.stale),
    refresh_pending: Boolean(data?.refresh_pending),
    shiphero_ready: data?.shiphero_ready !== false,
    message: typeof data?.message === "string" ? data.message : "",
  };
}

function readCache(clientAccountId) {
  try {
    const raw = sessionStorage.getItem(storageKey(clientAccountId));
    if (!raw) {
      return null;
    }
    return parsePortalQueueCounts(JSON.parse(raw));
  } catch {
    return null;
  }
}

function writeCache(clientAccountId, counts) {
  try {
    sessionStorage.setItem(storageKey(clientAccountId), JSON.stringify(counts));
  } catch {
    // no-op
  }
}

/** Fire-and-forget warm-up after portal login (also primes server cache). */
export function prefetchPortalDashboardCounts(clientAccountId, shipheroReady = true) {
  const id = Number(clientAccountId || 0);
  if (!id || shipheroReady === false) {
    return;
  }
  api
    .get("/orders/queue-counts", { params: { client_account_id: id } })
    .then(({ data }) => writeCache(id, parsePortalQueueCounts(data)))
    .catch(() => {});
}

/**
 * Portal dashboard queue counts with sessionStorage stale-while-revalidate.
 * @param {() => number} getClientAccountId
 * @param {{ onError?: (err: unknown) => void, getShipheroReady?: () => boolean }} [options]
 */
export function usePortalDashboardCounts(getClientAccountId, options = {}) {
  const loading = ref(true);
  const refreshing = ref(false);
  const counts = ref(parsePortalQueueCounts(null));
  const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();

  function shipheroReadyFlag() {
    if (typeof options.getShipheroReady === "function") {
      return options.getShipheroReady() !== false;
    }

    return true;
  }

  async function fetchCounts({ background = false, bustCache = false, shipheroReady = true } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      refreshing.value = false;
      return;
    }
    if (shipheroReady === false) {
      counts.value = parsePortalQueueCounts({
        shiphero_ready: false,
        message: "ShipHero is not configured for this account yet.",
      });
      loading.value = false;
      refreshing.value = false;
      return;
    }

    if (background) {
      refreshing.value = true;
    } else if (!readCache(clientAccountId)) {
      loading.value = true;
    }

    let fetchedOk = false;
    try {
      const params = { client_account_id: clientAccountId };
      if (bustCache) {
        params.refresh = 1;
      }
      const { data } = await api.get("/orders/queue-counts", {
        params,
        timeout: 90000,
      });
      const next = parsePortalQueueCounts(data);
      counts.value = next;
      writeCache(clientAccountId, next);
      fetchedOk = true;
    } catch (e) {
      if (shipheroReadyFlag()) {
        options.onError?.(e);
      }
    } finally {
      loading.value = false;
      refreshing.value = false;
      if (fetchedOk && !counts.value.refresh_pending) {
        markRefreshed();
      }
    }
  }

  async function loadCounts() {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      return;
    }

    const cached = readCache(clientAccountId);
    if (cached) {
      counts.value = cached;
      loading.value = false;
      await fetchCounts({ background: true, shipheroReady: shipheroReadyFlag() });
      return;
    }

    await fetchCounts({ background: false, shipheroReady: shipheroReadyFlag() });
  }

  async function refreshCounts() {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      return;
    }
    try {
      sessionStorage.removeItem(storageKey(clientAccountId));
    } catch {
      // no-op
    }
    refreshing.value = true;
    await fetchCounts({
      background: false,
      bustCache: true,
      shipheroReady: shipheroReadyFlag(),
    });
  }

  return { counts, loading, refreshing, loadCounts, refreshCounts, lastRefreshedLabel };
}
