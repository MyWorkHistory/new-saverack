<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { returnStatusBadgeClass, returnStatusLabel } from "../../utils/formatReturnDisplay.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const sortBy = ref("created_at");
const sortDir = ref("desc");

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const tableColspan = 8;
const actionOpenId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 220;
const MENU_H = 88;

function applySearch() {
  if (searchTimer) {
    clearTimeout(searchTimer);
    searchTimer = null;
  }
  searchDebounced.value = search.value.trim().replace(/^#+/, "");
  meta.value.current_page = 1;
  load();
}

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v).trim().replace(/^#+/, "");
    meta.value.current_page = 1;
    load();
  }, 300);
});

function sortIndicator(column) {
  if (sortBy.value !== column) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (sortBy.value !== column) {
    sortBy.value = column;
    sortDir.value = "asc";
  } else {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  }
  meta.value.current_page = 1;
  load();
}

async function load() {
  if (!clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get("/returns", {
      params: {
        client_account_id: clientAccountId.value,
        q: searchDebounced.value || undefined,
        page: meta.value.current_page,
        per_page: meta.value.per_page,
        sort_by: sortBy.value,
        sort_dir: sortDir.value,
      },
    });
    rows.value = data.data || [];
    if (data.meta) meta.value = { ...meta.value, ...data.meta };
  } catch (e) {
    toast.errorFrom(e, "Could not load returned orders.");
  } finally {
    loading.value = false;
  }
}

function returnDetailHref(r) {
  if (!r?.id) return "";
  return router.resolve({ name: "user-return-detail", params: { id: String(r.id) } }).href;
}

function openReturnInNewTab(r) {
  const href = returnDetailHref(r);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function canDeleteReturn(r) {
  return String(r?.status || "").toLowerCase() === "pending";
}

function closeRowActionMenu() {
  actionOpenId.value = null;
}

function placeActionMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  actionMenuRect.value = { top, left };
}

async function toggleRowActionMenu(r, event) {
  event.stopPropagation();
  if (actionOpenId.value === r.id) {
    closeRowActionMenu();
    return;
  }
  const btn = event.currentTarget;
  actionOpenId.value = r.id;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeActionMenu(btn);
  });
}

function onDocClickActions(event) {
  if (!event.target?.closest?.("[data-return-row-actions]")) {
    closeRowActionMenu();
  }
}

function onEscCloseActions(event) {
  if (event.key === "Escape") {
    closeRowActionMenu();
  }
}

function onWindowCloseActions() {
  closeRowActionMenu();
}

async function deleteReturn(r) {
  if (!r?.id || !canDeleteReturn(r)) return;
  const ok = window.confirm("Delete Return?");
  if (!ok) return;
  try {
    await api.delete(`/returns/${r.id}`);
    toast.success("Return deleted.");
    closeRowActionMenu();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete return.");
  }
}

const actionMenuRow = computed(() => rows.value.find((r) => r.id === actionOpenId.value) ?? null);

function goCreate() {
  router.push({ name: "user-return-create-search" });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Return Orders",
    description: "View returned orders that are pending processing or completed.",
  });
  load();
  document.addEventListener("click", onDocClickActions);
  document.addEventListener("keydown", onEscCloseActions);
  window.addEventListener("scroll", onWindowCloseActions, true);
  window.addEventListener("resize", onWindowCloseActions);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickActions);
  document.removeEventListener("keydown", onEscCloseActions);
  window.removeEventListener("scroll", onWindowCloseActions, true);
  window.removeEventListener("resize", onWindowCloseActions);
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Return Orders</h1>
        <p class="text-secondary small mb-0">
          View all returned orders that are pending processing or completed.
        </p>
      </div>
      <button type="button" class="btn btn-primary staff-page-primary" @click="goCreate">Create Return</button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search order #, name, or RMA #"
            autocomplete="off"
            aria-label="Search returns"
            @keydown.enter.prevent="applySearch"
          />
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="loading"
            @click="applySearch"
          >
            Search
          </button>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('order_number')">
                  Order #
                  <span v-if="sortIndicator('order_number')" class="staff-sort-ind">{{ sortIndicator("order_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('customer_name')">
                  Name
                  <span v-if="sortIndicator('customer_name')" class="staff-sort-ind">{{ sortIndicator("customer_name") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('created_at')">
                  Created Date
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('rma_number')">
                  RMA #
                  <span v-if="sortIndicator('rma_number')" class="staff-sort-ind">{{ sortIndicator("rma_number") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th text-center" scope="col">Processed Date</th>
              <th class="staff-table-head__th staff-table-head__th--sort text-center" scope="col">
                <button type="button" class="staff-sort-btn" @click="toggleSort('items_count')">
                  Items
                  <span v-if="sortIndicator('items_count')" class="staff-sort-ind">{{ sortIndicator("items_count") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading returns…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                {{ searchDebounced ? "No returns match your search." : "No return orders yet." }}
              </td>
            </tr>
            <tr v-for="r in rows" v-else :key="r.id" class="align-middle">
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="returnStatusBadgeClass(r.status)">
                  {{ returnStatusLabel(r.status) }}
                </span>
              </td>
              <td class="text-center">
                <a
                  v-if="returnDetailHref(r)"
                  :href="returnDetailHref(r)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-return-page__order-link"
                >
                  {{ r.order_number || "—" }}
                </a>
                <span v-else>—</span>
              </td>
              <td class="text-center">{{ r.customer_name || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(r.created_at) || "—" }}</td>
              <td class="text-center fw-semibold">{{ r.rma_number || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(r.processed_at) || "—" }}</td>
              <td class="text-center">{{ Number(r.items_count ?? 0).toLocaleString() }}</td>
              <td class="text-center staff-actions-cell" @click.stop>
                <div data-return-row-actions class="staff-actions-inner staff-actions-inner--single">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :aria-expanded="actionOpenId === r.id"
                    aria-label="Row Actions"
                    @click="toggleRowActionMenu(r, $event)"
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
        v-if="!loading && meta.last_page > 1"
        class="d-flex justify-content-between align-items-center px-3 py-3 border-top flex-wrap gap-2"
      >
        <span class="small text-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="
              meta.current_page--;
              load();
            "
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="
              meta.current_page++;
              load();
            "
          >
            Next
          </button>
        </div>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>
    </div>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="actionMenuRow"
          data-return-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${actionMenuRect.top}px`, left: `${actionMenuRect.left}px` }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openReturnInNewTab(actionMenuRow)">
            View
          </button>
          <button
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            :disabled="!canDeleteReturn(actionMenuRow)"
            @click="deleteReturn(actionMenuRow)"
          >
            Delete Return
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
