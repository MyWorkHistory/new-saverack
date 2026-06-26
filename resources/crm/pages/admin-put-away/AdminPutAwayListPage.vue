<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const LIST_PAGE_SIZE = 20;

const toast = useToast();
const router = useRouter();

const rows = ref([]);
const loading = ref(false);
const loadingMore = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref("");
const searchDraft = ref("");
const searchCommitted = ref("");
const searchSkipNext = ref(0);
const pageInfo = ref({ has_next_page: false, end_cursor: null });
const meta = ref({ computed_at: null, row_count: 0, source: null, status: null });

const lineMenuOpenSku = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });
const LINE_MENU_W = 180;

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || `Account #${a.id}`,
    email: a.has_shiphero_customer ? "" : "(No ShipHero)",
  })),
);

const accountId = computed(() => Number(selectedAccountId.value || 0));
const hasAccountFilter = computed(() => accountId.value > 0);

const accountNameById = computed(() => {
  const map = new Map();
  for (const account of accounts.value || []) {
    map.set(account.id, account.company_name || `Account #${account.id}`);
  }
  return map;
});

function accountLabelForRow(row) {
  const id = Number(row?.client_account_id || 0);
  if (id <= 0) return "—";
  return accountNameById.value.get(id) || `Account #${id}`;
}

const lastUpdatedLabel = computed(() => {
  const raw = meta.value?.computed_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const emptyTableMessage = computed(() => {
  if (hasAccountFilter.value) {
    return "No products in Receiving for this account. Receive inventory on an ASN to add SKUs here.";
  }
  return "No products in Receiving. Receive inventory on an ASN to add SKUs here.";
});

function applyListPayload(data, append = false) {
  const nextRows = Array.isArray(data?.rows) ? data.rows : [];
  rows.value = append ? [...rows.value, ...nextRows] : nextRows;
  pageInfo.value = data?.page_info || { has_next_page: false, end_cursor: null };
  meta.value = data?.meta || meta.value;
  if (typeof data?.page_info?.next_search_skip === "number") {
    searchSkipNext.value = Number(data.page_info.next_search_skip);
  } else if (!append) {
    searchSkipNext.value = 0;
  }
}

function putAwayDetailTo(row) {
  const sku = String(row?.sku || "").trim();
  const clientAccountId = Number(row?.client_account_id || accountId.value || 0);
  if (!sku || clientAccountId <= 0) {
    return { name: "admin-put-away" };
  }
  return {
    name: "admin-put-away-detail",
    params: { sku },
    query: { client_account_id: String(clientAccountId) },
  };
}

function putAwayDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  const clientAccountId = Number(row?.client_account_id || accountId.value || 0);
  if (!sku || clientAccountId <= 0) return "";
  return router.resolve(putAwayDetailTo(row)).href;
}

function openPutAwayInNewTab(row, event) {
  event?.preventDefault?.();
  const href = putAwayDetailHref(row);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
}

async function fetchPage(append) {
  const params = {
    first: LIST_PAGE_SIZE,
  };
  if (hasAccountFilter.value) {
    params.client_account_id = accountId.value;
  }
  const q = searchCommitted.value.trim();
  if (q) {
    params.query = q;
  } else if (append && pageInfo.value?.end_cursor) {
    params.after = pageInfo.value.end_cursor;
  }
  const { data } = await api.get("/admin/put-away", { params });
  applyListPayload(data, append);
}

async function loadRows(reset = false) {
  if (reset) {
    loading.value = true;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    searchSkipNext.value = 0;
  } else {
    loadingMore.value = true;
  }
  try {
    await fetchPage(!reset);
  } catch (e) {
    toast.errorFrom(e, "Could not load put away list.");
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}

async function loadMore() {
  if (!pageInfo.value?.has_next_page || loadingMore.value || loading.value) return;
  await loadRows(false);
}

function commitSearch() {
  searchCommitted.value = searchDraft.value.trim();
  searchSkipNext.value = 0;
  loadRows(true);
}

function onAccountChange() {
  searchDraft.value = "";
  searchCommitted.value = "";
  searchSkipNext.value = 0;
  rows.value = [];
  loadRows(true);
}

function toggleLineMenu(sku, event) {
  if (lineMenuOpenSku.value === sku) {
    lineMenuOpenSku.value = null;
    return;
  }
  const rect = event?.currentTarget?.getBoundingClientRect?.();
  if (!rect) return;
  lineMenuOpenSku.value = sku;
  lineMenuRect.value = {
    top: Math.max(8, rect.bottom + 4),
    left: Math.max(8, rect.right - LINE_MENU_W),
  };
}

function closeLineMenu() {
  lineMenuOpenSku.value = null;
}

function openFromMenu(row) {
  openPutAwayInNewTab(row);
  closeLineMenu();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    closeLineMenu();
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Put Away",
    description: "Move inventory from Receiving to warehouse locations.",
  });
  await loadAccounts();
  await loadRows(true);
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-bold text-body">Put Away</h1>
        <p class="text-secondary small mb-0">
          Products received into Receiving via ASN appear here. Optionally filter by account.
        </p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="lastUpdatedLabel" class="small text-secondary mb-0">
          Last Updated: {{ lastUpdatedLabel }}
        </p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 put-away-list-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row inventory-toolbar-row">
          <div class="inventory-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              button-id="put-away-list-account-trigger"
              @update:model-value="onAccountChange"
            />
          </div>
          <div class="user-inv-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by name, SKU, or barcode"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by name, SKU, or barcode"
                :disabled="loading"
                @keydown.enter.prevent="commitSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn"
                :disabled="loading"
                @click="commitSearch"
              >
                Search
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading && !rows.length" class="text-center py-5">
        <CrmLoadingSpinner label="Loading put away list…" />
      </div>

      <div v-else class="table-responsive staff-table-wrap">
        <table
          class="table table-hover align-middle mb-0 staff-data-table user-inv-table put-away-list-table"
          :class="{ 'put-away-list-table--syncing': loading || loadingMore }"
        >
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th user-inv-table__image-col" scope="col">Image</th>
              <th class="staff-table-head__th user-inv-table__sku-col" scope="col">SKU</th>
              <th class="staff-table-head__th user-inv-table__name-col" scope="col">Name</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Account</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Barcode</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Receiving</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Pickable</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Non-Pickable</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">On-Hand</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Backorder</th>
              <th class="staff-table-head__th text-center put-away-list-table__action-col" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="11" class="text-center text-secondary py-4">
                {{ emptyTableMessage }}
              </td>
            </tr>
            <tr v-for="row in rows" :key="`${row.client_account_id}-${row.sku}`">
              <td class="text-center user-inv-table__image-col">
                <a
                  :href="putAwayDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__image-link"
                  :aria-label="`View put away for ${row.sku || 'product'}`"
                  @click="openPutAwayInNewTab(row, $event)"
                >
                  <img
                    v-if="row.image_url"
                    :src="row.image_url"
                    alt=""
                    class="user-inventory-thumb"
                    loading="lazy"
                  />
                  <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
                </a>
              </td>
              <td class="user-inv-table__sku-col">
                <a
                  :href="putAwayDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link"
                  @click="openPutAwayInNewTab(row, $event)"
                >
                  {{ row.sku || "—" }}
                </a>
              </td>
              <td class="user-inv-table__name-col">
                <a
                  :href="putAwayDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__sku-link user-inv-table__name-link"
                  @click="openPutAwayInNewTab(row, $event)"
                >
                  <span class="user-inv-table__name-text" :title="row.name || undefined">{{ row.name || "—" }}</span>
                </a>
              </td>
              <td class="user-inv-table__text-col small">{{ accountLabelForRow(row) }}</td>
              <td class="user-inv-table__text-col small">{{ row.barcode || "—" }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.receiving_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.pickable_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.non_pickable_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.on_hand ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.backorder ?? 0).toLocaleString() }}</td>
              <td class="text-center put-away-list-table__action-col" @click.stop>
                <div data-row-actions class="position-relative d-inline-block">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': lineMenuOpenSku === row.sku }"
                    aria-haspopup="true"
                    :aria-expanded="lineMenuOpenSku === row.sku ? 'true' : 'false'"
                    aria-label="Row actions"
                    @click.stop="toggleLineMenu(row.sku, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pageInfo.has_next_page" class="p-3 border-top text-center">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
          :disabled="loadingMore || loading"
          @click="loadMore"
        >
          {{ loadingMore ? "Loading…" : "Load 20 More" }}
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="lineMenuOpenSku"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px`, minWidth: `${LINE_MENU_W}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openFromMenu(rows.find((r) => r.sku === lineMenuOpenSku))"
        >
          Open Put Away
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.put-away-list-toolbar .inventory-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.put-away-list-table {
  table-layout: fixed;
  width: 100%;
}

