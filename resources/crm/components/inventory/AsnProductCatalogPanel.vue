<script setup>
import { watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { catalogKey, useAsnProductCatalog } from "../../composables/useAsnProductCatalog.js";

const props = defineProps({
  clientAccountId: { type: [Number, String], default: 0 },
  /** When true, reset search and load the first catalog page. */
  active: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  showAddNewSku: { type: Boolean, default: false },
  qtyLabel: { type: String, default: "Quantity" },
  searchInputId: { type: String, default: "asn-catalog-search" },
  permissionDeniedMessage: { type: String, default: "" },
});

const emit = defineEmits(["add", "add-new-sku"]);

const {
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
} = useAsnProductCatalog(() => props.clientAccountId);

watch(
  () => [props.active, props.clientAccountId, props.permissionDeniedMessage],
  ([active, accountId, denied]) => {
    if (!active) return;
    if (denied) return;
    if (!Number(accountId || 0)) return;
    resetCatalogSearchState();
    loadCatalogRows(true);
  },
);
</script>

<template>
  <div class="asn-product-catalog-panel">
    <div
      v-if="permissionDeniedMessage"
      class="alert alert-warning small mb-0"
      role="alert"
    >
      {{ permissionDeniedMessage }}
    </div>
    <template v-else>
      <div class="staff-table-toolbar border-bottom">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            :id="searchInputId"
            v-model.trim="catalogSearchDraft"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search by SKU or name"
            autocomplete="off"
            aria-label="Search product catalog"
            :disabled="catalogLoading && !catalog.length"
            @keydown.enter.prevent="commitCatalogSearch"
          />
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
            :disabled="catalogLoading"
            @click="commitCatalogSearch"
          >
            Search
          </button>
          <button
            v-if="catalogSearchDraft || catalogSearchCommitted"
            type="button"
            class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
            :disabled="catalogLoading"
            @click="clearCatalogSearch"
          >
            Clear
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
            :disabled="catalogLoading || catalogLoadingMore || catalogRefreshing"
            @click="refreshCatalogProducts"
          >
            {{ catalogRefreshing ? "Refreshing…" : "Refresh Products" }}
          </button>
          <button
            v-if="showAddNewSku"
            type="button"
            class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
            @click="emit('add-new-sku')"
          >
            Add New SKU
          </button>
        </div>
      </div>
      <div class="p-4 bg-body-tertiary">
        <div v-if="catalogLoadError && !catalogLoading" class="alert alert-danger small mb-3" role="alert">
          {{ catalogLoadError }}
        </div>
        <div v-if="catalogLoading" class="d-flex justify-content-center py-4">
          <CrmLoadingSpinner message="Loading products…" />
        </div>
        <template v-else>
          <p class="small fw-semibold mb-2">From catalog</p>
          <div class="asn-catalog-grid border rounded bg-white">
            <div
              v-for="p in catalog"
              :key="catalogKey(p)"
              class="asn-catalog-grid__row d-flex align-items-center gap-2 border-bottom py-2 px-2"
            >
              <img
                v-if="p.image_url"
                :src="p.image_url"
                alt=""
                class="asn-catalog-thumb"
                loading="lazy"
              />
              <div v-else class="asn-catalog-thumb asn-catalog-thumb--empty" aria-hidden="true" />
              <div class="min-w-0 flex-grow-1">
                <div class="fw-semibold small text-truncate">{{ p.sku }}</div>
                <div class="text-secondary small text-truncate">{{ p.name }}</div>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <label class="visually-hidden" :for="'catalog-qty-' + catalogKey(p)">{{ qtyLabel }}</label>
                <input
                  :id="'catalog-qty-' + catalogKey(p)"
                  type="number"
                  min="0"
                  class="form-control form-control-sm asn-catalog-grid__qty"
                  :value="catalogQty(p)"
                  :disabled="busy"
                  @input="setCatalogQty(p, $event.target.value)"
                />
                <button
                  type="button"
                  class="btn btn-sm btn-primary staff-page-primary"
                  :disabled="busy"
                  @click="emit('add', { product: p, quantity: catalogQty(p) })"
                >
                  Add
                </button>
              </div>
            </div>
            <div v-if="catalog.length === 0" class="p-3 small text-secondary">
              <template v-if="catalogSearchCommitted">
                No matches.
                <button
                  v-if="showAddNewSku"
                  type="button"
                  class="btn btn-link btn-sm p-0 align-baseline"
                  @click="emit('add-new-sku')"
                >
                  Add New SKU
                </button>
              </template>
              <template v-else>No products on this page.</template>
            </div>
          </div>
          <div v-if="catalogPageInfo.has_next_page" class="d-flex justify-content-center mt-3">
            <div v-if="catalogSearchAutoLoading" class="small text-secondary py-1" aria-live="polite">
              Searching More Matches…
            </div>
            <button
              v-else
              type="button"
              class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary px-4"
              :disabled="catalogLoadingMore"
              @click="loadMoreCatalog"
            >
              {{ catalogLoadingMore ? "Loading…" : "Load 50 More" }}
            </button>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.asn-catalog-thumb {
  width: 40px;
  height: 40px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.asn-catalog-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.asn-catalog-grid {
  max-height: 280px;
  overflow: auto;
}

.asn-catalog-grid__qty {
  width: 4.5rem;
}
</style>
