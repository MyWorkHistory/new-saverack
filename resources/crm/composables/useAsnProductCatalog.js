import { nextTick, ref, toValue } from "vue";
import { useRoute } from "vue-router";
import api from "../services/api";
import { useToast } from "./useToast";

/** GraphQL products page size (aligned with portal inventory paging; capped 25–100 on server). */
export const ASN_CATALOG_PAGE_SIZE = 50;

export function catalogKey(product) {
  return String(product?.id || product?.sku || "");
}

/**
 * Base path for ASN-scoped catalog APIs (`/admin/asns/{id}` or `/asns/{id}`).
 * @param {number|string} asnId
 * @param {{ routeName?: string, routePath?: string }} [opts]
 */
export function asnCatalogApiBase(asnId, opts = {}) {
  const id = Number(asnId || 0);
  if (id <= 0) return null;
  const routeName = opts.routeName ?? "";
  const routePath = opts.routePath ?? currentBrowserPath();
  const admin =
    routeName === "admin-asn-detail" ||
    /\/admin\/receiving\/asn\b/i.test(String(routePath));
  return admin ? `/admin/asns/${id}` : `/asns/${id}`;
}

function currentBrowserPath() {
  if (typeof window === "undefined") return "";
  return String(window.location.pathname || "");
}

function asnIdFromBrowserPath() {
  const path = currentBrowserPath();
  const patterns = [
    /\/admin\/receiving\/asn\/(\d+)/i,
    /\/receiving\/asn\/(\d+)/i,
    /\/asn\/(\d+)/i,
  ];
  for (const re of patterns) {
    const m = path.match(re);
    if (m) return Number(m[1]);
  }
  return 0;
}

function wholesaleOrderIdFromBrowserPath() {
  const path = currentBrowserPath();
  const m = path.match(/\/admin\/orders\/wholesale\/(\d+)/i);
  return m ? Number(m[1]) : 0;
}

/** Route names whose `:id` param is an ASN id (not return/order/etc.). */
const ASN_DETAIL_ROUTE_NAMES = new Set(["admin-asn-detail", "user-asn-detail"]);

/**
 * ShipHero product catalog for ASN / order line-item pickers.
 *
 * @param {import('vue').MaybeRefOrGetter<number|string|null|undefined>} clientAccountIdSource
 * CRM client account id (the business customer), not the logged-in user id.
 * @param {import('vue').MaybeRefOrGetter<boolean>} [useSessionClientAccountSource]
 * @param {import('vue').MaybeRefOrGetter<number|string|null|undefined>} [asnIdSource]
 * @param {import('vue').MaybeRefOrGetter<number|string|null|undefined>} [wholesaleOrderIdSource]
 */
