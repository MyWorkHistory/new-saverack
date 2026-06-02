/**
 * @param {{ shiphero_sync?: { ok?: boolean, message?: string|null } }} data
 * @param {{ warning: (msg: string) => void }} toast
 */
export function warnIfShipheroSyncFailed(data, toast) {
  const sync = data?.shiphero_sync;
  if (sync && sync.ok === false && sync.message) {
    toast.warning(`ShipHero sync: ${sync.message}`);
  }
}
