<script setup>
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import ClientAccountUserEditModal from "../../components/clients/ClientAccountUserEditModal.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("client_users.create"));
const canUpdate = computed(() => userHasPerm("client_users.update"));
const canDelete = computed(() => userHasPerm("client_users.delete"));

const editModalOpen = ref(false);
const editAccountId = ref("");
const editUserId = ref("");
/** Show kebab column whenever any row menu action exists (View for everyone on this page). */
const showRowActions = computed(() => true);

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

const MENU_W = 200;
const MENU_H = 220;

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

async function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (btn instanceof HTMLElement) {
        placeManageMenu(btn);
      }
    });
  });
}

function goViewRow(row) {
  closeManageMenu();
  router.push(detailRoute(row));
}

function openEditFromMenu(row) {
  closeManageMenu();
  editAccountId.value = String(row.client_account_id);
  editUserId.value = String(row.id);
  editModalOpen.value = true;
}

function openRemoveFromMenu(row) {
  closeManageMenu();
  confirmDelete(row);
}

function canRemoveRow(row) {
  return canDelete.value && !row.is_account_primary;
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) {
    closeManageMenu();
  }
}

function onDocClick(e) {
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
  }
}

const tableColspan = computed(() => {
  let n = 5;
  if (showRowActions.value) n += 1;
  return n;
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const stats = ref({
  total: 0,
  active: 0,
  pending: 0,
  inactive: 0,
});

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const accountOptions = ref([]);

const addOpen = ref(false);
const addSaving = ref(false);
const addError = ref("");
const showAddPassword = ref(false);
const addForm = reactive({
  client_account_id: "",
  name: "",
  email: "",
  password: "",
  password_confirmation: "",
  status: "pending",
});

const deleteTarget = ref(null);
const deleteBusy = ref(false);

const query = reactive({
  search: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "name",
  sort_dir: "asc",
  client_account_id: "",
  status: "all",
});

let searchDebounce = null;
let searchWatchLock = false;

const SORT_KEYS = ["status", "name", "company_name", "account_user_role", "id"];

function toggleSort(column) {
  if (!SORT_KEYS.includes(column)) return;
  if (query.sort_by === column) {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.sort_by = column;
    query.sort_dir = "asc";
  }
  query.page = 1;
  fetchRows();
}

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  return query.sort_by === column
    ? query.sort_dir === "asc"
      ? "ascending"
      : "descending"
    : "none";
}

const deleteModalOpen = computed(() => deleteTarget.value !== null);
const deleteMessage = computed(() => {
  const r = deleteTarget.value;
  return r
    ? `Remove ${r.name} (${r.email}) from this directory? This cannot be undone.`
    : "";
});

const showingFrom = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return Math.min(pagination.value.current_page * query.per_page, t);
});

const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  if (last < 1) return [];
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => ({
      type: "page",
      value: i + 1,
    }));
  }
  const nums = new Set([1, last, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
  const sorted = [...nums].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) out.push({ type: "gap" });
    out.push({ type: "page", value: p });
    prev = p;
  }
  return out;
});

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      fetchRows();
    }, 300);
  },
);

watch(
  () => query.client_account_id,
  () => {
    query.page = 1;
    fetchRows();
  },
);

watch(
  () => query.status,
  () => {
    query.page = 1;
    fetchRows();
  },
);

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "bg-warning-subtle text-warning-emphasis";
  }
  if (s === "inactive") {
    return "bg-secondary-subtle text-secondary";
  }
  return "bg-body-secondary text-body-secondary";
}

const avatarPalettes = [
  "bg-info-subtle text-info-emphasis",
  "bg-primary-subtle text-primary-emphasis",
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

function detailRoute(row) {
  return {
    name: "client-account-user-detail",
    params: {
      accountId: String(row.client_account_id),
      userId: String(row.id),
    },
  };
}

async function loadAccountOptions() {
  try {
    const { data } = await api.get("/client-accounts", {
      params: { per_page: 500, sort_by: "company_name", sort_dir: "asc" },
    });
    const list = Array.isArray(data?.data) ? data.data : [];
    accountOptions.value = list.map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
    }));
  } catch {
    accountOptions.value = [];
  }
}

