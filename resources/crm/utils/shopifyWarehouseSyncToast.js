/**
 * Toast copy for warehouse → Shopify inventory sync status from the API.
 * @param {{ status?: string, reason?: string|null }|null|undefined} sync
 * @param {string} successWhenQueued
 */
export function toastShopifyWarehouseSync(toast, sync, successWhenQueued) {
  const status = String(sync?.status || "");
  if (status === "queued") {
    toast.success(successWhenQueued);
    return;
  }
  if (status === "skipped") {
    const reason = String(sync?.reason || "");
    if (reason === "no_sync_inventory_locations") {
      toast.warning(
        "Saved in CRM, but Shopify was not updated. Enable Sync Inventory on a store location under Account → Stores.",
      );
      return;
    }
    if (reason === "missing_inventory_item_id") {
      toast.warning(
        "Saved in CRM, but Shopify was not updated. This product is missing a Shopify inventory item id — re-sync products.",
      );
      return;
    }
    if (reason === "no_connection") {
      toast.warning("Saved in CRM, but Shopify was not updated. Shopify connection is missing for this product.");
      return;
    }
    toast.warning("Saved in CRM, but Shopify inventory sync was skipped.");
    return;
  }
  toast.success(successWhenQueued.replace(/ Shopify inventory will update in the background\./, "."));
}
