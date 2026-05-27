import { onUnmounted, ref } from "vue";
import api from "../services/api";
import { usePortalLastRefreshed } from "./usePortalLastRefreshed.js";

const CACHE_KEY_PREFIX = "portal:dashboard:queue-counts:v9:";
const QUEUE_TIMEOUT_MS = 45000;
const SHIPPED_QUEUE_TIMEOUT_MS = 90000;

/** API queue param → dashboard count field */
const PORTAL_QUEUES = [
  { queue: "awaiting", field: "ready_to_ship" },
  { queue: "on_hold", field: "on_hold" },
  { queue: "backorder", field: "backorder" },
  { queue: "shipped", field: "shipped" },
];

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

function storageKey(clientAccountId) {
  return `${CACHE_KEY_PREFIX}${clientAccountId}`;
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

function mergeQueueIntoCounts(counts, field, data) {
  const next = { ...counts };
  next[field] = Number(data?.count ?? data?.[field] ?? 0);
  next.truncated = Boolean(next.truncated || data?.truncated);
  next.stale = Boolean(next.stale || data?.stale);
  if (typeof data?.message === "string" && data.message) {
    next.message = data.message;
  }
  next.refresh_pending = false;
  next.shiphero_ready = data?.shiphero_ready !== false;
  if (typeof data?.cached_at === "string" && data.cached_at) {
    next.cached_at = data.cached_at;
  }
  return next;
}

/** Warm cache snapshot (instant, no ShipHero). */
export function prefetchPortalDashboardCounts(clientAccountId, shipheroReady = true) {
  const id = Number(clientAccountId || 0);
  if (!id || shipheroReady === false) {
    return;
  }
  api
    .get("/orders/queue-counts", { params: { client_account_id: id }, timeout: 10000 })
    .then(({ data }) => writeCache(id, parsePortalQueueCounts(data)))
    .catch(() => {});
}

/**
 * Portal dashboard queue counts — one ShipHero queue per HTTP request (avoids 502).
 * @param {() => number} getClientAccountId
 * @param {{ onError?: (err: unknown) => void, getShipheroReady?: () => boolean }} [options]
 */
export function usePortalDashboardCounts(getClientAccountId, options = {}) {
  const loading = ref(true);
  const refreshing = ref(false);
  const counts = ref(parsePortalQueueCounts(null));
  const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();
  let fetchGeneration = 0;

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
    if (markFresh) {
      markRefreshed(next?.cached_at ?? null);
    }
  }

  async function fetchOneQueue(clientAccountId, { queue, field }, bustCache) {
    const params = { client_account_id: clientAccountId, queue };
    if (bustCache) {
      params.refresh = 1;
    }
    const { data } = await api.get("/orders/queue-counts", {
      params,
      timeout: queue === "shipped" ? SHIPPED_QUEUE_TIMEOUT_MS : QUEUE_TIMEOUT_MS,
    });
    return mergeQueueIntoCounts(counts.value, field, data);
  }

  async function fetchAllQueues({ bustCache = false } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      return;
    }

    const generation = ++fetchGeneration;
    let hadError = false;

    for (const entry of PORTAL_QUEUES) {
      if (generation !== fetchGeneration) {
        return;
      }
      try {
        const next = await fetchOneQueue(clientAccountId, entry, bustCache);
        if (generation !== fetchGeneration) {
          return;
        }
        applyCounts(next);
      } catch (e) {
        hadError = true;
        if (shipheroReadyFlag()) {
          options.onError?.(e);
        }
        break;
      }
    }

    if (generation === fetchGeneration) {
      refreshing.value = false;
      loading.value = false;
      if (!hadError) {
        applyCounts(counts.value, { markFresh: true });
      }
    }
  }

  async function fetchCounts({ background = false, bustCache = false, shipheroReady = true } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      refreshing.value = false;
      return;
    }
    if (shipheroReady === false) {
      fetchGeneration += 1;
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
    } else {
      loading.value = false;
    }

    refreshing.value = true;
    await fetchAllQueues({ bustCache });
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
      await fetchCounts({ background: true, shipheroReady: shipheroReadyFlag() });
      return;
    }

    try {
      const { data } = await api.get("/orders/queue-counts", {
        params: { client_account_id: clientAccountId },
        timeout: 10000,
      });
      applyCounts(parsePortalQueueCounts(data));
      loading.value = false;
    } catch {
      // continue with zeros
      loading.value = false;
    }

    await fetchCounts({ background: true, shipheroReady: shipheroReadyFlag() });
  }

  async function refreshCounts() {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      return;
    }
    fetchGeneration += 1;
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

  onUnmounted(() => {
    fetchGeneration += 1;
  });

  return { counts, loading, refreshing, loadCounts, refreshCounts, lastRefreshedLabel };
}
