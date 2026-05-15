import { ref } from "vue";
import api from "../services/api";

const CACHE_KEY_PREFIX = "portal:dashboard:queue-counts:v1:";

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
export function prefetchPortalDashboardCounts(clientAccountId) {
  const id = Number(clientAccountId || 0);
  if (!id) {
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
 * @param {{ onError?: (err: unknown) => void }} [options]
 */
export function usePortalDashboardCounts(getClientAccountId, options = {}) {
  const loading = ref(true);
  const refreshing = ref(false);
  const counts = ref(parsePortalQueueCounts(null));

  async function fetchCounts({ background = false, bustCache = false } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      refreshing.value = false;
      return;
    }

    if (background) {
      refreshing.value = true;
    } else if (!readCache(clientAccountId)) {
      loading.value = true;
    }

    try {
      const params = { client_account_id: clientAccountId };
      if (bustCache) {
        params.refresh = 1;
      }
      const { data } = await api.get("/orders/queue-counts", { params });
      const next = parsePortalQueueCounts(data);
      counts.value = next;
      writeCache(clientAccountId, next);
    } catch (e) {
      options.onError?.(e);
    } finally {
      loading.value = false;
      refreshing.value = false;
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
      await fetchCounts({ background: true });
      return;
    }

    await fetchCounts({ background: false });
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
    loading.value = true;
    refreshing.value = false;
    await fetchCounts({ background: false, bustCache: true });
  }

  return { counts, loading, refreshing, loadCounts, refreshCounts };
}
