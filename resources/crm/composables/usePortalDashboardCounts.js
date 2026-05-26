import { onUnmounted, ref } from "vue";
import api from "../services/api";
import { usePortalLastRefreshed } from "./usePortalLastRefreshed.js";

const CACHE_KEY_PREFIX = "portal:dashboard:queue-counts:v4:";
const POLL_MS = 2500;
const POLL_MAX_ATTEMPTS = 24;

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
  let pollTimer = null;
  let pollAttempts = 0;

  function stopPolling() {
    if (pollTimer !== null) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    pollAttempts = 0;
  }

  function shipheroReadyFlag() {
    if (typeof options.getShipheroReady === "function") {
      return options.getShipheroReady() !== false;
    }

    return true;
  }

  function applyCounts(next, { markFresh = false } = {}) {
    counts.value = next;
    const id = Number(getClientAccountId() || 0);
    if (id) {
      writeCache(id, next);
    }
    if (markFresh && !next.refresh_pending) {
      markRefreshed();
    }
  }

  function startPollingIfNeeded() {
    if (!counts.value.refresh_pending) {
      stopPolling();
      return;
    }
    if (pollTimer !== null) {
      return;
    }

    pollTimer = setInterval(async () => {
      pollAttempts += 1;
      const clientAccountId = Number(getClientAccountId() || 0);
      if (!clientAccountId || pollAttempts > POLL_MAX_ATTEMPTS) {
        stopPolling();
        refreshing.value = false;
        return;
      }
      try {
        const { data } = await api.get("/orders/queue-counts", {
          params: { client_account_id: clientAccountId },
        });
        const next = parsePortalQueueCounts(data);
        applyCounts(next, { markFresh: true });
        if (!next.refresh_pending) {
          stopPolling();
          refreshing.value = false;
          loading.value = false;
        }
      } catch {
        if (pollAttempts >= POLL_MAX_ATTEMPTS) {
          stopPolling();
          refreshing.value = false;
        }
      }
    }, POLL_MS);
  }

  async function fetchCounts({ background = false, bustCache = false, shipheroReady = true } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      refreshing.value = false;
      return;
    }
    if (shipheroReady === false) {
      stopPolling();
      applyCounts(
        parsePortalQueueCounts({
          shiphero_ready: false,
          message: "ShipHero is not configured for this account yet.",
        }),
      );
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
      const { data } = await api.get("/orders/queue-counts", { params, timeout: 20000 });
      const next = parsePortalQueueCounts(data);
      applyCounts(next, { markFresh: !next.refresh_pending });
      if (next.refresh_pending) {
        refreshing.value = true;
        startPollingIfNeeded();
      } else {
        refreshing.value = false;
      }
    } catch (e) {
      if (shipheroReadyFlag()) {
        options.onError?.(e);
      }
      stopPolling();
      refreshing.value = false;
    } finally {
      loading.value = false;
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
      applyCounts(cached);
      loading.value = false;
      if (cached.refresh_pending) {
        refreshing.value = true;
        startPollingIfNeeded();
      }
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
    stopPolling();
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

  onUnmounted(stopPolling);

  return { counts, loading, refreshing, loadCounts, refreshCounts, lastRefreshedLabel };
}
