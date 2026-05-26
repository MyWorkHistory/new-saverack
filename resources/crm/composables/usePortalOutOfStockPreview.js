import { computed, ref } from "vue";
import api from "../services/api";

const PREVIEW_FETCH_SIZE = 100;
const PREVIEW_TOP_N = 5;

function sortByBackorderDesc(rows) {
  return [...rows]
    .filter((r) => Number(r?.backorder ?? 0) > 0)
    .sort((a, b) => Number(b?.backorder ?? 0) - Number(a?.backorder ?? 0));
}

/**
 * Portal home: top out-of-stock / oversold rows (same filters as UserInventoryOutOfStockPage).
 * @param {() => number} getClientAccountId
 * @param {{ getShipheroReady?: () => boolean, onError?: (err: unknown) => void }} [options]
 */
export function usePortalOutOfStockPreview(getClientAccountId, options = {}) {
  const loading = ref(false);
  const rows = ref([]);
  const hasLoaded = ref(false);

  const topRows = computed(() => sortByBackorderDesc(rows.value).slice(0, PREVIEW_TOP_N));

  async function loadPreview({ bustCache = false } = {}) {
    if (loading.value && !bustCache) {
      return;
    }
    if (hasLoaded.value && !bustCache) {
      return;
    }
    const clientAccountId = Number(getClientAccountId() || 0);
    if (!clientAccountId) {
      rows.value = [];
      loading.value = false;
      hasLoaded.value = false;
      return;
    }

    if (typeof options.getShipheroReady === "function" && options.getShipheroReady() === false) {
      rows.value = [];
      loading.value = false;
      hasLoaded.value = false;
      return;
    }

    loading.value = true;
    try {
      const params = {
        client_account_id: clientAccountId,
        backorder_only: 1,
        kits: "all",
        active_status: "active",
        first: PREVIEW_FETCH_SIZE,
      };
      if (bustCache) {
        params.refresh = 1;
      }
      const { data } = await api.get("/inventory/list", { params, timeout: 60000 });
      rows.value = Array.isArray(data?.rows) ? data.rows : [];
      hasLoaded.value = true;
    } catch (e) {
      rows.value = [];
      options.onError?.(e);
    } finally {
      loading.value = false;
    }
  }

  return { loading, topRows, loadPreview };
}
