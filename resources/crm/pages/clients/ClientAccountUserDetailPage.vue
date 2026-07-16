<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmStatusUpdateModal from "../../components/common/CrmStatusUpdateModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ClientAccountUserEditModal from "../../components/clients/ClientAccountUserEditModal.vue";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import { crmIsAdmin } from "../../utils/crmUser";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  accountId: { type: String, required: true },
  userId: { type: String, required: true },
});

const loading = ref(true);
const errorMsg = ref("");
const row = ref(null);
const historyItems = ref([]);
const notes = ref([]);
const noteBody = ref("");
const noteSubmitting = ref(false);
const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const statusModalOpen = ref(false);
const statusForm = ref("active");
const statusSaving = ref(false);
const userStatuses = ["active", "inactive"];
const editModalOpen = ref(false);
const editModalMode = ref("personal");
const heroAvatarInput = ref(null);
const heroAvatarLoadFailed = ref(false);

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("client_users.update"));

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
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-light text-secondary";
}

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function avatarUrl() {
  const raw = row.value?.avatar_url;
  if (!raw) return "";
  return resolvePublicUrl(raw) || raw;
}

function showHeroAvatarImage() {
  return Boolean(avatarUrl()) && !heroAvatarLoadFailed.value;
}

function markHeroAvatarLoadFailed() {
  heroAvatarLoadFailed.value = true;
}

function openHeroAvatarPicker() {
  if (!canUpdate.value) return;
  heroAvatarInput.value?.click();
}

async function onHeroAvatarChange(e) {
  const input = e.target;
  const file = input.files?.[0];
  input.value = "";
  if (!file || !canUpdate.value) return;
  const fd = new FormData();
  fd.append("avatar", file);
  try {
    await api.post(
      `/client-accounts/${props.accountId}/account-users/${props.userId}/avatar`,
      fd,
    );
    await load();
    toast.success("Photo updated.");
  } catch (err) {
    toast.errorFrom(err, "Could not upload photo.");
  }
}

function openStatusModal() {
  if (!row.value || !canUpdate.value) return;
  statusForm.value = row.value.status || "active";
  statusModalOpen.value = true;
}

async function saveStatusFromModal() {
  if (!row.value || !canUpdate.value) return;
  const next = statusForm.value;
  if (next === row.value.status) {
    statusModalOpen.value = false;
    return;
  }
  statusSaving.value = true;
  try {
    await api.patch(`/client-accounts/${props.accountId}/account-users/${props.userId}`, {
      status: next,
    });
    toast.success("Status updated.");
    await load();
    statusModalOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusSaving.value = false;
  }
}

function onEditModalSaved() {
  toast.success("User updated.");
  load();
}

function openAccessEdit() {
  editModalMode.value = "access";
  editModalOpen.value = true;
}

function openPersonalEdit() {
  editModalMode.value = "personal";
  editModalOpen.value = true;
}

const accountDetailLink = computed(() => ({
  name: "client-account-detail",
  params: { id: props.accountId },
}));

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

function timelineActorAvatarUrl(r) {
  const raw = r?.actor_avatar_url;
  if (!raw) return "";
  return resolvePublicUrl(raw) || raw;
}

function avatarClassForTimelineActor(label) {
  let h = 0;
  const s = label || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

async function loadHistory() {
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/account-users/${props.userId}/history`,
    );
    const list = data?.items;
    historyItems.value = Array.isArray(list) ? list : [];
  } catch {
    historyItems.value = [];
  }
}

async function loadNotes() {
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/account-users/${props.userId}/notes`,
    );
    notes.value = Array.isArray(data?.notes) ? data.notes : [];
  } catch {
    notes.value = [];
  }
}

