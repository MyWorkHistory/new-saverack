<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
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

setCrmPageMeta({
  title: "Save Rack | Email",
  description: "Broadcast email detail.",
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

onMounted(load);
</script>

<template>
  <div class="staff-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div class="d-flex align-items-center gap-2 min-w-0">
        <RouterLink
          to="/admin/email"
          class="btn btn-outline-secondary btn-sm flex-shrink-0"
        >
          Back
        </RouterLink>
        <div class="min-w-0">
          <h1 class="h4 mb-0 fw-semibold text-body text-truncate">
            {{ email?.subject || "Email" }}
          </h1>
        </div>
      </div>
      <button
        v-if="canManage && email"
        type="button"
        class="btn btn-outline-danger"
        @click="deleteOpen = true"
      >
        Delete
      </button>
    </div>

    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading email…" :center="true" />
    </div>

    <div v-else-if="!email" class="text-secondary">Email not found.</div>

    <div v-else class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
      <dl class="row mb-4 admin-email-detail__meta">
        <dt class="col-sm-3 text-secondary">From</dt>
        <dd class="col-sm-9">{{ email.from_address || "—" }}</dd>

        <dt class="col-sm-3 text-secondary">Subject</dt>
        <dd class="col-sm-9 fw-semibold">{{ email.subject || "—" }}</dd>

        <dt class="col-sm-3 text-secondary">Date Sent</dt>
        <dd class="col-sm-9">
          {{ formatDateUs(email.sent_at || email.created_at) || "—" }}
        </dd>

        <dt class="col-sm-3 text-secondary">QTY Sent</dt>
        <dd class="col-sm-9">
          {{ email.qty_sent ?? 0 }}
          <span v-if="email.recipient_count != null" class="text-secondary">
            / {{ email.recipient_count }} recipients
          </span>
        </dd>

        <dt class="col-sm-3 text-secondary">Status</dt>
        <dd class="col-sm-9 text-capitalize">{{ email.status || "—" }}</dd>
      </dl>

      <div class="border-top pt-3">
        <h2 class="h6 fw-semibold mb-3">Message Body</h2>
        <div
          class="admin-email-detail__body"
          v-html="email.body_html || '<p class=\"text-secondary\">—</p>'"
        />
        <p class="small text-secondary mt-3 mb-0">
          The From signature was appended when the message was sent and is not stored in this body.
        </p>
      </div>
    </div>

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
