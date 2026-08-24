<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import AdminEmailCreateDrawer from "../../components/admin-email/AdminEmailCreateDrawer.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

const canManage = () => {
  const u = crmUser.value;
  if (!u) return false;
  return crmIsAdmin(u) || !!u.is_crm_owner;
};

setCrmPageMeta({
  title: "Save Rack | Email",
  description: "Admin broadcast emails to client primary users.",
});

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const fromOptions = ref([
  { address: "info@saverack.com", name: "Save Rack" },
  { address: "audi@saverack.com", name: "Audi K | Save Rack" },
]);

const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const createOpen = ref(false);
const createBusy = ref(false);
const sendConfirmOpen = ref(false);
const sendConfirmBusy = ref(false);
const pendingPayload = ref(null);
const recipientCount = ref(0);

const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

async function loadList(page = 1) {
  loading.value = true;
  try {
    const { data } = await api.get("/admin/broadcast-emails", {
      params: {
        page,
        per_page: meta.value.per_page || 25,
        q: searchDebounced.value || undefined,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    meta.value = {
      current_page: Number(data?.meta?.current_page || 1),
      last_page: Number(data?.meta?.last_page || 1),
      per_page: Number(data?.meta?.per_page || 25),
      total: Number(data?.meta?.total || 0),
    };
    if (Array.isArray(data?.from_options) && data.from_options.length) {
      fromOptions.value = data.from_options;
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load emails.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openManage(row, event) {
  const btn = event?.currentTarget;
  const rect = btn?.getBoundingClientRect?.();
  const MENU_W = 160;
  if (rect) {
    let left = Math.round(rect.right - MENU_W);
    left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
    manageMenuRect.value = {
      top: Math.round(rect.bottom + 4),
      left,
    };
  }
  manageOpenId.value = manageOpenId.value === row.id ? null : row.id;
}

function requestDelete(row) {
  deleteTarget.value = row;
  manageOpenId.value = null;
  deleteOpen.value = true;
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/admin/broadcast-emails/${deleteTarget.value.id}`);
    toast.success("Email deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await loadList(meta.value.current_page);
  } catch (e) {
    toast.errorFrom(e, "Could not delete email.");
  } finally {
    deleteBusy.value = false;
  }
}

async function onCreateSubmit(payload) {
  pendingPayload.value = payload;
  createBusy.value = true;
  try {
    const { data } = await api.get("/admin/broadcast-emails/recipient-count");
    recipientCount.value = Number(data?.recipient_count || 0);
    if (Array.isArray(data?.from_options) && data.from_options.length) {
      fromOptions.value = data.from_options;
    }
    sendConfirmOpen.value = true;
  } catch (e) {
    toast.errorFrom(e, "Could not load recipient count.");
    pendingPayload.value = null;
  } finally {
    createBusy.value = false;
  }
}

async function confirmSend() {
  if (!pendingPayload.value) return;
  sendConfirmBusy.value = true;
  try {
    await api.post("/admin/broadcast-emails", pendingPayload.value);
    toast.success("Email queued for delivery.");
    sendConfirmOpen.value = false;
    createOpen.value = false;
    pendingPayload.value = null;
    await loadList(1);
  } catch (e) {
    toast.errorFrom(e, "Could not send email.");
  } finally {
    sendConfirmBusy.value = false;
  }
}

function goDetail(row) {
  if (!row?.id) return;
  manageOpenId.value = null;
  router.push(`/admin/email/${row.id}`);
}

function onDocClick(e) {
  if (e.target?.closest?.("[data-row-actions]")) return;
  manageOpenId.value = null;
}

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v || "").trim();
  }, 300);
});

watch(searchDebounced, () => {
  loadList(1);
});

onMounted(() => {
  document.addEventListener("click", onDocClick);
  loadList(1);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div class="d-flex align-items-center gap-2 min-w-0">
        <span class="order-detail-page__section-icon order-detail-page__section-icon--details flex-shrink-0" aria-hidden="true">
          <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
        </span>
        <div>
          <h1 class="h4 mb-1 fw-semibold text-body">Email</h1>
          <p class="text-secondary small mb-0">
            Broadcast client updates to primary account users.
          </p>
        </div>
      </div>
      <button
        v-if="canManage()"
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold"
        @click="createOpen = true"
      >
        Create Email
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="admin-email-search"
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            style="max-width: 320px"
            placeholder="Search by subject"
            autocomplete="off"
            aria-label="Search by subject"
          />
        </div>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center d-none d-lg-flex">
        <CrmLoadingSpinner message="Loading emails…" />
      </div>
      <div v-else class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th" scope="col">Subject</th>
              <th class="staff-table-head__th text-center" scope="col">Qty Sent</th>
              <th class="staff-table-head__th text-center staff-actions-col" scope="col">
                Action
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="4" class="text-center text-secondary py-4">No emails found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td>{{ formatDateUs(row.sent_at || row.created_at) || "—" }}</td>
              <td>
                <RouterLink
                  :to="`/admin/email/${row.id}`"
                  class="fw-semibold text-decoration-none"
                >
                  {{ row.subject || "—" }}
                </RouterLink>
              </td>
              <td class="text-center">{{ row.qty_sent ?? 0 }}</td>
              <td class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="(e) => openManage(row, e)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="crm-mobile-item-cards d-lg-none" aria-label="Emails">
        <div v-if="loading" class="crm-mobile-item-card__empty">
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading emails…" />
          </div>
        </div>
        <div v-else-if="!rows.length" class="crm-mobile-item-card__empty">No emails found.</div>
        <template v-else>
          <article v-for="row in rows" :key="`mobile-${row.id}`" class="crm-mobile-item-card">
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start">
                <span class="small text-secondary">
                  {{ formatDateUs(row.sent_at || row.created_at) || "—" }}
                </span>
              </div>
              <div class="crm-mobile-item-card__head-end" data-row-actions>
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': manageOpenId === row.id }"
                  :aria-expanded="manageOpenId === row.id"
                  aria-haspopup="true"
                  aria-label="Row actions"
                  @click="(e) => openManage(row, e)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>
            <div class="crm-mobile-item-card__product">
              <div class="crm-mobile-item-card__copy">
                <RouterLink
                  :to="`/admin/email/${row.id}`"
                  class="crm-mobile-item-card__name text-decoration-none"
                >
                  {{ row.subject || "—" }}
                </RouterLink>
              </div>
            </div>
            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Qty Sent</span>
                <span class="crm-mobile-item-card__meta-value">{{ row.qty_sent ?? 0 }}</span>
              </div>
            </div>
          </article>
        </template>
      </div>

      <div
        v-if="meta.last_page > 1 || meta.total > 0"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">
          {{ meta.total }} total · Page {{ meta.current_page }} of {{ meta.last_page }}
        </span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="loading || meta.current_page <= 1"
            @click="loadList(meta.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="loading || meta.current_page >= meta.last_page"
            @click="loadList(meta.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="goDetail(manageMenuRow)"
        >
          View
        </button>
        <button
          v-if="canManage()"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="requestDelete(manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <AdminEmailCreateDrawer
      v-model:open="createOpen"
      :busy="createBusy || sendConfirmBusy"
      :from-options="fromOptions"
      @submit="onCreateSubmit"
    />

    <ConfirmModal
      :open="sendConfirmOpen"
      title="Send Email"
      :message="`Send this email to ${recipientCount} primary user${
        recipientCount === 1 ? '' : 's'
      } on non-inactive accounts?`"
      confirm-label="Send"
      :busy="sendConfirmBusy"
      :danger="false"
      @close="
        sendConfirmOpen = false;
        pendingPayload = null;
      "
      @confirm="confirmSend"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Email"
      :message="
        deleteTarget
          ? `Delete “${deleteTarget.subject}” from this list? This does not recall messages already sent.`
          : ''
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
  </div>
</template>