function buildParams() {
  const p = {
    page: query.page,
    per_page: query.per_page,
    sort_by: query.sort_by,
    sort_dir: query.sort_dir,
  };
  const s = query.search.trim();
  if (s) p.search = s;
  const aid = query.client_account_id;
  if (aid && aid !== "" && aid !== "all") {
    p.client_account_id = Number(aid);
  }
  if (query.status && query.status !== "all") {
    p.status = query.status;
  }
  return p;
}

async function fetchRows() {
  loading.value = true;
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/client-account-users", { params: buildParams() });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? 0,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load client users.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function fetchStats() {
  try {
    const base = { per_page: 1, page: 1 };
    const [all, active, pending, inactive] = await Promise.all([
      api.get("/client-account-users", { params: { ...base } }),
      api.get("/client-account-users", { params: { ...base, status: "active" } }),
      api.get("/client-account-users", { params: { ...base, status: "pending" } }),
      api.get("/client-account-users", { params: { ...base, status: "inactive" } }),
    ]);
    stats.value = {
      total: all.data?.total ?? 0,
      active: active.data?.total ?? 0,
      pending: pending.data?.total ?? 0,
      inactive: inactive.data?.total ?? 0,
    };
  } catch {
    /* ignore */
  }
}

async function refreshList() {
  await fetchRows();
  await fetchStats();
}

function clearFilters() {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.status = "all";
  query.client_account_id = "";
  query.sort_by = "name";
  query.sort_dir = "asc";
  query.page = 1;
  fetchRows().finally(() => {
    searchWatchLock = false;
  });
}

function applySearch() {
  clearTimeout(searchDebounce);
  query.page = 1;
  fetchRows();
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  fetchRows();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  fetchRows();
}

function openAdd() {
  addError.value = "";
  addForm.client_account_id = query.client_account_id || "";
  addForm.name = "";
  addForm.email = "";
  addForm.password = "";
  addForm.password_confirmation = "";
  addForm.status = "pending";
  showAddPassword.value = false;
  addOpen.value = true;
}

function closeAdd() {
  if (!addSaving.value) addOpen.value = false;
}

async function submitAdd() {
  addSaving.value = true;
  addError.value = "";
  try {
    const id = Number(addForm.client_account_id);
    if (!id) {
      addError.value = "Choose an account.";
      return;
    }
    await api.post(`/client-accounts/${id}/account-users`, {
      name: addForm.name.trim(),
      email: addForm.email.trim(),
      password: addForm.password,
      password_confirmation: addForm.password_confirmation,
      status: addForm.status,
    });
    toast.success("User created.");
    addOpen.value = false;
    await refreshList();
  } catch (e) {
    const errs = e.response?.data?.errors;
    if (errs && typeof errs === "object") {
      addError.value = Object.values(errs).flat().join(" ");
    } else {
      addError.value =
        typeof e.response?.data?.message === "string"
          ? e.response.data.message
          : "Could not create user.";
    }
    toast.errorFrom(e, "Could not create user.");
  } finally {
    addSaving.value = false;
  }
}

function confirmDelete(row) {
  if (row.is_account_primary) return;
  deleteTarget.value = row;
}

