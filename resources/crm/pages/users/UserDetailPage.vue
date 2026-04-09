<script setup>
import {
  computed,
  inject,
  onMounted,
  ref,
  watch,
} from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import UserEditSectionModal from "../../components/users/UserEditSectionModal.vue";
import UserPermissionsPanel from "../../components/users/UserPermissionsPanel.vue";
import StaffRoleIcon from "../../components/users/StaffRoleIcon.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import {
  formatBirthdayUs,
  formatDateTimeUs,
  formatDateUs,
} from "../../utils/formatUserDates";
import { useToast } from "../../composables/useToast";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  id: { type: String, required: true },
});

const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const heroAvatarInput = ref(null);
const historyItems = ref([]);

const TAB_ACCOUNT = "account";
const TAB_PERMISSIONS = "permissions";

const tabs = [
  { id: TAB_ACCOUNT, label: "Account" },
  { id: TAB_PERMISSIONS, label: "Permissions" },
];

const activeTab = ref(TAB_ACCOUNT);

function tabFromRouteQuery(q) {
  const t = String(q || "").toLowerCase();
  if (t === TAB_PERMISSIONS) return TAB_PERMISSIONS;
  return TAB_ACCOUNT;
}

function syncTabFromRoute() {
  activeTab.value = tabFromRouteQuery(route.query.tab);
}

function setActiveTab(tabId) {
  activeTab.value = tabId;
  const cur = String(route.query.tab || "").toLowerCase();
  if (cur !== tabId) {
    router.replace({ query: { ...route.query, tab: tabId } });
  }
}

watch(
  () => route.query.tab,
  () => {
    const next = tabFromRouteQuery(route.query.tab);
    if (activeTab.value !== next) activeTab.value = next;
  },
);

const sectionModalOpen = ref(false);
const sectionModalKeys = ref([]);
const sectionModalTitle = ref("");
const sectionModalSubtitle = ref("");

function openSectionModal(keys, title, subtitle = "") {
  sectionModalKeys.value = keys;
  sectionModalTitle.value = title;
  sectionModalSubtitle.value = subtitle;
  sectionModalOpen.value = true;
}

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateUsers = computed(() => userHasPerm("users.update"));
const canManagePermissions = computed(() => crmIsAdmin(crmUser.value));

const loading = ref(true);
const errorMsg = ref("");
const user = ref(null);

/** Static until product defines XP; expose as computed for easy wiring later. */
const xpPointsDisplay = computed(() => 0);

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
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
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

function timelineActorAvatarUrl(row) {
  const raw = row?.actor_avatar_url;
  if (!raw) return "";
  return resolvePublicUrl(raw) || raw;
}

function avatarClassForTimelineActor(label) {
  let h = 0;
  const s = label || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

async function loadPageData() {
  loading.value = true;
  errorMsg.value = "";
  user.value = null;
  historyItems.value = [];
  try {
    const [userRes, histRes] = await Promise.all([
      api.get(`/users/${props.id}`),
      api.get(`/users/${props.id}/history`),
    ]);
    user.value = userRes.data;
    const list = histRes.data?.items;
    historyItems.value = Array.isArray(list) ? list : [];
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You Don't Have Access To This Profile.";
    } else if (st === 404) {
      errorMsg.value = "User Not Found.";
    } else {
      errorMsg.value = "Could Not Load User.";
    }
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  loadPageData();
  syncTabFromRoute();
});

function openHeroAvatarPicker() {
  heroAvatarInput.value?.click();
}

