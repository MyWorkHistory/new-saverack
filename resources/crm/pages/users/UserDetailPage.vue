<script setup>
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import UserEditModal from "../../components/users/UserEditModal.vue";
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
const editOpen = ref(false);
const toast = useToast();
const heroAvatarInput = ref(null);
const activeTab = ref("account");
const historyItems = ref([]);

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateUsers = computed(() => userHasPerm("users.update"));
const showGearMenu = computed(() => userHasPerm("users.view"));
const canManagePermissions = computed(() => crmIsAdmin(crmUser.value));

const loading = ref(true);
const errorMsg = ref("");
const user = ref(null);

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function primaryRoleLabel(u) {
  if (!u?.roles?.length) return "Staff";
  const x = u.roles[0];
  return x?.label || x?.name || "Staff";
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

function timelineHeading(row) {
  const t = (row.body || row.line || "Profile activity").trim();
  if (t.length <= 72) return t;
  return `${t.slice(0, 69)}…`;
}

const usernameFromEmail = computed(() => {
  const e = user.value?.email;
  if (!e || typeof e !== "string") return "—";
  const i = e.indexOf("@");
  return i > 0 ? e.slice(0, i) : e;
});

const rolesCount = computed(() =>
  Array.isArray(user.value?.roles) ? user.value.roles.length : 0,
);

const updatesCount = computed(() => historyItems.value.length);

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

const historyTableRows = computed(() => historyItems.value.slice(0, 15));

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
  document.addEventListener("click", onGearDocClick);
  window.addEventListener("resize", onGearWindowResize);
});
onUnmounted(() => {
  document.removeEventListener("click", onGearDocClick);
  window.removeEventListener("resize", onGearWindowResize);
});

function wantsEditQuery(q) {
  if (q === "1" || q === "true") return true;
  if (Array.isArray(q)) {
    return q[0] === "1" || q[0] === "true";
  }
  return false;
}

watch(
  [() => route.query.edit, canUpdateUsers, () => props.id],
  () => {
    if (!wantsEditQuery(route.query.edit)) return;
    if (!canUpdateUsers.value) {
      router.replace({ path: `/staff/${props.id}`, query: {} });
      return;
    }
    editOpen.value = true;
    router.replace({ path: `/staff/${props.id}`, query: {} });
  },
  { immediate: true },
);

const profile = computed(() =>
  user.value?.profile && typeof user.value.profile === "object"
    ? user.value.profile
    : {},
);

const gearMenuOpen = ref(false);
const gearMenuRect = ref({ top: 0, left: 0 });

function placeGearMenu(buttonEl) {
  const MENU_W = 208;
  const MENU_H = 168;
  const r = buttonEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  gearMenuRect.value = { top, left };
}

function closeGearMenu() {
  gearMenuOpen.value = false;
}

function toggleGearMenu(e) {
  e.stopPropagation();
  if (gearMenuOpen.value) {
    closeGearMenu();
    return;
  }
  gearMenuOpen.value = true;
  const btn = e.currentTarget;
  if (btn instanceof HTMLElement) {
    placeGearMenu(btn);
  }
}

