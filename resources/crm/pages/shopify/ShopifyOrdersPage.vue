<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { formatDateTimeUs } from "../../utils/formatUserDates";

const MENU_W = 160;
const MENU_H = 48;

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const rows = ref([]);
const q = ref("");
const fulfillmentStatus = ref("all");
const filterMenuOpen = ref(false);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

function fulfillmentBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "fulfilled") return "bg-success-subtle text-success-emphasis";
  if (s === "partial" || s === "partially_fulfilled") return "bg-warning-subtle text-warning-emphasis";
  if (s === "unfulfilled" || !s) return "bg-secondary-subtle text-secondary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function fulfillmentLabel(status) {
  const s = String(status || "").trim();
  if (!s) return "Unfulfilled";
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function financialLabel(status) {
  const s = String(status || "").trim();
  if (!s) return "—";
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatMoney(row) {
  if (row?.total_price == null) return "—";
  const currency = row.currency ? `${row.currency} ` : "";
  return `${currency}${row.total_price}`;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/orders", {
      params: {
        q: q.value || undefined,
        fulfillment_status: fulfillmentStatus.value,
        per_page: 50,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify orders.");
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push({ name: "shopify-order-detail", params: { id: String(row.id) } });
}

function placeManageMenu(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

async function toggleManageMenu(row, e) {
  e?.stopPropagation?.();
  const id = row?.id;
  if (manageOpenId.value === id) {
    manageOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  manageOpenId.value = id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeManageMenu(btn);
  });
}

function resetFilters() {
  fulfillmentStatus.value = "all";
  void load();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-orders-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-shopify-orders-row-actions]")) {
    manageOpenId.value = null;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Orders",
    description: "Shopify orders from connected stores.",
  });
  document.addEventListener("click", onDocClick);
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Shopify Orders</h1>
        <p class="small text-secondary mb-0">
          Orders from connected Shopify stores. Webhooks update in near real-time; backup sync runs every 5 minutes.
        </p>
      </div>
      <CrmRefreshToolbarButton
        :disabled="loading"
        :loading="loading"
        @click="load"
      />
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row shopify-orders-toolbar-row">
          <div class="shopify-orders-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                v-model="q"
                type="search"
                class="form-control"
                placeholder="Search name, email…"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search Shopify orders"
                :disabled="loading"
                @keydown.enter.prevent="load"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                :disabled="loading"
                @click="load"
              >
                Search
              </button>
            </div>
          </div>

          <div
            class="position-relative flex-shrink-0"
            data-shopify-orders-filter
          >
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Shopify order filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label
                  class="form-label"
                  for="shopify-orders-filter-fulfillment"
                >Fulfillment</label>
                <select
                  id="shopify-orders-filter-fulfillment"
                  v-model="fulfillmentStatus"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="load"
                >
                  <option value="all">All Fulfillment</option>
                  <option value="unfulfilled">Unfulfilled</option>
                  <option value="partial">Partial</option>
                  <option value="fulfilled">Fulfilled</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th"
                scope="col"
              >Order</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Account</th>
              <th
                class="staff-table-head__th text-center"
                scope="col"
              >Financial</th>
              <th
                class="staff-table-head__th text-center"
                scope="col"
              >Fulfillment</th>
              <th
                class="staff-table-head__th text-end"
                scope="col"
              >Total</th>
              <th
                class="staff-table-head__th text-center"
                scope="col"
              >Created</th>
              <th
                class="staff-table-head__th staff-actions-col text-center"
                scope="col"
              >Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td
                colspan="7"
                class="py-5"
              >
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Orders…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td
                colspan="7"
                class="px-4 py-5 text-center text-secondary"
              >
                No Shopify orders yet. Connect a store under Account → Settings and import.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle shopify-orders-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="fw-semibold text-body">{{ row.name || "—" }}</td>
              <td class="text-body staff-table-cell__meta">{{ row.account_name || "—" }}</td>
              <td class="text-center text-body staff-table-cell__meta">
                {{ financialLabel(row.financial_status) }}
              </td>
              <td class="text-center">
                <span
                  class="badge rounded-pill fw-medium"
                  :class="fulfillmentBadgeClass(row.fulfillment_status)"
                >
                  {{ fulfillmentLabel(row.fulfillment_status) }}
                </span>
              </td>
              <td class="text-end text-body staff-table-cell__meta text-nowrap">
                {{ formatMoney(row) }}
              </td>
              <td class="text-center text-body staff-table-cell__meta text-nowrap">
                {{ formatDateTimeUs(row.shopify_created_at) || "—" }}
              </td>
              <td
                class="staff-actions-cell text-center"
                @click.stop
              >
                <div
                  data-shopify-orders-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        class="crm-mobile-item-cards d-lg-none"
        aria-label="Shopify orders"
      >
        <div
          v-if="loading"
          class="crm-mobile-item-card__empty"
        >
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading Orders…" />
          </div>
        </div>
        <div
          v-else-if="!rows.length"
          class="crm-mobile-item-card__empty"
        >
          No Shopify orders yet.
        </div>
        <template v-else>
          <article
            v-for="row in rows"
            :key="`mobile-${row.id}`"
            class="crm-mobile-item-card"
            @click="openRow(row)"
          >
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start">
                <span
                  class="badge rounded-pill fw-medium"
                  :class="fulfillmentBadgeClass(row.fulfillment_status)"
                >
                  {{ fulfillmentLabel(row.fulfillment_status) }}
                </span>
              </div>
              <div
                class="crm-mobile-item-card__head-end"
                data-shopify-orders-row-actions
                @click.stop
              >
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': manageOpenId === row.id }"
                  :aria-expanded="manageOpenId === row.id ? 'true' : 'false'"
                  aria-haspopup="true"
                  aria-label="Row actions"
                  @click="toggleManageMenu(row, $event)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>
            <div class="crm-mobile-item-card__product">
              <div class="crm-mobile-item-card__copy">
                <span class="crm-mobile-item-card__sku crm-mobile-item-card__sku--plain">
                  {{ row.name || "—" }}
                </span>
                <div class="crm-mobile-item-card__name">
                  {{ row.account_name || "—" }}
                </div>
              </div>
            </div>
            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Total</span>
                <span class="crm-mobile-item-card__meta-value">{{ formatMoney(row) }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Created</span>
                <span class="crm-mobile-item-card__meta-value">
                  {{ formatDateTimeUs(row.shopify_created_at) || "—" }}
                </span>
              </div>
            </div>
          </article>
        </template>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-shopify-orders-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openRow(manageMenuRow)"
        >
          View
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.shopify-orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.shopify-orders-search-wrap {
  flex: 0 0 auto;
  width: min(22rem, 100%);
}

.shopify-orders-result-row {
  cursor: pointer;
}
</style>