watch(
  () => user.value?.name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | Staff: ${name}`,
        description: `Profile For ${name}.`,
      });
    }
  },
);

async function onHeroAvatarChange(e) {
  const input = e.target;
  const file = input.files?.[0];
  input.value = "";
  if (!file || !canUpdateUsers.value) return;
  const fd = new FormData();
  fd.append("avatar", file);
  try {
    await api.post(`/users/${props.id}/avatar`, fd);
    await loadPageData();
    toast.success("Photo updated.");
  } catch (err) {
    toast.errorFrom(err, "Could not upload photo.");
  }
}

const profile = computed(() =>
  user.value?.profile && typeof user.value.profile === "object"
    ? user.value.profile
    : {},
);

function onPermissionsSaved() {
  loadPageData();
}
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/dashboard">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/staff">Staff</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Profile</span>
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-center justify-content-between gap-2"
    >
      <h1 class="staff-user-view__title">User Profile</h1>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading Profile…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">
        {{ errorMsg }}
      </p>
      <RouterLink to="/staff" class="small">Back To Directory</RouterLink>
    </template>

    <template v-else-if="user">
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
                v-if="canUpdateUsers"
                type="button"
                class="staff-user-profile__avatar-btn rounded focus-ring"
                title="Change photo"
                @click="openHeroAvatarPicker"
              >
                <img
                  v-if="profile.avatar_url"
                  :src="resolvePublicUrl(profile.avatar_url)"
                  alt=""
                  class="staff-user-profile__avatar"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(user.email)"
                >
                  {{ initials(user.name) }}
                </span>
              </button>
              <div v-else>
                <img
                  v-if="profile.avatar_url"
                  :src="resolvePublicUrl(profile.avatar_url)"
                  alt=""
                  class="staff-user-profile__avatar"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(user.email)"
                >
                  {{ initials(user.name) }}
                </span>
              </div>
            </div>
            <h2 class="staff-user-profile__name">
              {{ user.name }}
            </h2>
            <div class="staff-user-profile__role-pill w-100">
              <span
                class="text-capitalize"
                :class="statusBadgeClass(user.status)"
              >{{ user.status }}</span>
            </div>
            <div class="staff-user-profile__stats staff-user-profile__stats--single">
              <div class="staff-user-profile__stat staff-user-profile__stat--wide">
                <div class="staff-user-profile__stat-icon text-warning" aria-hidden="true">
                  <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zm-14 3v-1h14v1c0 1.3-.84 2.42-2 2.83V10h-1v1.17C15.84 10.42 15 9.3 15 8V6H9v2c0 1.3-.84 2.42-2 2.83V10H6v-1.17C7.16 10.42 8 9.3 8 8z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ xpPointsDisplay }}
                </div>
                <div class="staff-user-profile__stat-lbl">XP Points</div>
              </div>
            </div>
            <div
              class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"
            >
              <h3 class="staff-user-profile__details-title mb-0">Details</h3>
              <button
                v-if="canUpdateUsers"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="
                  openSectionModal(
                    ['identity', 'access', 'bio'],
                    'Login & access',
                    'Email, password, status, roles, and bio.',
                  )
                "
              >
                Edit
              </button>
            </div>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd text-break">
                  {{ display(user.email) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Role</dt>
                <dd class="staff-user-profile__dd">
                  <template v-if="!user.roles?.length">—</template>
                  <div v-else class="d-flex flex-column gap-2 align-items-end">
                    <div
                      v-for="r in user.roles"
                      :key="r.id"
                      class="d-flex align-items-center gap-2 min-w-0 justify-content-end"
                    >
                      <StaffRoleIcon :role="r" />
                      <span class="text-break">{{ r.label || r.name }}</span>
                    </div>
                  </div>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Bio</dt>
                <dd
                  class="staff-user-profile__dd"
                  style="white-space: pre-wrap"
                >
                  {{ display(profile.bio) }}
                </dd>
              </div>
            </dl>
          </aside>
        </div>
        <div class="col-12 col-xl-8">
          <div class="staff-user-tabs" role="tablist">
            <button
              v-for="t in tabs"
              :key="t.id"
              type="button"
              class="staff-user-tab"
              :class="{ 'staff-user-tab--active': activeTab === t.id }"
              role="tab"
              :aria-selected="activeTab === t.id"
              @click="setActiveTab(t.id)"
            >
              {{ t.label }}
            </button>
          </div>
          <div
            class="staff-user-tab-panel"
            role="tabpanel"
            :aria-label="tabs.find((x) => x.id === activeTab)?.label"
          >
            <template v-if="activeTab === TAB_ACCOUNT">
              <div class="staff-surface p-3 p-md-4 mb-4">
                <div
                  class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
                >
                  <h3 class="staff-user-section-title mb-0">
                    Personal Information
                  </h3>
                  <button
                    v-if="canUpdateUsers"
                    type="button"
                    class="btn btn-sm btn-primary staff-page-primary"
                    @click="openSectionModal(['displayName', 'contact'])"
                  >
                    Edit
                  </button>
                </div>
                <div class="row g-3">
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Full Name
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(user.name) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Phone
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.phone) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Personal Email
                      </dt>
                      <dd class="mb-0 fw-semibold text-body text-break">
                        {{ display(profile.personal_email) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-6">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Birthday
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ formatBirthdayUs(profile.birthday) }}
                      </dd>
                    </dl>
                  </div>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4 mb-4">
                <div
                  class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
                >
                  <h3 class="staff-user-section-title mb-0">Address</h3>
                  <button
                    v-if="canUpdateUsers"
                    type="button"
                    class="btn btn-sm btn-primary staff-page-primary"
                    @click="
                      openSectionModal(
                        ['address'],
                        'Address',
                        'Street, city, and country.',
                      )
                    "
                  >
                    Edit
                  </button>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Street
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.address) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        City
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.city) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        State / ZIP
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.state) }}
                        <template v-if="profile.zip"> {{ display(profile.zip) }}</template>
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Country
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.region) }}
                      </dd>
                    </dl>
                  </div>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4">
                <div
                  class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
                >
                  <h3 class="staff-user-section-title mb-0">Employment</h3>
                  <button
                    v-if="canUpdateUsers"
                    type="button"
                    class="btn btn-sm btn-primary staff-page-primary"
                    @click="
                      openSectionModal(
                        ['employment'],
                        'Employment',
                        'Role, dates, and employment type.',
                      )
                    "
                  >
                    Edit
                  </button>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Employment Type
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.employee_type) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Position
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ display(profile.job_position) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Hire Date
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ formatDateUs(profile.hire_date) }}
                      </dd>
                    </dl>
                  </div>
                  <div class="col-md-4">
                    <dl class="mb-0 small">
                      <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                        Termination Date
                      </dt>
                      <dd class="mb-0 fw-semibold text-body">
                        {{ formatDateUs(profile.terminate_date) }}
                      </dd>
                    </dl>
                  </div>
                </div>
              </div>
            </template>

            <template v-else-if="activeTab === TAB_PERMISSIONS">
              <template v-if="canManagePermissions">
                <UserPermissionsPanel
                  :user-id="id"
                  embedded
                  @saved="onPermissionsSaved"
                />
              </template>
              <p v-else class="staff-user-tab-panel__placeholder mb-0">
                You don’t have access to manage permissions for this user.
              </p>
            </template>
          </div>
        </div>
      </div>

      <section class="staff-user-timeline-card" aria-labelledby="staff-activity-timeline-heading">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <h2 id="staff-activity-timeline-heading" class="staff-user-timeline-card__title mb-0">
            User Activity Timeline
          </h2>
          <RouterLink
            :to="{ name: 'staff-history', params: { id: props.id } }"
            class="btn btn-sm btn-primary staff-page-primary"
          >
            View all
          </RouterLink>
        </div>
        <div v-if="timelinePreview.length" class="staff-user-timeline">
          <div
            v-for="(row, idx) in timelinePreview"
            :key="row.id"
            class="staff-user-timeline__item"
          >
            <img
              v-if="timelineActorAvatarUrl(row)"
              :src="timelineActorAvatarUrl(row)"
              alt=""
              class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
              width="44"
              height="44"
            />
            <span
              v-else
              class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
              style="width: 44px; height: 44px; font-size: 0.75rem"
              :class="avatarClassForTimelineActor(row.actor_name)"
              :title="row.actor_name || 'User'"
              aria-hidden="true"
              >{{ row.actor_initials || "?" }}</span
            >
            <div class="staff-user-timeline__content min-w-0 flex-grow-1">
              <div class="staff-user-timeline__row">
                <h3 class="staff-user-timeline__heading">
                  {{ row.actor_name || "System" }}
                </h3>
                <time
                  class="staff-user-timeline__time"
                  :datetime="row.created_at"
                >{{ formatDateTimeUs(row.created_at) }}</time>
              </div>
              <p class="staff-user-timeline__body">
                {{ row.body || row.line }}
              </p>
            </div>
          </div>
        </div>
        <p v-else class="staff-user-timeline__empty">
          No profile activity yet.
        </p>
      </section>
    </template>

    <UserEditSectionModal
      v-model:open="sectionModalOpen"
      :user-id="String(props.id)"
      :section-keys="sectionModalKeys"
      :title="sectionModalTitle"
      :subtitle="sectionModalSubtitle"
      @saved="loadPageData"
    />

  </div>
</template>
