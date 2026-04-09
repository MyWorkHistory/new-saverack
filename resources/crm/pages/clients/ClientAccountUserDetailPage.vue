<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  accountId: { type: String, required: true },
  userId: { type: String, required: true },
});

const loading = ref(true);
const errorMsg = ref("");
const row = ref(null);
const historyItems = ref([]);

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

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

function formatRelativeTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  const diff = Date.now() - d.getTime();
  const sec = Math.floor(diff / 1000);
  if (sec < 45) return "Just now";
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min} min ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr} hr ago`;
  const day = Math.floor(hr / 24);
  if (day < 7) return `${day} day${day === 1 ? "" : "s"} ago`;
  try {
    return formatDateTimeUs(iso);
  } catch {
    return iso;
  }
}

function timelineHeading(r) {
  const t = (r.body || r.line || "Activity").trim();
  if (t.length <= 72) return t;
  return `${t.slice(0, 69)}…`;
}

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

async function load() {
  loading.value = true;
  errorMsg.value = "";
  row.value = null;
  historyItems.value = [];
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/account-users/${props.userId}`,
    );
    row.value = data;
    await loadHistory();
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

      <section
        class="staff-user-timeline-card mt-3"
        aria-labelledby="portal-user-activity-heading"
      >
        <h2
          id="portal-user-activity-heading"
          class="staff-user-timeline-card__title mb-3"
        >
          User activity
        </h2>
        <div v-if="timelinePreview.length" class="staff-user-timeline">
          <div
            v-for="(item, idx) in timelinePreview"
            :key="item.id"
            class="staff-user-timeline__item"
          >
            <img
              v-if="timelineActorAvatarUrl(item)"
              :src="timelineActorAvatarUrl(item)"
              alt=""
              class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
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
            <div class="staff-user-timeline__row">
              <h3 class="staff-user-timeline__heading">
                {{ timelineHeading(item) }}
              </h3>
              <time
                class="staff-user-timeline__time"
                :datetime="item.created_at"
                >{{ formatRelativeTime(item.created_at) }}</time
              >
            </div>
            <p class="staff-user-timeline__body">
              {{ item.body || item.line }}
            </p>
          </div>
        </div>
        <p v-else class="staff-user-timeline__empty">
          No activity logged yet.
        </p>
      </section>
    </template>

  </div>
</template>