export function useAsnProductCatalog(
  clientAccountIdSource,
  useSessionClientAccountSource = () => false,
  asnIdSource = () => 0,
  wholesaleOrderIdSource = () => 0,
) {
  const toast = useToast();
  const route = useRoute();

  const catalog = ref([]);
  const catalogLoading = ref(false);
  const catalogLoadingMore = ref(false);
  const catalogRefreshing = ref(false);
  const catalogSearchAutoLoading = ref(false);
  const catalogPageInfo = ref({ has_next_page: false, end_cursor: null });
  const catalogSearchDraft = ref("");
  const catalogSearchCommitted = ref("");
  const catalogSearchSkipNext = ref(0);
  const catalogQtyByKey = ref({});
  const catalogLoadError = ref("");

  let catalogSearchRunSeq = 0;

  function resolvedAccountId() {
    const id = Number(toValue(clientAccountIdSource) || 0);

    return id > 0 ? id : 0;
  }

  function allowImplicitClientAccount() {
    return Boolean(toValue(useSessionClientAccountSource));
  }

  function resolvedAsnId() {
    let id = Number(toValue(asnIdSource) || 0);
    if (id > 0) return id;
    id = Number(route.params?.id || 0);
    if (id > 0 && ASN_DETAIL_ROUTE_NAMES.has(String(route.name || ""))) return id;
    return asnIdFromBrowserPath();
  }

  function resolvedWholesaleOrderId() {
    let id = Number(toValue(wholesaleOrderIdSource) || 0);
    if (id > 0) return id;
    if (route.name === "wholesale-order-detail") {
      id = Number(route.params?.id || 0);
      if (id > 0) return id;
    }
    return wholesaleOrderIdFromBrowserPath();
  }

  /** ASN / wholesale-scoped catalog URL; null falls back to legacy inventory endpoint. */
  function scopedCatalogBasePath() {
    const wholesaleId = resolvedWholesaleOrderId();
    if (wholesaleId > 0) {
      return `/admin/wholesale-orders/${wholesaleId}`;
    }
    return asnCatalogApiBase(resolvedAsnId(), {
      routeName: route.name,
      routePath: route.path,
    });
  }

  function catalogQty(product) {
    const k = catalogKey(product);
    const n = Number(catalogQtyByKey.value[k]);

    return Number.isFinite(n) && n >= 0 ? n : 1;
  }

  function setCatalogQty(product, value) {
    const k = catalogKey(product);
    catalogQtyByKey.value = {
      ...catalogQtyByKey.value,
      [k]: Math.max(0, Number(value) || 0),
    };
  }

  function resetCatalogSearchState() {
    catalogSearchRunSeq += 1;
    catalogSearchAutoLoading.value = false;
    catalogSearchDraft.value = "";
    catalogSearchCommitted.value = "";
    catalogSearchSkipNext.value = 0;
    catalogPageInfo.value = { has_next_page: false, end_cursor: null };
    catalog.value = [];
    catalogLoadError.value = "";
  }

  function commitCatalogSearch() {
    catalogSearchCommitted.value = catalogSearchDraft.value.trim();
    loadCatalogRows(true);
  }

  function clearCatalogSearch() {
    if (!catalogSearchDraft.value && !catalogSearchCommitted.value) return;
    catalogSearchDraft.value = "";
    catalogSearchCommitted.value = "";
    loadCatalogRows(true);
  }

  function loadMoreCatalog() {
    if (
      !catalogPageInfo.value.has_next_page ||
      catalogLoadingMore.value ||
      catalogLoading.value ||
      catalogSearchAutoLoading.value ||
      (!resolvedWholesaleOrderId() && !resolvedAsnId() && !resolvedAccountId() && !allowImplicitClientAccount())
    ) {
      return;
    }
    loadCatalogRows(false);
  }

  async function continueCatalogSearchInBackground(runId) {
    if (catalogSearchAutoLoading.value) return;
    catalogSearchAutoLoading.value = true;
    try {
      let guard = 0;
      while (
        runId === catalogSearchRunSeq &&
        catalogSearchCommitted.value.trim() &&
        catalogPageInfo.value.has_next_page &&
        guard < 200
      ) {
        guard += 1;
        await loadCatalogRows(false);
        await nextTick();
      }
    } finally {
      if (runId === catalogSearchRunSeq) {
        catalogSearchAutoLoading.value = false;
      }
    }
  }

  async function loadCatalogRows(reset, forceRefresh = false) {
    const accountId = resolvedAccountId();
    const asnId = resolvedAsnId();
    const scopedBase = scopedCatalogBasePath();
    if (
      !scopedBase &&
      !accountId &&
      !allowImplicitClientAccount() &&
      resolvedWholesaleOrderId() <= 0
    ) {
      catalogLoadError.value =
        "Could not determine which customer account owns this ASN. Reload the page.";
      return;
    }

    const runId = reset ? ++catalogSearchRunSeq : catalogSearchRunSeq;
    if (reset) {
      catalogLoading.value = !forceRefresh;
      catalogRefreshing.value = forceRefresh;
      catalogSearchAutoLoading.value = false;
      catalog.value = [];
      catalogPageInfo.value = { has_next_page: false, end_cursor: null };
      catalogSearchSkipNext.value = 0;
      catalogLoadError.value = "";
    } else {
      catalogLoadingMore.value = true;
    }

    try {
      const params = {
        first: ASN_CATALOG_PAGE_SIZE,
      };
      const catalogUrl = scopedBase
        ? `${scopedBase}/product-catalog`
        : "/inventory/asn-product-catalog";
      if (asnId > 0) {
        params.asn_id = asnId;
      }
      if (accountId > 0) {
        params.client_account_id = accountId;
      }
      const q = catalogSearchCommitted.value;
      if (q) {
        params.query = q;
        params.search_skip = catalogSearchSkipNext.value;
      }
      if (forceRefresh) {
        params.refresh = 1;
      }
      if (!reset && catalogPageInfo.value?.end_cursor) {
        params.after = catalogPageInfo.value.end_cursor;
      }
      const { data } = await api.get(catalogUrl, { params });
      const chunk = Array.isArray(data?.products) ? data.products : [];
      const pi = data?.page_info || {};
      catalogPageInfo.value = {
        has_next_page: Boolean(pi.has_next_page),
        end_cursor: pi.end_cursor ?? null,
      };
      if (q && typeof pi.next_search_skip === "number") {
        catalogSearchSkipNext.value = Number(pi.next_search_skip);
      }

      const dest = [];
      const seen = new Set();
      if (!reset) {
        for (const p of catalog.value) {
          const k = catalogKey(p);
          seen.add(k);
          dest.push(p);
        }
      }
      for (const p of chunk) {
        const k = catalogKey(p);
        if (seen.has(k)) continue;
        seen.add(k);
        dest.push(p);
      }
      catalog.value = dest;
    } catch (e) {
      const msg =
        e?.response?.data?.message ||
        e?.message ||
        "Could not load product catalog.";
      catalogLoadError.value = msg;
      toast.errorFrom(e, "Could not load product catalog.");
    } finally {
      catalogLoading.value = false;
      catalogLoadingMore.value = false;
      catalogRefreshing.value = false;
    }

    if (reset && catalogSearchCommitted.value.trim()) {
      continueCatalogSearchInBackground(runId);
    }
  }

  async function refreshCatalogProducts() {
    await loadCatalogRows(true, true);
  }

  return {
    catalog,
    catalogLoading,
    catalogLoadingMore,
    catalogRefreshing,
    catalogSearchAutoLoading,
    catalogPageInfo,
    catalogSearchDraft,
    catalogSearchCommitted,
    catalogLoadError,
    catalogQty,
    setCatalogQty,
    resetCatalogSearchState,
    commitCatalogSearch,
    clearCatalogSearch,
    loadMoreCatalog,
    loadCatalogRows,
    refreshCatalogProducts,
    resolvedAsnId,
    scopedCatalogBasePath,
  };
}