async function runDelete() {
  const r = deleteTarget.value;
  if (!r) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/client-accounts/${r.client_account_id}/account-users/${r.id}`);
    toast.success("User deleted.");
    deleteTarget.value = null;
    await refreshList();
  } catch (e) {
    toast.errorFrom(e, "Could not delete user.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  setCrmPageMeta({
    title: "Save Rack | Client users",
    description: "Portal logins for client accounts.",
  });
  await loadAccountOptions();
  await refreshList();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <Teleport to="body">
      <Transition name="drawer-fade">
        <div
          v-if="addOpen"
          class="fixed inset-0 z-[1200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
          aria-modal="true"
          role="dialog"
        >
          <div
            class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
            aria-hidden="true"
            @click="closeAdd"
          />
          <Transition name="drawer-slide" appear>
            <aside
              class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-2xl"
            >
              <header
                class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
              >
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                  Add client user
                </h2>
                <button
                  type="button"
                  class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10"
                  aria-label="Close"
                  :disabled="addSaving"
                  @click="closeAdd"
                >
                  <svg
                    class="h-5 w-5"
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
                </button>
              </header>
              <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submitAdd">
                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                  <p
                    v-if="addError"
                    class="mb-4 text-sm text-red-600 dark:text-red-400"
                  >
                    {{ addError }}
                  </p>
                  <div class="space-y-5">
                    <CrmSearchableSelect
                      v-model="addForm.client_account_id"
                      label="Account"
                      :options="accountOptions"
                      placeholder="Choose account"
                      search-placeholder="Search accounts…"
                      empty-label="— Select —"
                      :allow-empty="false"
                    />
                    <div>
                      <label
                        class="form-label small text-secondary mb-1"
                      >Full name</label>
                      <input
                        v-model="addForm.name"
                        type="text"
                        required
                        class="form-control"
                        autocomplete="name"
                      />
                    </div>
                    <div>
                      <label class="form-label small text-secondary mb-1">Email</label>
                      <input
                        v-model="addForm.email"
                        type="email"
                        required
                        class="form-control"
                        autocomplete="email"
                      />
                      <p class="small text-secondary mt-1 mb-0">
                        Must differ from the account primary email.
                      </p>
                    </div>
                    <div>
                      <label class="form-label small text-secondary mb-1">Status</label>
                      <select v-model="addForm.status" class="form-select">
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                      </select>
                    </div>
                    <div>
                      <label class="form-label small text-secondary mb-1">Password</label>
                      <div class="position-relative">
                        <input
                          v-model="addForm.password"
                          :type="showAddPassword ? 'text' : 'password'"
                          required
                          minlength="8"
                          class="form-control pe-5"
                          autocomplete="new-password"
                        />
                        <button
                          type="button"
                          class="btn btn-link btn-sm position-absolute end-0 top-50 translate-middle-y me-1 py-0"
                          @click="showAddPassword = !showAddPassword"
                        >
                          {{ showAddPassword ? "Hide" : "Show" }}
                        </button>
                      </div>
                    </div>
                    <div>
                      <label class="form-label small text-secondary mb-1"
                        >Confirm password</label
                      >
                      <input
                        v-model="addForm.password_confirmation"
                        :type="showAddPassword ? 'text' : 'password'"
                        required
                        minlength="8"
                        class="form-control"
                        autocomplete="new-password"
                      />
                    </div>
                    <p class="small text-secondary mb-0">
                      Role: <strong>Customer Service</strong>
                    </p>
                  </div>
                </div>
                <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
                  <button
                    type="button"
                    :class="CRM_BTN_SECONDARY"
                    :disabled="addSaving"
                    @click="closeAdd"
                  >
                    Cancel
                  </button>
                  <button type="submit" :class="CRM_BTN_PRIMARY" :disabled="addSaving">
                    {{ addSaving ? "Saving…" : "Save" }}
                  </button>
                </footer>
              </form>
            </aside>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Client users</h1>
        <p class="text-secondary small mb-0">
          Primary admins and customer service logins for 3PL accounts
        </p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0"
      >
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="openAdd"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add user
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
          :disabled="loading"
          title="Refresh"
          aria-label="Refresh list"
          @click="refreshList"
        >
          <svg
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          Refresh
        </button>
      </div>
    </div>

    <div class="row g-4 mb-2">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Total users</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.total) }}</p>
          <p class="staff-stat-card__sub">Portal logins in the directory</p>
          <div
            class="staff-stat-card__icon text-white"
            style="background: #2563eb"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Active</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.active) }}</p>
          <p class="staff-stat-card__sub">Accounts marked active</p>
          <div class="staff-stat-card__icon bg-success-subtle text-success" aria-hidden="true">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Pending</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.pending) }}</p>
          <p class="staff-stat-card__sub">Awaiting activation</p>
          <div
            class="staff-stat-card__icon bg-warning-subtle text-warning-emphasis"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 9.5 5 7.49 5 5c0-2.59 2.01-4.5 4.5-4.5S14 2.41 14 5c0 2.49-2.01 4.5-4.5 4.5z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Inactive</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.inactive) }}</p>
          <p class="staff-stat-card__sub">Disabled or suspended</p>
          <div class="staff-stat-card__icon bg-secondary-subtle text-secondary" aria-hidden="true">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
              />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card">
      <div class="staff-datatable-filters">
        <div
          class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3"
        >
          <h2 class="staff-datatable-filters__title mb-0">Filters</h2>
          <button
            type="button"
            class="btn btn-link btn-sm text-secondary text-decoration-none px-0"
            :disabled="loading"
            @click="clearFilters"
          >
            Reset
          </button>
        </div>
        <div class="row g-3 g-md-4">
          <div class="col-12 col-md-4 col-lg-3">
            <label class="visually-hidden" for="cau-filter-status">Status</label>
            <select
              id="cau-filter-status"
              v-model="query.status"
              class="form-select staff-datatable-filters__select"
              :disabled="loading"
              @change="applySearch"
            >
              <option value="all">All statuses</option>
              <option value="pending">Pending</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="col-12 col-md-6 col-lg-4">
            <CrmSearchableSelect
              v-model="query.client_account_id"
              appearance="staff"
              aria-label="Account"
              :options="accountOptions"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              empty-label="All accounts"
            />
          </div>
        </div>
      </div>

      <div class="staff-table-toolbar">
        <div
          class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-3 w-100"
        >
          <input
            id="cau-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--grow"
            placeholder="Search name, email, company…"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('status')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('status')"
                >
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{
                    sortIndicator("status")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('name')"
                >
                  User
                  <span v-if="sortIndicator('name')" class="staff-sort-ind">{{
                    sortIndicator("name")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('company_name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('company_name')"
                >
                  Company
                  <span
                    v-if="sortIndicator('company_name')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("company_name") }}</span
                  >
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('account_user_role')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('account_user_role')"
                >
                  Role
                  <span
                    v-if="sortIndicator('account_user_role')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("account_user_role") }}</span
                  >
                </button>
              </th>
              <th
                v-if="showRowActions"
                class="staff-table-head__th staff-actions-col"
                scope="col"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading users…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle"
            >
              <td>
                <span
                  class="badge rounded-pill text-capitalize fw-medium"
                  :class="statusBadgeClass(row.status)"
                >
                  {{ row.status }}
                </span>
              </td>
              <td>
                <div class="d-flex align-items-center gap-3 min-w-0">
                  <span
                    class="flex-shrink-0 rounded-circle overflow-hidden bg-body-secondary d-inline-flex"
                    style="width: 2.75rem; height: 2.75rem"
                  >
                    <span
                      class="d-flex w-100 h-100 align-items-center justify-content-center fw-semibold staff-user-cell__meta"
                      :class="avatarClassForEmail(row.email)"
                    >
                      {{ initials(row.name) }}
                    </span>
                  </span>
                  <div class="min-w-0">
                    <RouterLink
                      :to="detailRoute(row)"
                      class="d-block text-truncate fw-semibold text-body text-decoration-none"
                    >
                      {{ row.name }}
                    </RouterLink>
                    <RouterLink
                      :to="detailRoute(row)"
                      class="d-block text-truncate text-secondary text-decoration-none staff-user-cell__meta"
                    >
                      {{ row.email }}
                    </RouterLink>
                  </div>
                </div>
              </td>
              <td
                class="text-secondary staff-table-cell__meta text-truncate"
                style="max-width: 14rem"
                :title="row.company_name || undefined"
              >
                {{ row.company_name || "—" }}
              </td>
              <td>
                <span class="text-body text-truncate staff-table-cell__meta">{{
                  row.account_user_role_label || row.account_user_role || "—"
                }}</span>
                <span
                  v-if="row.is_account_primary"
                  class="badge bg-body-secondary text-body-secondary ms-1"
                  >Primary</span
                >
              </td>
              <td v-if="showRowActions" class="staff-actions-cell text-end">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    :aria-row-actions="row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No client users match your filters.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <div
          class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 gap-sm-4 flex-wrap order-2 order-lg-1 justify-content-center justify-content-lg-start"
        >
          <p class="small text-secondary mb-0 text-center text-sm-start">
            Showing
            <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to
            <span class="fw-semibold text-body">{{ showingTo }}</span>
            of
            <span class="fw-semibold text-body">{{ pagination.total }}</span>
            entries
          </p>
          <div
            class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start"
          >
            <label
              class="small text-secondary text-nowrap mb-0"
              for="cau-per-page-footer"
              >Rows per page</label
            >
            <select
              id="cau-per-page-footer"
              class="form-select form-select-sm staff-table-footer-per-page"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
                {{ n }}
              </option>
            </select>
          </div>
        </div>
        <nav
          class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
          aria-label="Client users pages"
        >
          <div class="staff-page-pager staff-page-pager--cluster">
            <div class="staff-page-pager__start">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="First page"
                @click="goFirstPage"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M5.59 18L7 16.59 2.41 12 7 7.41 5.59 6l-6 6 6 6zm8 0L15 16.59 10.41 12 15 7.41 13.59 6l-6 6 6 6z"
                  />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="Previous page"
                @click="goPage(pagination.current_page - 1)"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                </svg>
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <div class="staff-page-pager-inner d-flex align-items-center">
                <template v-for="(item, idx) in pageItems" :key="'pi-' + idx">
                  <span
                    v-if="item.type === 'gap'"
                    class="px-1 small text-secondary user-select-none"
                    >…</span
                  >
                  <button
                    v-else
                    type="button"
                    class="staff-page-pager-tile"
                    :class="{
                      'staff-page-pager-tile--active':
                        item.value === pagination.current_page,
                    }"
                    :disabled="loading"
                    @click="goPage(item.value)"
                  >
                    {{ item.value }}
                  </button>
                </template>
              </div>
            </div>
            <div class="staff-page-pager__end">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Next page"
                @click="goPage(pagination.current_page + 1)"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Last page"
                @click="goLastPage"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M6.41 6L5 7.41 9.58 12 5 16.59 6.41 18l6-6-6-6zm8 0L13 7.41 17.58 12 13 16.59 14.41 18l6-6-6-6z"
                  />
                </svg>
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>

    <ClientAccountUserEditModal
      v-model:open="editModalOpen"
      :client-account-id="editAccountId"
      :user-id="editUserId"
      @saved="refreshList"
    />

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete user?"
      :message="deleteMessage"
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteTarget = null"
      @confirm="runDelete"
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
            @click="goViewRow(manageMenuRow)"
          >
            View
          </button>
          <hr
            v-if="canUpdate || canRemoveRow(manageMenuRow)"
            class="staff-row-menu__divider"
          />
          <button
            v-if="canUpdate"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openEditFromMenu(manageMenuRow)"
          >
            Edit
          </button>
          <hr
            v-if="canUpdate && canRemoveRow(manageMenuRow)"
            class="staff-row-menu__divider"
          />
          <button
            v-if="canRemoveRow(manageMenuRow)"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openRemoveFromMenu(manageMenuRow)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.2s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}
.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.25s ease;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>
