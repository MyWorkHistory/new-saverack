<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { formatDateUs } from "../../utils/formatUserDates";
import { useToast } from "../../composables/useToast";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const props = defineProps({
  accountId: { type: String, required: true },
  userId: { type: String, required: true },
});

const router = useRouter();
const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canDelete = computed(() => userHasPerm("client_users.delete"));

const loading = ref(true);
const errorMsg = ref("");
const row = ref(null);

const deleteOpen = ref(false);
const deleteBusy = ref(false);

const avatarPalettes = [
  "bg-primary-subtle text-primary-emphasis",
  "bg-info-subtle text-info-emphasis",
  "bg-warning-subtle text-warning-emphasis",
  "bg-success-subtle text-success-emphasis",
  "bg-danger-subtle text-danger-emphasis",
];

function avatarClassForEmail(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) {
    h = (h + s.charCodeAt(i)) % 997;
  }
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "badge bg-success-subtle text-success-emphasis";
  }
  if (s === "pending") {
    return "badge bg-warning-subtle text-warning-emphasis";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-light text-secondary";
}

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

const accountDetailLink = computed(() => ({
  name: "client-account-detail",
  params: { id: props.accountId },
}));

async function load() {
  loading.value = true;
  errorMsg.value = "";
  row.value = null;
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/account-users/${props.userId}`,
    );
    row.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to this profile.";
    } else if (st === 404) {
      errorMsg.value = "User not found.";
    } else {
      errorMsg.value = "Could not load user.";
    }
  } finally {
    loading.value = false;
  }
}

function confirmDelete() {
  if (!row.value || row.value.is_account_primary) return;
  deleteOpen.value = true;
}

async function runDelete() {
  if (!row.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(
      `/client-accounts/${props.accountId}/account-users/${props.userId}`,
    );
    toast.success("User deleted.");
    deleteOpen.value = false;
    router.replace({ name: "client-users" });
  } catch (e) {
    toast.errorFrom(e, "Could not delete user.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  load();
});

watch(
  () => [props.accountId, props.userId],
  () => {
    load();
  },
);

watch(
  () => row.value?.name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | Client user: ${name}`,
        description: `Portal user ${name}.`,
      });
    }
  },
);

</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/dashboard">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink :to="{ name: 'client-users' }">Client users</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Profile</span>
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-center justify-content-between gap-2"
    >
      <h1 class="staff-user-view__title">Portal user</h1>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading profile…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">
        {{ errorMsg }}
      </p>
      <RouterLink :to="{ name: 'client-users' }" class="small"
        >Back to directory</RouterLink
      >
    </template>

    <template v-else-if="row">
      <div class="row g-3">
        <div class="col-12 col-xl-4">
          <aside class="staff-user-profile">
            <div class="staff-user-profile__avatar-wrap">
              <span
                class="staff-user-profile__avatar staff-user-profile__avatar--initials d-inline-flex"
                :class="avatarClassForEmail(row.email)"
              >
                {{ initials(row.name) }}
              </span>
            </div>
            <h2 class="staff-user-profile__name">
              {{ row.name }}
            </h2>
            <div class="staff-user-profile__role-pill">
              <span class="badge rounded-pill bg-body-secondary text-body px-3 py-2">
                {{ row.account_user_role_label || row.account_user_role || "—" }}
              </span>
              <span
                v-if="row.is_account_primary"
                class="badge rounded-pill bg-primary-subtle text-primary-emphasis ms-1"
                >Primary admin</span
              >
            </div>
            <div class="staff-user-profile__stats">
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val text-truncate" style="max-width: 100%">
                  {{ display(row.company_name) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Company</div>
              </div>
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ row.is_account_primary ? "Yes" : "No" }}
                </div>
                <div class="staff-user-profile__stat-lbl">Primary</div>
              </div>
            </div>

            <h3 class="staff-user-profile__details-title">Details</h3>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd text-break">{{ display(row.email) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Status</dt>
                <dd class="staff-user-profile__dd text-capitalize">
                  <span :class="statusBadgeClass(row.status)">{{ row.status }}</span>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Account email</dt>
                <dd class="staff-user-profile__dd text-break">{{ display(row.account_email) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Created</dt>
                <dd class="staff-user-profile__dd">{{ formatDateUs(row.created_at) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Updated</dt>
                <dd class="staff-user-profile__dd">{{ formatDateUs(row.updated_at) }}</dd>
              </div>
            </dl>

            <div class="staff-user-profile__actions d-flex flex-wrap gap-2">
              <RouterLink
                :to="{ name: 'client-users' }"
                class="btn btn-sm btn-outline-secondary"
              >
                Back to directory
              </RouterLink>
              <RouterLink
                :to="accountDetailLink"
                class="btn btn-sm btn-primary staff-page-primary"
              >
                View client account
              </RouterLink>
              <button
                v-if="canDelete && !row.is_account_primary"
                type="button"
                class="btn btn-sm btn-outline-danger"
                @click="confirmDelete"
              >
                Remove user
              </button>
            </div>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
          <div class="staff-user-tabs border rounded-3 bg-body p-4">
            <h2 class="h6 fw-semibold mb-3">About this login</h2>
            <p class="text-secondary small mb-0">
              This user signs in to the client portal with the role shown above.
              Primary admins are created when the client account is added; additional
              users are typically Customer Service.
            </p>
          </div>
        </div>
      </div>
    </template>

    <ConfirmModal
      :open="deleteOpen"
      title="Remove user?"
      message="This removes the portal login permanently. This cannot be undone."
      confirm-label="Remove"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="runDelete"
    />
  </div>
</template>