.put-away-list-table--syncing {
  opacity: 0.55;
  pointer-events: none;
}

.put-away-list-table .user-inv-table__image-col {
  width: 1%;
  min-width: 4.5rem;
  text-align: center;
  vertical-align: middle;
}

.put-away-list-table .user-inv-table__text-col,
.put-away-list-table .user-inv-table__sku-col,
.put-away-list-table .user-inv-table__name-col {
  text-align: start;
  vertical-align: middle;
}

.put-away-list-table .user-inv-table__sku-col {
  width: 6.5rem;
  min-width: 5rem;
}

.put-away-list-table .user-inv-table__name-col {
  width: 12rem;
  min-width: 0;
  max-width: 12rem;
}

.put-away-list-table .user-inv-table__text-col {
  width: 6rem;
  min-width: 4.5rem;
}

.put-away-list-table .user-inv-table__image-link {
  display: inline-block;
  line-height: 0;
  text-decoration: none;
}

.put-away-list-table .user-inv-table__sku-link {
  color: #2563eb;
  font-weight: 600;
  text-decoration: none;
}

.put-away-list-table .user-inv-table__sku-link:hover {
  color: #1d4ed8;
  text-decoration: underline;
}

.put-away-list-table .user-inv-table__name-link {
  font-weight: 400;
}

.put-away-list-table .user-inv-table__name-text {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  white-space: normal;
  word-break: break-word;
  line-height: 1.35;
  max-height: calc(1.35em * 2);
}

.put-away-list-table .user-inv-table__num-col {
  min-width: 3.75rem;
  width: 3.75rem;
  padding-left: 0.2rem;
  padding-right: 0.2rem;
  font-size: 0.8125rem;
}

.put-away-list-table .put-away-list-table__action-col {
  width: 3.25rem;
  min-width: 3.25rem;
  padding-left: 0.25rem;
  padding-right: 0.25rem;
}

.put-away-list-table .user-inventory-thumb {
  width: 52px;
  height: 52px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.put-away-list-table .user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}
</style>
