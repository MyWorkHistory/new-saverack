<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import ShopifyInventorySubnav from "../../components/shopify/ShopifyInventorySubnav.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const MENU_W = 160;
const MENU_H = 48;

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const rows = ref([]);
const q = ref("");
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get("/shopify/inventory", {
      params: { q: q.value || undefined, per_page: 50 },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load Shopify inventory.");
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push({ name: "shopify-inventory-detail", params: { id: String(row.id) } });
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

function onDocClick(e) {
  if (!e.target?.closest?.("[data-shopify-inventory-row-actions]")) {
    manageOpenId.value = null;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Inventory",
    description: "Shopify products and inventory levels.",
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
        <h1 class="h4 mb-1 fw-semibold text-body">Shopify Inventory</h1>
        <p class="small text-secondary mb-0">
          Active Shopify products and location quantities (Shopify-only; not ShipHero stock).
        </p>
      </div>
      <CrmRefreshToolbarButton
        :disabled="loading"
        :loading="loading"
        @click="load"
      />
    </div>

    <ShopifyInventorySubnav products-active />

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row shopify-inventory-toolbar-row">
          <div class="shopify-inventory-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                v-model="q"
                type="search"
                class="form-control"
                placeholder="Search SKU or title…"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search Shopify inventory"
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
        </div>
      </div>

      <div class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th"
                scope="col"
              >SKU</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Product</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Variant</th>
              <th
                class="staff-table-head__th text-end"
                scope="col"
              >Available</th>
              <th
                class="staff-table-head__th"
                scope="col"
              >Account</th>
              <th
                class="staff-table-head__th staff-actions-col text-center"
                scope="col"
              >Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td
                colspan="6"
                class="py-5"
              >
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading Inventory…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td
                colspan="6"
                class="px-4 py-5 text-center text-secondary"
              >
                No active Shopify variants imported yet.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle shopify-inventory-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="fw-semibold text-body">{{ row.sku || "—" }}</td>
              <td class="text-body">{{ row.product_title || "—" }}</td>
              <td class="text-body staff-table-cell__meta">{{ row.title || "—" }}</td>
              <td class="text-end text-body">{{ row.available_total }}</td>
              <td class="text-body staff-table-cell__meta">{{ row.account_name || "—" }}</td>
              <td
                class="staff-actions-cell text-center"
                @click.stop
              >
                <div
                  data-shopify-inventory-row-actions
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
        aria-label="Shopify inventory"
      >
        <div
          v-if="loading"
          class="crm-mobile-item-card__empty"
        >
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading Inventory…" />
          </div>
        </div>
        <div
          v-else-if="!rows.length"
          class="crm-mobile-item-card__empty"
        >
          No active Shopify variants imported yet.
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
                <span class="crm-mobile-item-card__sku crm-mobile-item-card__sku--plain">
                  {{ row.sku || "—" }}
                </span>
              </div>
              <div
                class="crm-mobile-item-card__head-end"
                data-shopify-inventory-row-actions
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
                <div class="crm-mobile-item-card__name">
                  {{ row.product_title || "—" }}
                </div>
                <div class="small text-secondary">{{ row.title || "—" }}</div>
              </div>
            </div>
            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Available</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.available_total }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Account</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.account_name || "—" }}</span>
              </div>
            </div>
          </article>
        </template>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-shopify-inventory-row-actions
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
          Edit
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.shopify-inventory-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.shopify-inventory-search-wrap {
  flex: 0 0 auto;
  width: min(22rem, 100%);
}

.shopify-inventory-result-row {
  cursor: pointer;
}
</style>
