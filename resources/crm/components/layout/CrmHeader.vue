<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import UserEditModal from "../users/UserEditModal.vue";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(["logout", "refresh-user"]);

const router = useRouter();
const toast = useToast();
const { isMobileOpen, toggleSidebar } = useCrmSidebar();
const markSrc = computed(() => BRAND_MARK_SRC());

const isPortalUser = computed(() => (props.user?.client_account_id ?? 0) > 0);

const staffLookupAccountOptions = computed(() =>
  (staffLookupAccounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);
const portalSearch = ref("");
const portalSearchLoading = ref(false);
const staffSearch = ref("");
const staffSearchLoading = ref(false);
const staffLookupAccountId = ref("");
const staffLookupAccountsLoading = ref(false);
const staffLookupAccounts = ref([]);

const menuOpen = ref(false);
const menuRoot = ref(null);
const editProfileModalOpen = ref(false);

const buildEnvLabel = computed(() =>
  import.meta.env.MODE === "production" ? "Prod" : "Dev",
);

const buildEnvTitle = computed(
  () => `Build: ${import.meta.env.MODE}`,
);

const canManageOwnAccount = computed(
  () => !isPortalUser.value && !!props.user?.id,
);

const isSelfEditModal = computed(() => canManageOwnAccount.value);

function openAccountSettingsModal() {
  editProfileModalOpen.value = true;
  closeMenu();
}

const firstName = computed(() => {
  const n = props.user?.name;
  if (!n || typeof n !== "string") return "";
  return n.trim().split(/\s+/)[0] ?? "";
});

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const avatarPalettes = [
  "bg-info-subtle text-info-emphasis border border-info-subtle",
  "bg-primary-subtle text-primary-emphasis border border-primary-subtle",
  "bg-warning-subtle text-warning-emphasis border border-warning-subtle",
  "bg-success-subtle text-success-emphasis border border-success-subtle",
  "bg-danger-subtle text-danger-emphasis border border-danger-subtle",
];

function avatarClass(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function closeMenu() {
  menuOpen.value = false;
}

function onDocClick(e) {
  if (!menuRoot.value?.contains(e.target)) {
    menuOpen.value = false;
  }
}

function signOut() {
  menuOpen.value = false;
  emit("logout");
}

async function loadStaffLookupAccounts() {
  staffLookupAccountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    staffLookupAccounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (_) {
    staffLookupAccounts.value = [];
  } finally {
    staffLookupAccountsLoading.value = false;
  }
}

async function submitStaffSearch() {
  const q = staffSearch.value.trim();
  if (!q || staffSearchLoading.value) return;
  staffSearchLoading.value = true;
  try {
    const params = { query: q };
    if (Number(staffLookupAccountId.value || 0) > 0) {
      params.client_account_id = Number(staffLookupAccountId.value);
    }
    const { data } = await api.get("/crm/lookup", { params });
    const accountId = data?.client_account_id;
    if (data?.type === "order") {
      const query =
        accountId != null && accountId !== ""
          ? { client_account_id: String(accountId) }
          : {};
      await router.push({
        name: "order-detail",
        params: { shipheroOrderId: String(data.shiphero_order_id) },
        query,
      });
      staffSearch.value = "";
    } else if (data?.type === "sku") {
      const query =
        accountId != null && accountId !== ""
          ? { client_account_id: String(accountId) }
          : {};
      await router.push({
        name: "inventory-detail",
        params: { sku: String(data.sku) },
        query,
      });
      staffSearch.value = "";
    }
  } catch (e) {
    const status = e?.response?.status;
    const msg = e?.response?.data?.message;
    if (status === 404 || status === 422) {
      toast.error(typeof msg === "string" && msg ? msg : "Not found.");
    } else {
      toast.errorFrom(e, "Search failed.");
    }
  } finally {
    staffSearchLoading.value = false;
  }
}

function onStaffSearchKeydown(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    submitStaffSearch();
  }
}

async function submitPortalSearch() {
  const q = portalSearch.value.trim();
  if (!q || portalSearchLoading.value) return;
  portalSearchLoading.value = true;
  try {
    const { data } = await api.get("/portal/lookup", { params: { query: q } });
    if (data?.type === "order") {
      await router.push({
        name: "user-order-detail",
        params: { shipheroOrderId: String(data.shiphero_order_id) },
        query: {
          client_account_id: String(data.client_account_id ?? props.user?.client_account_id ?? ""),
        },
      });
      portalSearch.value = "";
    } else if (data?.type === "sku") {
      await router.push({
        name: "user-inventory-detail",
        params: { sku: String(data.sku) },
      });
      portalSearch.value = "";
    }
  } catch (e) {
    const status = e?.response?.status;
    const msg = e?.response?.data?.message;
    if (status === 404 || status === 422) {
      toast.error(typeof msg === "string" && msg ? msg : "Not found.");
    } else {
      toast.errorFrom(e, "Search failed.");
    }
  } finally {
    portalSearchLoading.value = false;
  }
}

function onPortalSearchKeydown(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    submitPortalSearch();
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  if (!isPortalUser.value) {
    loadStaffLookupAccounts();
  }
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <header class="crm-navbar">
    <div class="vx-navbar-float">
      <div class="vx-navbar__inner">
        <div
          class="d-flex flex-wrap flex-lg-nowrap align-items-center gap-2 w-100"
        >
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
          <button
            type="button"
            class="btn vx-icon-btn d-lg-none flex-shrink-0"
            aria-label="Toggle sidebar"
            @click="toggleSidebar"
          >
            <svg
              v-if="isMobileOpen"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
            <svg
              v-else
              width="20"
              height="16"
              viewBox="0 0 16 12"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z"
                fill="currentColor"
              />
            </svg>
          </button>

          <RouterLink
            v-if="!isPortalUser"
            to="/admin/home"
            class="d-none d-sm-flex d-lg-none align-items-center gap-2 text-decoration-none text-body flex-shrink-0"
          >
            <img
              :src="markSrc"
              alt=""
              width="44"
              height="44"
              class="rounded object-fit-contain"
            />
            <span class="fw-bold text-truncate" style="max-width: 5.5rem"
              >Save Rack</span
            >
          </RouterLink>
        </div>

        <template v-if="isPortalUser">
          <div
            class="crm-portal-navbar-search d-flex align-items-center gap-2 flex-grow-1 min-w-0"
          >
            <div class="vx-search-merge flex-grow-1 min-w-0">
              <div class="input-group">
                <span class="input-group-text border-end-0">
                  <svg
                    width="20"
                    height="20"
                    class="text-secondary opacity-75 flex-shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                    />
                  </svg>
                </span>
                <input
                  v-model="portalSearch"
                  type="search"
                  class="form-control border-start-0"
                  :placeholder="portalSearchLoading ? 'Searching…' : 'Search Exact Order # or SKU'"
                  autocomplete="off"
                  aria-label="Search exact order number or SKU"
                  :disabled="portalSearchLoading"
                  :aria-busy="portalSearchLoading"
                  @keydown="onPortalSearchKeydown"
                />
              </div>
            </div>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn flex-shrink-0 d-inline-flex align-items-center"
              :disabled="portalSearchLoading || !portalSearch.trim()"
              :aria-busy="portalSearchLoading"
              @click="submitPortalSearch"
            >
              <span
                v-if="portalSearchLoading"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              {{ portalSearchLoading ? "Searching…" : "Search" }}
            </button>
          </div>

          <div class="d-flex align-items-center flex-shrink-0 ms-auto">
            <div ref="menuRoot" class="position-relative">
              <button
                type="button"
                class="btn btn-link text-decoration-none text-body d-flex align-items-center rounded-3 py-1 ps-1 pe-1 border-0"
                :aria-expanded="menuOpen"
                aria-haspopup="true"
                @click.stop="menuOpen = !menuOpen"
              >
                <span class="position-relative flex-shrink-0 d-inline-flex">
                  <img
                    v-if="user.profile?.avatar_url"
                    :src="resolvePublicUrl(user.profile.avatar_url)"
                    alt=""
                    class="rounded-circle object-fit-cover"
                    width="40"
                    height="40"
                  />
                  <span
                    v-else
                    class="d-flex align-items-center justify-content-center rounded-circle fw-bold small"
                    style="width: 2.5rem; height: 2.5rem"
                    :class="avatarClass(user.email)"
                  >
                    {{ initials(user.name) }}
                  </span>
                  <span class="vx-avatar-online" aria-hidden="true" />
                </span>
              </button>

              <div
                v-if="menuOpen"
                class="position-absolute end-0 mt-2 rounded-4 border bg-body shadow overflow-hidden"
                style="width: 16rem; z-index: 1080"
                role="menu"
              >
                <div class="border-bottom px-3 pb-3 pt-3">
                  <p class="fw-medium mb-0 text-body">{{ user.name }}</p>
                  <p class="mb-0 small text-secondary text-truncate">
                    {{ user.email }}
                  </p>
                </div>
                <ul class="list-unstyled mb-0 py-1">
                  <li>
                    <RouterLink
                      to="/users/account-settings"
                      class="d-block py-2 px-3 text-body text-decoration-none"
                      @click="closeMenu"
                    >
                      Account Settings
                    </RouterLink>
                  </li>
                  <li>
                    <RouterLink
                      to="/users/support"
                      class="d-block py-2 px-3 text-body text-decoration-none"
                      @click="closeMenu"
                    >
                      Support
                    </RouterLink>
                  </li>
                </ul>
                <div class="border-top" />
                <button
                  type="button"
                  class="btn btn-link text-start text-danger w-100 py-2 px-3 rounded-0 text-decoration-none"
                  role="menuitem"
                  @click="signOut"
                >
                  Sign out
                </button>
              </div>
            </div>
          </div>
        </template>

        <template v-else>
          <div
            class="crm-portal-navbar-search d-flex align-items-center gap-2 flex-grow-1 min-w-0"
          >
            <CrmSearchableSelect
              v-model="staffLookupAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline flex-shrink-0 crm-header-lookup-account"
              appearance="staff"
              aria-label="Limit search to account"
              :options="staffLookupAccountOptions"
              :disabled="staffLookupAccountsLoading || staffSearchLoading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="All accounts"
              button-id="crm-header-lookup-account-trigger"
            />
            <div class="vx-search-merge flex-grow-1 min-w-0">
              <div class="input-group">
                <span class="input-group-text border-end-0">
                  <svg
                    width="20"
                    height="20"
                    class="text-secondary opacity-75 flex-shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                    />
                  </svg>
                </span>
                <input
                  v-model="staffSearch"
                  type="search"
                  class="form-control border-start-0"
                  :placeholder="staffSearchLoading ? 'Searching…' : 'Search Exact Order # or SKU'"
                  autocomplete="off"
                  aria-label="Search exact order number or SKU"
                  :disabled="staffSearchLoading"
                  :aria-busy="staffSearchLoading"
                  @keydown="onStaffSearchKeydown"
                />
              </div>
            </div>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn flex-shrink-0 d-inline-flex align-items-center"
              :disabled="staffSearchLoading || !staffSearch.trim()"
              :aria-busy="staffSearchLoading"
              @click="submitStaffSearch"
            >
              <span
                v-if="staffSearchLoading"
                class="spinner-border spinner-border-sm me-1"
                role="status"
                aria-hidden="true"
              />
              {{ staffSearchLoading ? "Searching…" : "Search" }}
            </button>
          </div>

          <div class="d-flex align-items-center flex-shrink-0 ms-auto">
            <div ref="menuRoot" class="position-relative">
              <button
                type="button"
                class="btn btn-link text-decoration-none text-body d-flex align-items-center rounded-3 py-1 ps-1 pe-1 border-0"
                :aria-expanded="menuOpen"
                aria-haspopup="true"
                @click.stop="menuOpen = !menuOpen"
              >
                <span class="position-relative flex-shrink-0 d-inline-flex">
                  <img
                    v-if="user.profile?.avatar_url"
                    :src="resolvePublicUrl(user.profile.avatar_url)"
                    alt=""
                    class="rounded-circle object-fit-cover"
                    width="40"
                    height="40"
                  />
                  <span
                    v-else
                    class="d-flex align-items-center justify-content-center rounded-circle fw-bold small"
                    style="width: 2.5rem; height: 2.5rem"
                    :class="avatarClass(user.email)"
                  >
                    {{ initials(user.name) }}
                  </span>
                  <span class="vx-avatar-online" aria-hidden="true" />
                </span>
              </button>

              <div
                v-if="menuOpen"
                class="position-absolute end-0 mt-2 rounded-4 border bg-body shadow overflow-hidden"
                style="width: 16rem; z-index: 1080"
                role="menu"
              >
                <div class="border-bottom px-3 pb-3 pt-3">
                  <p class="fw-medium mb-0 text-body">{{ user.name }}</p>
                  <p class="mb-0 small text-secondary text-truncate">
                    {{ user.email }}
                  </p>
                </div>
                <ul class="list-unstyled mb-0 py-1">
                  <li v-if="canManageOwnAccount">
                    <button
                      type="button"
                      class="btn btn-link text-start text-decoration-none text-body w-100 py-2 px-3 rounded-0"
                      @click="openAccountSettingsModal"
                    >
                      Account Settings
                    </button>
                  </li>
                </ul>
                <div class="border-top" />
                <button
                  type="button"
                  class="btn btn-link text-start text-danger w-100 py-2 px-3 rounded-0 text-decoration-none"
                  role="menuitem"
                  @click="signOut"
                >
                  Sign out
                </button>
              </div>
            </div>
          </div>
        </template>
        </div>
      </div>
    </div>
  </header>

  <UserEditModal
    v-if="!isPortalUser && user?.id"
    v-model:open="editProfileModalOpen"
    :user-id="String(user.id)"
    :self-edit="isSelfEditModal"
    @saved="$emit('refresh-user')"
  />
</template>

<style scoped>
.rotate-180 {
  transform: rotate(180deg);
}
.btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
</style>
