import { onMounted, onUnmounted, watch } from "vue";
import api from "../services/api";

export const SHIPHERO_REVISION_POLL_MS = 30000;

function resolveValue(source) {
  return typeof source === "function" ? source() : source;
}

/**
 * Poll ShipHero revision counters and invoke refresh callbacks when they increase.
 */
export function useShipHeroRevisionPoll({
  getClientAccountId = () => 0,
  pollQueueCounts = false,
  pollInventory = false,
  onQueueRevision = null,
  onInventoryRevision = null,
  intervalMs = SHIPHERO_REVISION_POLL_MS,
} = {}) {
  let revisionPollTimer = null;
  let knownQueueRevision = 0;
  let knownInventoryRevision = 0;

  function stopRevisionPolling() {
    if (revisionPollTimer !== null) {
      window.clearInterval(revisionPollTimer);
      revisionPollTimer = null;
    }
  }

  function resetKnownRevisions() {
    knownQueueRevision = 0;
    knownInventoryRevision = 0;
  }

  async function pollOnce() {
    const clientAccountId = Number(resolveValue(getClientAccountId) || 0);
    const shouldPollQueue = Boolean(resolveValue(pollQueueCounts));
    const shouldPollInventory = Boolean(resolveValue(pollInventory));

    if (shouldPollQueue && clientAccountId > 0) {
      try {
        const { data } = await api.get("/orders/queue-counts/revision", {
          params: { client_account_id: clientAccountId },
          timeout: 10000,
        });
        const revision = Number(data?.revision ?? 0);
        if (revision > knownQueueRevision) {
          knownQueueRevision = revision;
          if (typeof onQueueRevision === "function") {
            await onQueueRevision();
          }
        } else if (knownQueueRevision === 0) {
          knownQueueRevision = revision;
        }
      } catch {
        /* keep polling on transient errors */
      }
    }

    if (shouldPollInventory && clientAccountId > 0) {
      try {
        const { data } = await api.get("/inventory-beta/revision", {
          params: { client_account_id: clientAccountId },
          timeout: 10000,
        });
        const revision = Number(data?.revision ?? 0);
        if (revision > knownInventoryRevision) {
          knownInventoryRevision = revision;
          if (typeof onInventoryRevision === "function") {
            await onInventoryRevision();
          }
        } else if (knownInventoryRevision === 0) {
          knownInventoryRevision = revision;
        }
      } catch {
        /* keep polling on transient errors */
      }
    }
  }

  function startRevisionPolling() {
    stopRevisionPolling();
    revisionPollTimer = window.setInterval(() => {
      void pollOnce();
    }, intervalMs);
    void pollOnce();
  }

  onMounted(() => {
    startRevisionPolling();
  });

  onUnmounted(() => {
    stopRevisionPolling();
  });

  watch(
    () => [resolveValue(getClientAccountId), resolveValue(pollQueueCounts), resolveValue(pollInventory)],
    () => {
      resetKnownRevisions();
    },
  );

  return {
    startRevisionPolling,
    stopRevisionPolling,
    resetKnownRevisions,
  };
}