async function submitNote() {
  if (!canUpdate.value) return;
  const body = noteBody.value.trim();
  if (!body) {
    toast.warning("Enter a note before adding.");
    return;
  }
  noteSubmitting.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/account-users/${props.userId}/notes`,
      { body },
    );
    notes.value = [data, ...notes.value];
    noteBody.value = "";
    toast.success("Note added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add note.");
  } finally {
    noteSubmitting.value = false;
  }
}

async function deleteNote(note) {
  if (!canUpdate.value || !note?.id) return;
  try {
    await api.delete(
      `/client-accounts/${props.accountId}/account-users/${props.userId}/notes/${note.id}`,
    );
    notes.value = notes.value.filter((n) => n.id !== note.id);
    toast.success("Note deleted.");
  } catch (e) {
    toast.errorFrom(e, "Could not delete note.");
  }
}

async function load() {
  loading.value = true;
  errorMsg.value = "";
  row.value = null;
  historyItems.value = [];
  notes.value = [];
  heroAvatarLoadFailed.value = false;
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/account-users/${props.userId}`,
    );
    row.value = data;
    await Promise.all([loadHistory(), loadNotes()]);
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
      <RouterLink to="/admin/home">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink :to="{ name: 'client-users' }">Client users</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Profile</span>
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-center justify-content-between gap-2"
    >
      <h1 class="staff-user-view__title">Client User</h1>
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
            <input
              ref="heroAvatarInput"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              class="d-none"
              @change="onHeroAvatarChange"
            />
            <div class="staff-user-profile__avatar-wrap">
              <button
                v-if="canUpdate"
                type="button"
                class="staff-user-profile__avatar-btn rounded focus-ring"
                title="Change photo"
                @click="openHeroAvatarPicker"
              >
                <img
                  v-if="showHeroAvatarImage()"
                  :src="avatarUrl()"
                  alt=""
                  class="staff-user-profile__avatar"
                  @error="markHeroAvatarLoadFailed"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(row.email)"
                >
                  {{ initials(row.name) }}
                </span>
              </button>
              <div v-else>
                <img
                  v-if="showHeroAvatarImage()"
                  :src="avatarUrl()"
                  alt=""
                  class="staff-user-profile__avatar"
                  @error="markHeroAvatarLoadFailed"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(row.email)"
                >
                  {{ initials(row.name) }}
                </span>
              </div>
            </div>
            <h2 class="staff-user-profile__name">
              {{ row.name }}
            </h2>
            <div class="staff-user-profile__role-pill w-100">
              <button
                v-if="canUpdate"
                type="button"
                class="staff-status-badge text-capitalize"
                :class="statusBadgeClass(row.status)"
                title="Change status"
                @click="openStatusModal"
              >
                {{ row.status }}
              </button>
              <span
                v-else
                class="staff-status-badge text-capitalize"
                :class="statusBadgeClass(row.status)"
                >{{ row.status }}</span
              >
            </div>
            <div class="staff-user-profile__stats">
              <RouterLink
                :to="accountDetailLink"
                class="staff-user-profile__stat text-decoration-none text-reset"
                title="View client account"
              >
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
              </RouterLink>
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

            <div
              class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"
            >
              <h3 class="staff-user-profile__details-title mb-0">Details</h3>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="openAccessEdit"
              >
                Edit
              </button>
            </div>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Account type</dt>
                <dd class="staff-user-profile__dd">
                  {{
                    display(row.account_user_role_label || row.account_user_role)
                  }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Created</dt>
                <dd class="staff-user-profile__dd">{{ formatDateUs(row.created_at) }}</dd>
              </div>
            </dl>

            <section
              class="staff-user-timeline-card staff-user-timeline-card--aside mt-3"
              aria-labelledby="portal-user-activity-heading"
            >
              <h2
                id="portal-user-activity-heading"
                class="staff-user-timeline-card__title mb-3"
              >
                User Activity Timeline
              </h2>
              <div v-if="timelinePreview.length" class="staff-user-timeline">
                <div
                  v-for="item in timelinePreview"
                  :key="item.id"
                  class="staff-user-timeline__item"
                >
                  <img
                    v-if="timelineActorAvatarUrl(item)"
                    :src="timelineActorAvatarUrl(item)"
                    alt=""
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
                    width="36"
                    height="36"
                  />
                  <span
                    v-else
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
                    style="width: 36px; height: 36px; font-size: 0.6875rem"
                    :class="avatarClassForTimelineActor(item.actor_name)"
                    :title="item.actor_name || 'User'"
                    aria-hidden="true"
                    >{{ item.actor_initials || "?" }}</span
                  >
                  <div class="staff-user-timeline__content min-w-0 flex-grow-1">
                    <div class="staff-user-timeline__row">
                      <h3 class="staff-user-timeline__heading">
                        {{ item.actor_name || "System" }}
                      </h3>
                      <time
                        class="staff-user-timeline__time"
                        :datetime="item.created_at"
                        >{{ formatDateTimeUs(item.created_at) }}</time
                      >
                    </div>
                    <p class="staff-user-timeline__body">
                      {{ item.body || item.line }}
                    </p>
                  </div>
                </div>
              </div>
              <p v-else class="staff-user-timeline__empty mb-0">
                No activity logged yet.
              </p>
            </section>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
          <div class="staff-surface p-3 p-md-4 mb-4">
            <div
              class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
            >
              <h3 class="staff-user-section-title mb-0">Personal Information</h3>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="openPersonalEdit"
              >
                Edit
              </button>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <dl class="mb-0 small">
                  <dt
                    class="text-secondary text-uppercase fw-semibold mb-1"
                    style="font-size: 0.65rem"
                  >
                    Full Name
                  </dt>
                  <dd class="mb-0 fw-semibold text-body">
                    {{ display(row.name) }}
                  </dd>
                </dl>
              </div>
              <div class="col-md-6">
                <dl class="mb-0 small">
                  <dt
                    class="text-secondary text-uppercase fw-semibold mb-1"
                    style="font-size: 0.65rem"
                  >
                    Email
                  </dt>
                  <dd class="mb-0 fw-semibold text-body text-break">
                    {{ display(row.email) }}
                  </dd>
                </dl>
              </div>
              <div class="col-md-6">
                <dl class="mb-0 small">
                  <dt
                    class="text-secondary text-uppercase fw-semibold mb-1"
                    style="font-size: 0.65rem"
                  >
                    Phone
                  </dt>
                  <dd class="mb-0 fw-semibold text-body">
                    {{ display(row.phone) }}
                  </dd>
                </dl>
              </div>
            </div>
          </div>

          <div class="staff-surface p-3 p-md-4">
            <div
              class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
            >
              <h3 class="staff-user-section-title mb-0">Notes</h3>
            </div>

            <ul v-if="notes.length" class="list-unstyled mb-0">
              <li
                v-for="note in notes"
                :key="note.id"
                class="border-bottom py-3"
              >
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                  <div class="min-w-0 flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 small text-secondary mb-1">
                      <span class="fw-semibold text-body">{{
                        note.author_name || "Staff"
                      }}</span>
                      <time v-if="note.created_at" :datetime="note.created_at">{{
                        formatDateTimeUs(note.created_at)
                      }}</time>
                    </div>
                    <p class="mb-0 small text-body" style="white-space: pre-wrap">
                      {{ note.body }}
                    </p>
                  </div>
                  <button
                    v-if="canUpdate"
                    type="button"
                    class="btn btn-link btn-sm text-danger text-decoration-none p-0 flex-shrink-0"
                    @click="deleteNote(note)"
                  >
                    Delete
                  </button>
                </div>
              </li>
            </ul>
            <p v-else class="text-secondary small border-bottom pb-3 mb-0">
              No notes yet.
            </p>

            <div v-if="canUpdate" class="pt-3">
              <label class="form-label small text-secondary" for="portal-user-note"
                >Add Note</label
              >
              <textarea
                id="portal-user-note"
                v-model="noteBody"
                rows="3"
                class="form-control"
                placeholder="Write an internal note…"
              />
              <div class="mt-3">
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary"
                  :disabled="noteSubmitting"
                  @click="submitNote"
                >
                  {{ noteSubmitting ? "Adding…" : "Add Note" }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <CrmStatusUpdateModal
        v-model:open="statusModalOpen"
        v-model:status="statusForm"
        title="Portal user status"
        subtitle="Choose the login status for this portal user."
        :statuses="userStatuses"
        :busy="statusSaving"
        @save="saveStatusFromModal"
      />
    </template>

    <ClientAccountUserEditModal
      v-if="canUpdate"
      v-model:open="editModalOpen"
      :mode="editModalMode"
      :client-account-id="accountId"
      :user-id="userId"
      @saved="onEditModalSaved"
    />
  </div>
</template>
