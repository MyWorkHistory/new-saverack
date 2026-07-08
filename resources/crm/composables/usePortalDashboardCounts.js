import { onMounted, onUnmounted, ref } from "vue";
import api from "../services/api";
import { usePortalLastRefreshed } from "./usePortalLastRefreshed.js";

const CACHE_KEY_PREFIX = "portal:dashboard:queue-counts:v13:";
const QUEUE_TIMEOUT_MS = 45000;
const SHIPPED_QUEUE_TIMEOUT_MS = 90000;
const REVISION_POLL_MS = 30000;
const SNAPSHOT_TIMEOUT_MS = 15000;

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
    revision: Number(data?.revision ?? 0),
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
    .get("/orders/queue-counts/snapshot", {
      params: { client_account_id: id },
      timeout: SNAPSHOT_TIMEOUT_MS,
    })
    .then(({ data }) => writeCache(id, parsePortalQueueCounts(data)))
    .catch(() => {});
}

/**
 * Portal dashboard queue counts — fast index snapshot + revision polling.
 * @param {() => number} getClientAccountId
 * @param {{ onError?: (err: unknown) => void, getShipheroReady?: () => boolean }} [options]
 */
export function usePortalDashboardCounts(getClientAccountId, options = {}) {
  const loading = ref(true);
  const refreshing = ref(false);
  /** True once we have shown counts from cache or API (keeps numbers visible during background refresh). */
  const countsReady = ref(false);
  const counts = ref(parsePortalQueueCounts(null));
  const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();
  let fetchGeneration = 0;
  let revisionPollTimer = null;
  let knownRevision = 0;

  function shipheroReadyFlag() {
    if (typeof options.getShipheroReady === "function") {
      return options.getShipheroReady() !== false;
    }

    return true;
  }

  function applyCounts(next, { markFresh = false, markReady = true } = {}) {
    counts.value = next;
    knownRevision = Number(next?.revision ?? knownRevision);
    if (markReady) {
      countsReady.value = true;
      loading.value = false;
    }
    const id = Number(getClientAccountId() || 0);
    if (id) {
      writeCache(id, next);
    }
    if (markFresh) {
      markRefreshed(next?.cached_at ?? null);
    }
  }

  async function fetchSnapshot({ markFresh = true } = {}) {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId || shipheroReadyFlag() === false) {
      return;
    }

    const { data } = await api.get("/orders/queue-counts/snapshot", {
      params: { client_account_id: clientAccountId },
      timeout: SNAPSHOT_TIMEOUT_MS,
    });
    applyCounts(parsePortalQueueCounts(data), { markFresh, markReady: true });
    refreshing.value = false;
    loading.value = false;
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
      refreshing.value = false;
      return;
    }

    if (bustCache) {
      const isBackgroundRefresh = background || countsReady.value;
      if (isBackgroundRefresh) {
        refreshing.value = true;
        loading.value = false;
      }
      await fetchAllQueues({ bustCache: true });
      return;
    }

    const isBackgroundRefresh = background || countsReady.value;
    if (isBackgroundRefresh) {
      refreshing.value = true;
      loading.value = false;
    } else if (!readCache(clientAccountId)) {
      loading.value = true;
    } else {
      loading.value = false;
    }

    try {
      await fetchSnapshot({ markFresh: !isBackgroundRefresh });
    } catch (e) {
      if (shipheroReadyFlag()) {
        options.onError?.(e);
      }
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
      applyCounts(cached);
      await fetchCounts({ background: true, shipheroReady: shipheroReadyFlag() });
      return;
    }

    await fetchCounts({ shipheroReady: shipheroReadyFlag() });
  }

  async function refreshCounts() {
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      loading.value = false;
      refreshing.value = false;
      return;
    }
    fetchGeneration += 1;
    try {
      sessionStorage.removeItem(storageKey(clientAccountId));
    } catch {
      // no-op
    }
    await fetchCounts({
      background: true,
      bustCache: true,
      shipheroReady: shipheroReadyFlag(),
    });
  }

  function stopRevisionPolling() {
    if (revisionPollTimer !== null) {
      window.clearInterval(revisionPollTimer);
      revisionPollTimer = null;
    }
  }

  function startRevisionPolling() {
    stopRevisionPolling();
    revisionPollTimer = window.setInterval(() => {
      const clientAccountId = Number(getClientAccountId() || 0);
      if (!clientAccountId || shipheroReadyFlag() === false) {
        return;
      }
      void api
        .get("/orders/queue-counts/revision", {
          params: { client_account_id: clientAccountId },
          timeout: 10000,
        })
        .then(({ data }) => {
          const revision = Number(data?.revision ?? 0);
          if (revision > knownRevision) {
            void fetchSnapshot({ markFresh: true }).catch(() => {});
          }
        })
        .catch(() => {});
    }, REVISION_POLL_MS);
  }

  onMounted(() => {
    startRevisionPolling();
  });

  onUnmounted(() => {
    fetchGeneration += 1;
    stopRevisionPolling();
  });

  return {
    counts,
    loading,
    refreshing,
    countsReady,
    loadCounts,
    refreshCounts,
    markRefreshed,
    lastRefreshedLabel,
  };
}