function openPermissionsInNewTab() {
  if (!crmIsAdmin(crmUser.value)) return;
  const href = router.resolve({
    name: "staff-permissions",
    params: { id: props.id },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
  closeGearMenu();
}

function openHistoryInNewTab() {
  const href = router.resolve({
    name: "staff-history",
    params: { id: props.id },
  }).href;
  window.open(href, "_blank", "noopener,noreferrer");
  closeGearMenu();
}

function onGearDocClick(e) {
  if (!e.target.closest("[data-page-gear]")) {
    gearMenuOpen.value = false;
  }
}

function onGearWindowResize() {
  if (gearMenuOpen.value) {
    closeGearMenu();
  }
}

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

const tabs = [
  { id: "account", label: "Account" },
  { id: "security", label: "Security" },
  { id: "billing", label: "Billing & Plans" },
  { id: "notifications", label: "Notifications" },
  { id: "connections", label: "Connections" },
];
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
      <div
        v-if="showGearMenu && !loading && !errorMsg"
        class="position-relative flex-shrink-0"
        data-page-gear
      >
        <button
          type="button"
          class="staff-user-gear"
          :aria-expanded="gearMenuOpen"
          aria-haspopup="true"
          aria-label="Page Actions"
          @click="toggleGearMenu"
        >
          <svg
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.224 2.3c.307.575.21 1.278-.234 1.733l-.793.792c-.39.39-.601.918-.601 1.467v.224c0 .99.66 1.86 1.617 2.12l1.218.304c.517.129.88.596.88 1.114v2.593c0 .55-.398 1.02-.94 1.11l-1.281.213a1.125 1.125 0 01-.87.645l-.135.045a1.125 1.125 0 00-.53.315l-.792.793a1.125 1.125 0 01-1.733-.234l-1.224-2.3a1.125 1.125 0 00-.49-.37l-.286-.107a1.125 1.125 0 01-.633-1.326l.302-.774a1.125 1.125 0 00-.216-.883l-.792-.792a1.125 1.125 0 00-.883-.216l-.774.302a1.125 1.125 0 01-1.326-.633l-.107-.286a1.125 1.125 0 00-.37-.49l-2.3-1.224a1.125 1.125 0 01-.234-1.733l.793-.792c.196-.324.257-.72.124-1.075l-.456-1.217a1.125 1.125 0 01.49-1.37l2.3-1.224c.162-.086.312-.2.444-.324L9.594 3.94zM12 15a3 3 0 100-6 3 3 0 000 6z"
            />
          </svg>
        </button>
      </div>
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
      <!-- #1 Profile + tabs -->
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
            <div class="staff-user-profile__role-pill">
              <span class="badge rounded-pill bg-body-secondary text-body-secondary px-3 py-2">
                {{ primaryRoleLabel(user) }}
              </span>
            </div>
            <div class="staff-user-profile__stats">
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H7v-4h4v4zm0-6H7V7h4v4zm6 6h-4v-4h4v4zm0-6h-4V7h4v4z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ rolesCount }}
                </div>
                <div class="staff-user-profile__stat-lbl">Roles</div>
              </div>
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M20 6h-2.18c.11-.31.18-.65.18-1a2 2 0 00-4 0c0 .35.07.69.18 1H5c-1.11 0-1.99.89-1.99 2L3 19c0 1.11.89 2 2 2h14c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-3c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ updatesCount }}
                </div>
                <div class="staff-user-profile__stat-lbl">Updates</div>
              </div>
            </div>
            <h3 class="staff-user-profile__details-title">Details</h3>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Username</dt>
                <dd class="staff-user-profile__dd">{{ usernameFromEmail }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd">{{ display(user.email) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Status</dt>
                <dd class="staff-user-profile__dd text-capitalize">
                  <span :class="statusBadgeClass(user.status)">{{ user.status }}</span>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Role</dt>
                <dd class="staff-user-profile__dd">
                  <template v-if="!user.roles?.length">—</template>
                  <div v-else class="d-flex flex-column gap-2">
                    <div
                      v-for="r in user.roles"
                      :key="r.id"
                      class="d-flex align-items-center gap-2 min-w-0"
                    >
                      <StaffRoleIcon :role="r" />
                      <span class="text-break">{{ r.label || r.name }}</span>
                    </div>
                  </div>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Tax id</dt>
                <dd class="staff-user-profile__dd">{{ display(profile.tax_id) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Contact</dt>
                <dd class="staff-user-profile__dd">{{ display(profile.phone) }}</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Languages</dt>
                <dd class="staff-user-profile__dd">—</dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Country</dt>
                <dd class="staff-user-profile__dd">{{ display(profile.region) }}</dd>
              </div>
            </dl>
            <div class="staff-user-profile__actions">
              <button
                v-if="canUpdateUsers"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="editOpen = true"
              >
                Edit
              </button>
              <button
                type="button"
                class="btn btn-sm staff-user-profile__btn-suspend"
                disabled
                title="Not available in this version"
              >
                Suspend
              </button>
            </div>
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
              @click="activeTab = t.id"
            >
              <svg
                v-if="t.id === 'account'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                />
              </svg>
              <svg
                v-else-if="t.id === 'security'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                />
              </svg>
              <svg
                v-else-if="t.id === 'billing'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"
                />
              </svg>
              <svg
                v-else-if="t.id === 'notifications'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.75A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3.75 3.75 0 11-5.714 0"
                />
              </svg>
              <svg
                v-else
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"
                />
              </svg>
              {{ t.label }}
            </button>
          </div>
          <div
            class="staff-user-tab-panel"
            role="tabpanel"
            :aria-label="tabs.find((x) => x.id === activeTab)?.label"
          >
            <template v-if="activeTab === 'account'">
              <h3 class="staff-user-section-title">Personal Information</h3>
              <div class="row g-3">
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                      Full Name
                    </dt>
                    <dd class="mb-3 fw-semibold text-body">
                      {{ display(user.name) }}
                    </dd>
                    <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                      Login Email
                    </dt>
                    <dd class="mb-0 fw-semibold text-body text-break">
                      {{ display(user.email) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                      Phone
                    </dt>
                    <dd class="mb-3 fw-semibold text-body">
                      {{ display(profile.phone) }}
                    </dd>
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
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                      Bio
                    </dt>
                    <dd class="mb-0 fw-semibold text-body text-break" style="white-space: pre-wrap">
                      {{ display(profile.bio) }}
                    </dd>
                  </dl>
                </div>
              </div>
              <h3 class="staff-user-section-title">Address</h3>
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
              <h3 class="staff-user-section-title">Employment</h3>
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
            </template>
            <p v-else class="staff-user-tab-panel__placeholder mb-0">
              {{ tabs.find((x) => x.id === activeTab)?.label }} settings will be
              available in a future update.
            </p>
          </div>
        </div>
      </div>

      <!-- #3 Activity timeline -->
      <section class="staff-user-timeline-card" aria-labelledby="staff-activity-timeline-heading">
        <h2 id="staff-activity-timeline-heading" class="staff-user-timeline-card__title">
          User Activity Timeline
        </h2>
        <div v-if="timelinePreview.length" class="staff-user-timeline">
          <div
            v-for="(row, idx) in timelinePreview"
            :key="row.id"
            class="staff-user-timeline__item"
          >
            <span
              class="staff-user-timeline__dot"
              :class="`staff-user-timeline__dot--${idx % 3}`"
              aria-hidden="true"
            />
            <div class="staff-user-timeline__row">
              <h3 class="staff-user-timeline__heading">
                {{ timelineHeading(row) }}
              </h3>
              <time
                class="staff-user-timeline__time"
                :datetime="row.created_at"
              >{{ formatRelativeTime(row.created_at) }}</time>
            </div>
            <p class="staff-user-timeline__body">
              {{ row.body || row.line }}
            </p>
          </div>
        </div>
        <p v-else class="staff-user-timeline__empty">
          No profile activity yet.
        </p>
      </section>

      <!-- #2 Change history table -->
      <section class="staff-user-history-card" aria-labelledby="staff-history-table-heading">
        <div class="staff-user-history-card__head">
          <h2 id="staff-history-table-heading" class="staff-user-history-card__title">
            Change History
          </h2>
          <RouterLink
            :to="{ name: 'staff-history', params: { id: props.id } }"
            class="btn btn-sm btn-outline-secondary"
          >
            View all
          </RouterLink>
        </div>
        <div v-if="historyTableRows.length" class="staff-user-history-table-wrap">
          <table class="staff-user-history-table table table-hover mb-0">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">Status</th>
                <th scope="col">Activity</th>
                <th scope="col">Date</th>
                <th scope="col" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in historyTableRows" :key="row.id">
                <td class="staff-user-history-table__id">#{{ row.id }}</td>
                <td>
                  <span
                    class="rounded-circle d-inline-flex align-items-center justify-content-center bg-success-subtle text-success"
                    style="width: 2rem; height: 2rem"
                    aria-label="Update"
                  >
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                    </svg>
                  </span>
                </td>
                <td class="small text-body">
                  {{ row.body || row.line }}
                </td>
                <td class="small text-secondary text-nowrap">
                  {{ formatDateTimeUs(row.created_at) }}
                </td>
                <td class="text-end">
                  <RouterLink
                    :to="{ name: 'staff-history', params: { id: props.id } }"
                    class="btn btn-link btn-sm text-decoration-none p-0"
                  >
                    View
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="staff-user-history-table__empty">
          No history entries yet.
        </div>
      </section>
    </template>

    <UserEditModal
      v-model:open="editOpen"
      :user-id="String(props.id)"
      @saved="loadPageData"
    />

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="transform opacity-0 scale-95"
        enter-to-class="transform opacity-100 scale-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="transform opacity-100 scale-100"
        leave-to-class="transform opacity-0 scale-95"
      >
        <div
          v-if="gearMenuOpen"
          data-page-gear
          class="position-fixed rounded-3 border bg-body shadow py-1 staff-row-menu"
          style="z-index: 300; width: 13rem"
          role="menu"
          :style="{
            top: `${gearMenuRect.top}px`,
            left: `${gearMenuRect.left}px`,
          }"
          @click.stop
        >
          <button
            v-if="canManagePermissions"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openPermissionsInNewTab"
          >
            Permissions
          </button>
          <button
            type="button"
            class="staff-row-menu__item"
            :class="{ 'border-top': canManagePermissions }"
            role="menuitem"
            @click="openHistoryInNewTab"
          >
            History
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
