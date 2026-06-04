/**
 * @param {{ shiphero_sync?: { ok?: boolean, message?: string|null } }} data
 * @param {{ warning?: (msg: string) => void, error?: (msg: string) => void }} toast
 */
export function warnIfShipheroSyncFailed(data, toast) {
  const sync = data?.shiphero_sync;
  if (!sync || sync.ok !== false || !sync.message) {
    return;
  }

  const message = `ShipHero sync: ${sync.message}`;
  if (typeof toast?.warning === "function") {
    toast.warning(message);
    return;
  }
  if (typeof toast?.error === "function") {
    toast.error(message);
  }
}
