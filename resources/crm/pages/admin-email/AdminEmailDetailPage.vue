<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import AccountDetailSectionHead from "../../components/clients/AccountDetailSectionHead.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  id: { type: [String, Number], required: true },
});

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

const canManage = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  return crmIsAdmin(u) || !!u.is_crm_owner;
});

const loading = ref(true);
const email = ref(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const actionsOpen = ref(false);
const actionsRect = ref({ top: 0, left: 0 });

setCrmPageMeta({
  title: "Save Rack | Email",
  description: "Broadcast email detail.",
});

const statusBadgeClass = computed(() => {
  const status = String(email.value?.status || "").toLowerCase();
  if (status === "sent") return "staff-status-badge staff-status-badge--success";
  if (status === "failed") return "staff-status-badge staff-status-badge--danger";
  if (status === "queued" || status === "sending") return "staff-status-badge staff-status-badge--warning";
  return "staff-status-badge staff-status-badge--secondary";
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/broadcast-emails/${props.id}`);
    email.value = data;
    const subject = data?.subject ? String(data.subject) : "Email";
    setCrmPageMeta({
      title: `Save Rack | ${subject}`,
      description: "Broadcast email detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load email.");
    email.value = null;
  } finally {
    loading.value = false;
  }
}

function openActions(event) {
  const btn = event?.currentTarget;
  const rect = btn?.getBoundingClientRect?.();
  const MENU_W = 160;
  if (rect) {
    let left = Math.round(rect.right - MENU_W);
    left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
    actionsRect.value = { top: Math.round(rect.bottom + 4), left };
  }
  actionsOpen.value = !actionsOpen.value;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionsOpen.value = false;
  }
}

async function confirmDelete() {
  if (!email.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/admin/broadcast-emails/${email.value.id}`);
    toast.success("Email deleted.");
    router.push("/admin/email");
  } catch (e) {
    toast.errorFrom(e, "Could not delete email.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-user-view staff-page--wide staff-page">
    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading email…" :center="true" />
    </div>

    <template v-else-if="email">
      <nav
        class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 mb-3"
        aria-label="Breadcrumb"
      >
        <RouterLink to="/admin/home">Home</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <RouterLink to="/admin/email">Email</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <span class="text-body-secondary">{{ email.subject || "Email" }}</span>
      </nav>

      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div class="min-w-0">
          <RouterLink to="/admin/email" class="small text-decoration-none d-inline-block mb-2">
            ← Back to Email
          </RouterLink>
          <h1 class="staff-user-view__title h4 fw-semibold mb-0 text-truncate">
            {{ email.subject || "Email" }}
          </h1>
        </div>
        <div v-if="canManage" data-row-actions class="position-relative">
          <button
            type="button"
            class="staff-action-btn staff-action-btn--more"
            :class="{ 'is-open': actionsOpen }"
            aria-haspopup="true"
            aria-label="Email actions"
            @click="openActions"
          >
            <CrmIconRowActions variant="horizontal" />
          </button>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
        <AccountDetailSectionHead title="Details" icon="details" head-class="mb-3" />
        <dl class="row mb-0 admin-email-detail__meta">
          <dt class="col-sm-3 text-secondary">From</dt>
          <dd class="col-sm-9">{{ email.from_address || "—" }}</dd>

          <dt class="col-sm-3 text-secondary">Date</dt>
          <dd class="col-sm-9">
            {{ formatDateUs(email.sent_at || email.created_at) || "—" }}
          </dd>

          <dt class="col-sm-3 text-secondary">Qty Sent</dt>
          <dd class="col-sm-9">
            {{ email.qty_sent ?? 0 }}
            <span v-if="email.recipient_count != null" class="text-secondary">
              / {{ email.recipient_count }} recipients
            </span>
          </dd>

          <dt class="col-sm-3 text-secondary">Status</dt>
          <dd class="col-sm-9">
            <span class="text-capitalize" :class="statusBadgeClass">
              {{ email.status || "—" }}
            </span>
          </dd>
        </dl>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
        <AccountDetailSectionHead title="Message Body" icon="notes" head-class="mb-3" />
        <div v-if="email.body_html" class="admin-email-detail__body" v-html="email.body_html" />
        <p v-else class="text-secondary mb-0">—</p>
        <p class="small text-secondary mt-3 mb-0">
          The From signature was appended when the message was sent and is not stored in this body.
        </p>
      </div>
    </template>

    <div v-else class="text-secondary">Email not found.</div>

    <Teleport to="body">
      <div
        v-if="actionsOpen && canManage"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${actionsRect.top}px`, left: `${actionsRect.left}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="
            actionsOpen = false;
            deleteOpen = true;
          "
        >
          Delete
        </button>
      </div>
    </Teleport>

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Email"
      :message="
        email
          ? `Delete “${email.subject}” from this list? This does not recall messages already sent.`
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

<style scoped>
.admin-email-detail__meta dt {
  font-weight: 500;
}
.admin-email-detail__meta dd {
  margin-bottom: 0.75rem;
}
.admin-email-detail__body :deep(a) {
  color: #2563eb;
}
.admin-email-detail__body :deep(p) {
  margin-bottom: 0.75rem;
}
</style>
