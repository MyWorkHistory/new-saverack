<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientStoreCreateDrawer from "../../components/clients/ClientStoreCreateDrawer.vue";
import ClientStoreEditModal from "../../components/clients/ClientStoreEditModal.vue";
import ClientStoresBulkEditModal from "../../components/clients/ClientStoresBulkEditModal.vue";
import ClientAccountFeesPanel from "../../components/clients/ClientAccountFeesPanel.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import { DEFAULT_PER_PAGE } from "../../constants/pagination";
import {
  inHouseSlackDisplayLabel,
  inHouseSlackHref,
} from "../../utils/slackChannel.js";
import { crmIsAdmin } from "../../utils/crmUser";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import { formatDateTimeUs } from "../../utils/formatUserDates";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  id: { type: String, required: true },
});

const router = useRouter();
const route = useRoute();
const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdateAccount = computed(() => userHasPerm("clients.update"));
const canViewStores = computed(() => userHasPerm("stores.view"));
const canCreateStore = computed(() => userHasPerm("stores.create"));
const canUpdateStore = computed(() => userHasPerm("stores.update"));
const canDeleteStore = computed(() => userHasPerm("stores.delete"));

const loading = ref(true);
const errorMsg = ref("");
const account = ref(null);
const storesLoading = ref(false);
const stores = ref([]);

const editAccountOpen = ref(false);
const editAccountSection = ref("");
const accountManagers = ref([]);

const historyItems = ref([]);

const addStoreOpen = ref(false);
const editStoreOpen = ref(false);
const editingStore = ref(null);

const storeDeleteTarget = ref(null);
const storeDeleteBusy = ref(false);
const storeMenuOpenId = ref(null);
const storeMenuRect = ref({ top: 0, left: 0 });

const storeSearch = ref("");
const storeStatusFilter = ref("all");

const storeListQuery = reactive({
  page: 1,
  per_page: DEFAULT_PER_PAGE,
});
const selectedStoreIds = ref([]);
const storeBulkEditOpen = ref(false);
const storeBulkEditBusy = ref(false);
const storeFilterMenuOpen = ref(false);
const storeBulkDeleteOpen = ref(false);
const storeBulkDeleteBusy = ref(false);

const TAB_ACCOUNT_INFO = "account-info";
const TAB_STORES = "stores";
const TAB_FEES = "fees";
const TAB_BILLING = "billing";

const accountTabList = computed(() => {
  const tabs = [{ id: TAB_ACCOUNT_INFO, label: "Account Info" }];
  if (canViewStores.value) {
    tabs.push({ id: TAB_STORES, label: "Stores" });
  }
  tabs.push(
    { id: TAB_FEES, label: "Fees" },
    { id: TAB_BILLING, label: "Billing" },
  );
  return tabs;
});

const activeTab = ref(TAB_ACCOUNT_INFO);

function tabFromRouteQuery(tab) {
  const t = String(tab || "").toLowerCase();
  if (t === TAB_FEES) return TAB_FEES;
  if (t === TAB_BILLING) return TAB_BILLING;
  if (t === TAB_STORES || t === "stores") return TAB_STORES;
  if (t === TAB_ACCOUNT_INFO || t === "overview") return TAB_ACCOUNT_INFO;
  return TAB_ACCOUNT_INFO;
}

function syncTabFromRoute() {
  let next = tabFromRouteQuery(route.query.tab);
  if (next === TAB_STORES && !canViewStores.value) {
    next = TAB_ACCOUNT_INFO;
  }
  if (activeTab.value !== next) {
    activeTab.value = next;
  }
}

function setActiveTab(tabId) {
  activeTab.value = tabId;
  const q = String(route.query.tab || "");
  if (q !== tabId) {
    router.replace({ query: { ...route.query, tab: tabId } });
  }
}

watch(
  () => route.query.tab,
  () => {
    let next = tabFromRouteQuery(route.query.tab);
    if (next === TAB_STORES && !canViewStores.value) {
      next = TAB_ACCOUNT_INFO;
    }
    if (activeTab.value !== next) {
      activeTab.value = next;
    }
  },
);

watch(canViewStores, (ok) => {
  if (!ok && activeTab.value === TAB_STORES) {
    activeTab.value = TAB_ACCOUNT_INFO;
    const cur = String(route.query.tab || "").toLowerCase();
    if (cur === TAB_STORES || cur === "stores") {
      router.replace({ query: { ...route.query, tab: TAB_ACCOUNT_INFO } });
    }
  }
});

const commentBody = ref("");
const commentFile = ref(null);
const commentFileInput = ref(null);
const commentSubmitting = ref(false);
const commentError = ref("");

const noteMenuOpenId = ref(null);
const noteEditId = ref(null);
const noteEditBody = ref("");
const noteEditSaving = ref(false);
const noteDeleteTarget = ref(null);
const noteDeleteBusy = ref(false);

const accountComments = computed(() => {
  const c = account.value?.comments;
  return Array.isArray(c) ? c : [];
});

const imagePreviewUrls = ref({});

const storeDeleteOpen = computed(() => storeDeleteTarget.value !== null);
const storeDeleteMessage = computed(() => {
  const s = storeDeleteTarget.value;
  return s ? `Delete store “${s.name}”? This cannot be undone.` : "";
});

const noteDeleteModalOpen = computed(() => noteDeleteTarget.value !== null);

const storeBulkDeleteMessage = computed(() => {
  const n = selectedStoreIds.value.length;
  return n
    ? `Delete ${n} store${n === 1 ? "" : "s"}? This cannot be undone.`
    : "";
});
const noteDeleteMessage = computed(() => {
  const c = noteDeleteTarget.value;
  return c ? "Remove this note? This cannot be undone." : "";
});

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
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
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

function accountStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "badge bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "badge bg-warning-subtle text-warning-emphasis";
  }
  if (s === "paused") {
    return "badge bg-info-subtle text-info-emphasis";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-body-secondary text-body-secondary";
}

function storeStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "badge bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "badge bg-warning-subtle text-warning-emphasis";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-body-secondary text-body-secondary";
}

/** Store row website: safe href for anchor (adds https:// when missing). */
function storeWebsiteHref(raw) {
  if (raw == null || raw === "") return "";
  const t = String(raw).trim();
  if (!t) return "";
  if (/^https?:\/\//i.test(t)) return t;
  if (/^\/\//.test(t)) return `https:${t}`;
  return `https://${t}`;
}

/** Display host/path for link text (trim scheme). */
function storeWebsiteLinkLabel(raw) {
  if (raw == null || raw === "") return "";
  let t = String(raw).trim();
  if (!t) return "";
  t = t.replace(/^https?:\/\//i, "").replace(/^\/\//, "");
  t = t.replace(/\/$/, "");
  return t || String(raw).trim();
}

const storeCountDisplay = computed(() => {
  const a = account.value;
  if (a && a.stores_count != null) return Number(a.stores_count);
  return stores.value.length;
});

const usersCountDisplay = computed(() => {
  const a = account.value;
  if (a && a.account_users_count != null) return Number(a.account_users_count);
  return 0;
});

const avatarPalettesWm = [
  "bg-sky-100 text-sky-800",
  "bg-violet-100 text-violet-800",
  "bg-amber-100 text-amber-900",
];

function avatarClassForCommentUser(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettesWm[h % avatarPalettesWm.length];
}

const timelineAvatarPalettes = [
  "bg-info-subtle text-info-emphasis",
  "bg-primary-subtle text-primary-emphasis",
  "bg-warning-subtle text-warning-emphasis",
];

function avatarClassForTimelineActor(label) {
  let h = 0;
  const s = label || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return timelineAvatarPalettes[h % timelineAvatarPalettes.length];
}

function formatFileSize(n) {
  if (n == null || n === "") return "";
  const x = Number(n);
  if (Number.isNaN(x) || x <= 0) return "";
  if (x < 1024) return `${x} B`;
  if (x < 1024 * 1024) return `${(x / 1024).toFixed(1)} KB`;
  return `${(x / (1024 * 1024)).toFixed(1)} MB`;
}

function isImageMime(mime) {
  return typeof mime === "string" && mime.startsWith("image/");
}

const timelinePreview = computed(() => historyItems.value.slice(0, 5));

const primaryAccountUserId = computed(
  () => account.value?.primary_account_user_id ?? null,
);

function accountUserDetailRoute(userId) {
  return {
    name: "client-account-user-detail",
    params: { accountId: String(props.id), userId: String(userId) },
  };
}

function timelineActorAvatarUrl(row) {
  const raw = row?.actor_avatar_url;
  if (!raw) return "";
  return resolvePublicUrl(raw) || raw;
}

function canModifyNote(c) {
  const u = crmUser.value;
  if (!u || !canUpdateAccount.value) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Number(c?.user?.id) === Number(u.id);
}

function closeNoteMenu() {
  noteMenuOpenId.value = null;
}

function closeStoreMenu() {
  storeMenuOpenId.value = null;
}

function requestStoreDelete(row) {
  if (!row) return;
  storeDeleteTarget.value = row;
  closeStoreMenu();
}

function openStoreBulkDelete() {
  if (!selectedStoreIds.value.length) {
    toast.error("Select one or more stores.");
    return;
  }
  storeBulkDeleteOpen.value = true;
}

function closeStoreBulkDelete() {
  if (!storeBulkDeleteBusy.value) storeBulkDeleteOpen.value = false;
}

async function confirmStoreBulkDelete() {
  if (!selectedStoreIds.value.length) return;
  storeBulkDeleteBusy.value = true;
  try {
    await api.delete("/client-stores/bulk", {
      data: { client_store_ids: selectedStoreIds.value },
    });
    toast.success("Stores deleted.");
    storeBulkDeleteOpen.value = false;
    selectedStoreIds.value = [];
    await refreshStoresAndAccountCounts();
  } catch (e) {
    toast.errorFrom(e, "Could not delete stores.");
  } finally {
    storeBulkDeleteBusy.value = false;
  }
}

function toggleNoteMenu(commentId, e) {
  e.stopPropagation();
  noteMenuOpenId.value = noteMenuOpenId.value === commentId ? null : commentId;
}

function toggleStoreMenu(storeId, e) {
  e.stopPropagation();
  if (storeMenuOpenId.value === storeId) {
    closeStoreMenu();
    return;
  }
  const btn = e.currentTarget;
  storeMenuOpenId.value = storeId;
  nextTick(() => {
    requestAnimationFrame(() => {
      if (!(btn instanceof HTMLElement)) return;
      placeStoreMenu(btn);
    });
  });
}

const STORE_MENU_W = 176;
const STORE_MENU_H = 120;
function placeStoreMenu(anchorEl) {
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - STORE_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - STORE_MENU_W - 8));
  if (top + STORE_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - STORE_MENU_H - 4);
  }
  storeMenuRect.value = { top, left };
}

function openEditNote(c) {
  closeNoteMenu();
  noteEditId.value = c.id;
  noteEditBody.value = c.body || "";
}

function cancelEditNote() {
  noteEditId.value = null;
  noteEditBody.value = "";
}

async function saveEditNote(c) {
  const body = noteEditBody.value?.trim() || "";
  if (!body) {
    toast.error("Note cannot be empty.");
    return;
  }
  noteEditSaving.value = true;
  try {
    const { data } = await api.patch(
      `/client-accounts/${props.id}/comments/${c.id}`,
      { body },
    );
    if (account.value && Array.isArray(account.value.comments)) {
      const list = account.value.comments.map((x) =>
        x.id === c.id ? { ...x, ...data } : x,
      );
      account.value = { ...account.value, comments: list };
    }
    cancelEditNote();
    toast.success("Note updated.");
    await loadHistory();
  } catch (e) {
    toast.errorFrom(e, "Could not update note.");
  } finally {
    noteEditSaving.value = false;
  }
}

function requestDeleteNote(c) {
  closeNoteMenu();
  noteDeleteTarget.value = c;
}

function closeNoteDelete() {
  if (!noteDeleteBusy.value) noteDeleteTarget.value = null;
}

async function confirmDeleteNote() {
  const c = noteDeleteTarget.value;
  if (!c) return;
  noteDeleteBusy.value = true;
  try {
    await api.delete(`/client-accounts/${props.id}/comments/${c.id}`);
    if (account.value && Array.isArray(account.value.comments)) {
      const list = account.value.comments.filter((x) => x.id !== c.id);
      account.value = { ...account.value, comments: list };
    }
    const prevUrls = { ...imagePreviewUrls.value };
    delete prevUrls[c.id];
    imagePreviewUrls.value = prevUrls;
    noteDeleteTarget.value = null;
    toast.success("Note deleted.");
    await loadHistory();
  } catch (e) {
    toast.errorFrom(e, "Could not delete note.");
  } finally {
    noteDeleteBusy.value = false;
  }
}

function onDocumentClickCloseNoteMenu(e) {
  const t = e.target;

  if (
    storeFilterMenuOpen.value &&
    t instanceof Element &&
    !t.closest("[data-store-toolbar-filter]")
  ) {
    storeFilterMenuOpen.value = false;
  }

  if (storeMenuOpenId.value !== null) {
    if (!(t instanceof Element) || !t.closest("[data-store-menu-anchor]")) {
      closeStoreMenu();
    }
  }

  if (noteMenuOpenId.value === null) return;
  if (t instanceof Element && t.closest("[data-note-menu-root]")) return;
  closeNoteMenu();
}

function onWindowScrollOrResize() {
  closeStoreMenu();
}

function openAccountEdit(section = "") {
  editAccountSection.value = section;
  editAccountOpen.value = true;
}

function onAccountFeesUpdated(payload) {
  account.value = payload;
}

async function loadHistory() {
  if (!props.id) return;
  try {
    const { data } = await api.get(`/client-accounts/${props.id}/history`);
    const list = data?.items;
    historyItems.value = Array.isArray(list) ? list : [];
  } catch {
    historyItems.value = [];
  }
}

async function submitAccountComment() {
  const body = commentBody.value?.trim() || "";
  if (!body) {
    commentError.value = "Write a comment first.";
    return;
  }
  commentSubmitting.value = true;
  commentError.value = "";
  const fd = new FormData();
  fd.append("body", body);
  const f = commentFile.value;
  if (f) fd.append("attachment", f);
  try {
    const { data } = await api.post(
      `/client-accounts/${props.id}/comments`,
      fd,
      { headers: { "Content-Type": undefined } },
    );
    if (account.value) {
      const list = Array.isArray(account.value.comments)
        ? [...account.value.comments]
        : [];
      list.push(data);
      account.value = { ...account.value, comments: list };
    }
    commentBody.value = "";
    commentFile.value = null;
    if (commentFileInput.value) commentFileInput.value.value = "";
    await loadHistory();
    toast.success("Note added.");
  } catch (e) {
    commentError.value = errorMessage(e, "Could not add note.");
  } finally {
    commentSubmitting.value = false;
  }
}

async function downloadAccountCommentAttachment(commentId) {
  try {
    const res = await api.get(
      `/client-accounts/${props.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    const cd = res.headers?.["content-disposition"];
    let name = "download";
    if (cd && typeof cd === "string") {
      const m = /filename\*?=(?:UTF-8'')?["']?([^"'\s;]+)/i.exec(cd);
      if (m?.[1]) name = decodeURIComponent(m[1].replace(/["']/g, ""));
    }
    const c = accountComments.value.find((x) => x.id === commentId);
    if (c?.attachment?.original_name) name = c.attachment.original_name;
    const url = window.URL.createObjectURL(res.data);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    window.URL.revokeObjectURL(url);
  } catch {
    /* ignore */
  }
}

async function loadImagePreview(commentId) {
  const res = await api.get(
    `/client-accounts/${props.id}/comments/${commentId}/attachment`,
    { responseType: "blob" },
  );
  return window.URL.createObjectURL(res.data);
}

async function ensureAccountImagePreview(comment) {
  if (!comment?.attachment || !isImageMime(comment.attachment.mime)) return;
  const id = comment.id;
  if (imagePreviewUrls.value[id]) return;
  try {
    imagePreviewUrls.value = {
      ...imagePreviewUrls.value,
      [id]: await loadImagePreview(id),
    };
  } catch {
    /* ignore */
  }
}

watch(
  () => account.value?.comments,
  (list) => {
    if (!Array.isArray(list)) return;
    for (const c of list) {
      if (c.attachment && isImageMime(c.attachment.mime)) {
        ensureAccountImagePreview(c);
      }
    }
  },
  { deep: true },
);

const showStoreCheckboxCol = computed(() => canUpdateStore.value);

const storeTableColspan = computed(() => {
  let n = 3;
  if (canUpdateStore.value || canDeleteStore.value) {
    n += 1;
  }
  if (showStoreCheckboxCol.value) {
    n += 1;
  }
  return n;
});

const filteredStores = computed(() => {
  let list = stores.value;
  const st = storeStatusFilter.value;
  if (st && st !== "all") {
    list = list.filter((r) => String(r.status).toLowerCase() === st);
  }
  const q = storeSearch.value.trim().toLowerCase();
  if (!q) return list;
  return list.filter((r) => {
    const hay = `${r.name || ""} ${r.website || ""} ${r.marketplace || ""}`.toLowerCase();
    return hay.includes(q);
  });
});

const storeListTotal = computed(() => filteredStores.value.length);

const storeListLastPage = computed(() => {
  const t = storeListTotal.value;
  const pp = storeListQuery.per_page;
  if (t === 0) return 1;
  return Math.max(1, Math.ceil(t / pp));
});

const paginatedStores = computed(() => {
  const list = filteredStores.value;
  const pp = storeListQuery.per_page;
  const p = storeListQuery.page;
  const start = (p - 1) * pp;
  return list.slice(start, start + pp);
});
const storeMenuRow = computed(
  () => paginatedStores.value.find((r) => r.id === storeMenuOpenId.value) ?? null,
);

const showingStoresFrom = computed(() => {
  const t = storeListTotal.value;
  if (t === 0) return 0;
  return (storeListQuery.page - 1) * storeListQuery.per_page + 1;
});

const showingStoresTo = computed(() => {
  const t = storeListTotal.value;
  if (t === 0) return 0;
  return Math.min(storeListQuery.page * storeListQuery.per_page, t);
});

const storePageItems = computed(() => {
  const last = storeListLastPage.value;
  const cur = storeListQuery.page;
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

const isAllStoresPageSelected = computed(
  () =>
    paginatedStores.value.length > 0 &&
    paginatedStores.value.every((r) => selectedStoreIds.value.includes(r.id)),
);

watch(storeListLastPage, (last) => {
  if (storeListQuery.page > last) {
    storeListQuery.page = last;
  }
});

watch([storeSearch, storeStatusFilter], () => {
  storeListQuery.page = 1;
  selectedStoreIds.value = [];
});

watch(storeStatusFilter, () => {
  storeFilterMenuOpen.value = false;
});

function toggleSelectAllStores() {
  const pageIds = paginatedStores.value.map((r) => r.id);
  if (!pageIds.length) return;
  const allSelected = pageIds.every((id) => selectedStoreIds.value.includes(id));
  if (allSelected) {
    selectedStoreIds.value = selectedStoreIds.value.filter(
      (id) => !pageIds.includes(id),
    );
  } else {
    const set = new Set(selectedStoreIds.value);
    pageIds.forEach((id) => set.add(id));
    selectedStoreIds.value = [...set];
  }
}

function toggleStoreRowSelect(id) {
  const i = selectedStoreIds.value.indexOf(id);
  if (i >= 0) {
    selectedStoreIds.value = selectedStoreIds.value.filter((x) => x !== id);
  } else {
    selectedStoreIds.value = [...selectedStoreIds.value, id];
  }
}

function storeGoPage(p) {
  const last = storeListLastPage.value;
  if (p < 1 || p > last) return;
  storeListQuery.page = p;
}

function storeFirstPage() {
  storeListQuery.page = 1;
}

function storeLastPageFn() {
  storeListQuery.page = storeListLastPage.value;
}

function openStoreBulkEdit() {
  if (!selectedStoreIds.value.length) return;
  storeBulkEditOpen.value = true;
}

async function applyStoreBulkEdit(payload) {
  storeBulkEditBusy.value = true;
  try {
    const body = {
      client_store_ids: selectedStoreIds.value,
      apply_status: !!payload.apply_status,
      apply_marketplace: !!payload.apply_marketplace,
    };
    if (payload.apply_status) {
      body.status = payload.status;
    }
    if (payload.apply_marketplace) {
      body.marketplace = payload.marketplace ?? null;
    }
    await api.patch("/client-stores/bulk", body);
    toast.success("Stores updated.");
    storeBulkEditOpen.value = false;
    selectedStoreIds.value = [];
    await refreshStoresAndAccountCounts();
  } catch (e) {
    toast.errorFrom(e, "Could not update stores.");
  } finally {
    storeBulkEditBusy.value = false;
  }
}

function normalizeAccountManagersFromMeta(payload) {
  const raw =
    payload?.account_managers ??
    payload?.accountManagers ??
    (Array.isArray(payload) ? payload : null);
  if (!Array.isArray(raw)) return [];
  return raw.map((row) => ({
    id: Number(row.id),
    name: row.name != null ? String(row.name) : "",
    email: row.email != null ? String(row.email) : "",
  }));
}

async function fetchMeta() {
  try {
    const { data } = await api.get("/client-accounts/meta");
    accountManagers.value = normalizeAccountManagersFromMeta(data);
  } catch (e) {
    accountManagers.value = [];
    toast.errorFrom(e, "Could not load account manager list.");
  }
}

async function loadAccount() {
  loading.value = true;
  errorMsg.value = "";
  account.value = null;
  try {
    const { data } = await api.get(`/client-accounts/${props.id}`);
    account.value = data;
  } catch (e) {
    const st = e.response?.status;
    if (st === 403) {
      errorMsg.value = "You don't have access to this account.";
    } else if (st === 404) {
      errorMsg.value = "Account not found.";
    } else {
      errorMsg.value = "Could not load account.";
    }
  } finally {
    loading.value = false;
  }
}

async function loadStores() {
  if (!canViewStores.value || !props.id) return;
  storesLoading.value = true;
  try {
    const { data } = await api.get(`/client-accounts/${props.id}/stores`);
    stores.value = Array.isArray(data) ? data : [];
  } catch (e) {
    stores.value = [];
    if (e.response?.status !== 403) {
      toast.errorFrom(e, "Could not load stores.");
    }
  } finally {
    storesLoading.value = false;
  }
}

async function refreshStoresAndAccountCounts() {
  await loadStores();
  await loadAccount();
}

function openEditStore(row) {
  closeStoreMenu();
  editingStore.value = { ...row };
  editStoreOpen.value = true;
}

function closeStoreDelete() {
  if (storeDeleteBusy.value) return;
  storeDeleteTarget.value = null;
}

async function confirmStoreDelete() {
  const row = storeDeleteTarget.value;
  if (!row) return;
  storeDeleteBusy.value = true;
  try {
    await api.delete(`/client-stores/${row.id}`);
    storeDeleteTarget.value = null;
    toast.success("Store deleted.");
    await refreshStoresAndAccountCounts();
  } catch (e) {
    toast.errorFrom(e, "Could not delete store.");
  } finally {
    storeDeleteBusy.value = false;
  }
}

watch(
  () => account.value?.company_name,
  (name) => {
    if (name && typeof name === "string") {
      setCrmPageMeta({
        title: `Save Rack | ${name}`,
        description: `Client account ${name}.`,
      });
    }
  },
);

watch(editAccountOpen, (o) => {
  if (o) fetchMeta();
});

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Account",
    description: "Client account profile.",
  });
  document.addEventListener("click", onDocumentClickCloseNoteMenu);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  await loadAccount();
  syncTabFromRoute();
  await loadStores();
  await loadHistory();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocumentClickCloseNoteMenu);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  for (const url of Object.values(imagePreviewUrls.value)) {
    if (typeof url === "string") window.URL.revokeObjectURL(url);
  }
});
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/dashboard">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/clients/accounts">Accounts</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{
        account?.company_name || "Account"
      }}</span>
    </nav>

    <div class="staff-user-view__title-row d-flex flex-wrap align-items-start gap-2">
      <div class="min-w-0">
        <h1 class="staff-user-view__title">Account Profile</h1>
      </div>
    </div>

    <ClientAccountEditModal
      v-if="canUpdateAccount"
      v-model:open="editAccountOpen"
      :account-id="id"
      :account-managers="accountManagers"
      :section="editAccountSection"
      @saved="
        loadAccount();
        loadHistory();
      "
    />
    <ClientStoreCreateDrawer
      v-if="canCreateStore && canViewStores"
      v-model:open="addStoreOpen"
      :client-account-id="id"
      @saved="refreshStoresAndAccountCounts"
    />
    <ClientStoreEditModal
      v-if="canUpdateStore"
      v-model:open="editStoreOpen"
      :store="editingStore"
      @saved="refreshStoresAndAccountCounts"
    />
    <ClientStoresBulkEditModal
      v-if="canUpdateStore && canViewStores"
      v-model:open="storeBulkEditOpen"
      :selected-count="selectedStoreIds.length"
      :busy="storeBulkEditBusy"
      @apply="applyStoreBulkEdit"
    />
    <ConfirmModal
      :open="storeDeleteOpen"
      title="Delete store"
      :message="storeDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="storeDeleteBusy"
      @close="closeStoreDelete"
      @confirm="confirmStoreDelete"
    />
    <ConfirmModal
      :open="storeBulkDeleteOpen"
      title="Delete stores"
      :message="storeBulkDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="storeBulkDeleteBusy"
      @close="closeStoreBulkDelete"
      @confirm="confirmStoreBulkDelete"
    />
    <ConfirmModal
      :open="noteDeleteModalOpen"
      title="Delete Note"
      :message="noteDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="noteDeleteBusy"
      danger
      @close="closeNoteDelete"
      @confirm="confirmDeleteNote"
    />

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading account…" />
    </div>

    <template v-else-if="errorMsg">
      <p class="text-danger small mb-2">
        {{ errorMsg }}
      </p>
      <RouterLink to="/clients/accounts" class="small"
        >Back to accounts</RouterLink
      >
    </template>

    <template v-else-if="account">
      <div class="row g-3">
        <div class="col-12 col-xl-4">
          <aside class="staff-user-profile">
            <div class="staff-user-profile__avatar-wrap">
              <span
                class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                :class="avatarClassForEmail(account.email)"
              >
                {{ initials(account.company_name) }}
              </span>
            </div>
            <h2 class="staff-user-profile__name">
              {{ account.company_name }}
            </h2>
            <div class="text-center mb-3">
              <span
                class="text-capitalize"
                :class="accountStatusBadgeClass(account.status)"
              >{{ account.status }}</span>
            </div>
            <div class="staff-user-profile__stats">
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ nf.format(storeCountDisplay) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Stores</div>
              </div>
              <div class="staff-user-profile__stat">
                <div class="staff-user-profile__stat-icon" aria-hidden="true">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
                    />
                  </svg>
                </div>
                <div class="staff-user-profile__stat-val">
                  {{ nf.format(usersCountDisplay) }}
                </div>
                <div class="staff-user-profile__stat-lbl">Users</div>
              </div>
            </div>

            <div
              class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"
            >
              <h3 class="staff-user-profile__details-title mb-0">Details</h3>
              <button
                v-if="canUpdateAccount"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="openAccountEdit('left')"
              >
                Edit
              </button>
            </div>
            <dl class="staff-user-profile__dl">
              <div>
                <dt class="staff-user-profile__dt">Email</dt>
                <dd class="staff-user-profile__dd text-break">
                  {{ display(account.email) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Phone</dt>
                <dd class="staff-user-profile__dd">
                  {{ display(account.phone) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Account manager</dt>
                <dd class="staff-user-profile__dd">
                  {{ display(account.account_manager?.name) }}
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">Channels</dt>
                <dd class="staff-user-profile__dd text-end">
                  <div class="d-flex justify-content-end flex-wrap gap-1">
                    <ClientAccountChannelIcons
                      :notify-email="!!account.notify_email"
                      :telegram-handle="account.telegram_handle || ''"
                      :whatsapp-e164="account.whatsapp_e164 || ''"
                      :slack-channel="account.slack_channel || ''"
                      :in-house-slack="account.in_house_slack || ''"
                    />
                  </div>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">In-House Slack</dt>
                <dd class="staff-user-profile__dd text-break">
                  <template v-if="inHouseSlackHref(account.in_house_slack)">
                    <a
                      :href="inHouseSlackHref(account.in_house_slack)"
                      class="link-primary text-decoration-none text-break"
                      :aria-label="`${inHouseSlackDisplayLabel(account.in_house_slack)} in Slack (opens in new tab)`"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {{ inHouseSlackDisplayLabel(account.in_house_slack) }}
                    </a>
                  </template>
                  <template v-else-if="account.in_house_slack">
                    <span class="text-body text-break">{{
                      inHouseSlackDisplayLabel(account.in_house_slack) ||
                      display(account.in_house_slack)
                    }}</span>
                  </template>
                  <template v-else>{{ display(account.in_house_slack) }}</template>
                </dd>
              </div>
            </dl>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
        <div class="staff-user-tabs" role="tablist">
          <button
            v-for="t in accountTabList"
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
          :aria-label="accountTabList.find((x) => x.id === activeTab)?.label"
        >
          <template v-if="activeTab === TAB_ACCOUNT_INFO">
            <div class="staff-surface p-3 p-md-4 mb-4">
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
              >
                <h3 class="staff-user-section-title mb-0">Personal information</h3>
                <button
                  v-if="canUpdateAccount"
                  type="button"
                  class="btn btn-sm btn-primary staff-page-primary"
                  @click="openAccountEdit('account')"
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
                      Company
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.company_name) }}
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
                      {{ display(account.email) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      Name
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.contact_full_name) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      Phone number
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.phone) }}
                    </dd>
                  </dl>
                </div>
              </div>
              <div
                class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center gap-2"
              >
                <RouterLink
                  v-if="primaryAccountUserId"
                  class="btn btn-sm btn-outline-primary"
                  :to="accountUserDetailRoute(primaryAccountUserId)"
                >
                  View primary portal user
                </RouterLink>
              </div>
            </div>

            <div class="staff-surface p-3 p-md-4 mb-4">
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3"
              >
                <h3 class="staff-user-section-title mb-0">Address</h3>
                <button
                  v-if="canUpdateAccount"
                  type="button"
                  class="btn btn-sm btn-primary staff-page-primary"
                  @click="openAccountEdit('address')"
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
                      Street
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.street) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      City
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.city) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      State / ZIP
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.state) }}
                      <template v-if="account.zip">
                        <span v-if="account.state"> </span
                        >{{ display(account.zip) }}
                      </template>
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      Country
                    </dt>
                    <dd class="mb-0 fw-semibold text-body">
                      {{ display(account.country) }}
                    </dd>
                  </dl>
                </div>
              </div>
            </div>

            <div class="staff-table-card staff-datatable-card overflow-hidden mb-4">
              <div class="px-4 py-3 px-md-5 py-md-3 border-bottom">
                <h3 class="h6 fw-semibold text-body mb-0">Notes</h3>
              </div>
              <div class="p-4 p-md-5">
                <ul
                  v-if="accountComments.length"
                  class="list-unstyled mb-0 pb-4 border-bottom"
                >
                  <li
                    v-for="c in accountComments"
                    :key="c.id"
                    class="d-flex gap-3 mb-4"
                  >
                    <img
                      v-if="c.user?.avatar_url"
                      :src="resolvePublicUrl(c.user.avatar_url) || c.user.avatar_url"
                      alt=""
                      class="account-note-avatar rounded-circle flex-shrink-0 object-fit-cover"
                    />
                    <span
                      v-else
                      class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold account-note-avatar"
                      :class="avatarClassForCommentUser(c.user?.email)"
                    >
                      {{ initials(c.user?.name) }}
                    </span>
                    <div class="min-w-0 flex-grow-1">
                      <div
                        class="d-flex align-items-start justify-content-between gap-2"
                      >
                        <div
                          class="d-flex flex-wrap align-items-baseline gap-2 min-w-0"
                        >
                          <span class="small fw-medium text-body">{{
                            c.user?.name || "User"
                          }}</span>
                          <span class="small text-secondary">{{
                            formatDateTimeUs(c.created_at)
                          }}</span>
                          <span
                            v-if="
                              c.updated_at &&
                              c.created_at &&
                              String(c.updated_at) !== String(c.created_at)
                            "
                            class="small text-secondary fst-italic"
                            >(edited)</span
                          >
                        </div>
                        <div
                          v-if="canModifyNote(c)"
                          class="flex-shrink-0 position-relative"
                          data-note-menu-root
                        >
                          <button
                            type="button"
                            class="btn btn-link btn-sm text-secondary p-1 lh-1 rounded border-0"
                            :class="{ 'text-body': noteMenuOpenId === c.id }"
                            :aria-expanded="noteMenuOpenId === c.id"
                            aria-haspopup="true"
                            aria-label="Note actions"
                            @click="toggleNoteMenu(c.id, $event)"
                          >
                            <CrmIconRowActions variant="horizontal" />
                          </button>
                          <div
                            v-if="noteMenuOpenId === c.id"
                            class="staff-row-menu position-absolute end-0 mt-1 py-1 shadow border"
                            style="min-width: 11rem; z-index: 400"
                            role="menu"
                            @click.stop
                          >
                            <button
                              type="button"
                              class="staff-row-menu__item"
                              role="menuitem"
                              @click="openEditNote(c)"
                            >
                              Edit Note
                            </button>
                            <button
                              type="button"
                              class="staff-row-menu__item staff-row-menu__item--danger"
                              role="menuitem"
                              @click="requestDeleteNote(c)"
                            >
                              Delete Note
                            </button>
                          </div>
                        </div>
                      </div>
                      <template v-if="noteEditId === c.id">
                        <textarea
                          v-model="noteEditBody"
                          class="form-control form-control-sm mt-2"
                          rows="4"
                          aria-label="Edit note"
                        />
                        <div class="d-flex flex-wrap gap-2 mt-2">
                          <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="noteEditSaving"
                            @click="saveEditNote(c)"
                          >
                            {{ noteEditSaving ? "Saving…" : "Save" }}
                          </button>
                          <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            :disabled="noteEditSaving"
                            @click="cancelEditNote"
                          >
                            Cancel
                          </button>
                        </div>
                      </template>
                      <template v-else>
                        <p class="mt-1 mb-0 small text-body notes-pre-wrap">
                          {{ c.body }}
                        </p>
                        <div v-if="c.attachment" class="mt-3">
                          <img
                            v-if="
                              isImageMime(c.attachment.mime) &&
                              imagePreviewUrls[c.id]
                            "
                            :src="imagePreviewUrls[c.id]"
                            alt=""
                            class="img-fluid rounded border"
                            style="max-height: 12rem"
                          />
                          <button
                            type="button"
                            class="btn btn-link btn-sm text-decoration-none p-0 mt-2 d-inline-flex align-items-center gap-1"
                            @click="downloadAccountCommentAttachment(c.id)"
                          >
                            <span v-if="c.attachment.original_name">{{
                              c.attachment.original_name
                            }}</span>
                            <span v-else>Download attachment</span>
                            <span
                              v-if="formatFileSize(c.attachment.size)"
                              class="text-secondary"
                              >({{ formatFileSize(c.attachment.size) }})</span
                            >
                          </button>
                        </div>
                      </template>
                    </div>
                  </li>
                </ul>
                <p
                  v-else
                  class="text-secondary small border-bottom pb-4 mb-0"
                >
                  No notes yet.
                </p>

                <div v-if="canUpdateAccount" class="pt-4">
                  <label
                    class="form-label small text-secondary"
                    for="client-account-note"
                    >Add Note</label
                  >
                  <textarea
                    id="client-account-note"
                    v-model="commentBody"
                    rows="3"
                    class="form-control"
                    placeholder="Write an update…"
                  />
                  <div
                    class="mt-3 d-flex flex-wrap align-items-center gap-2"
                  >
                    <input
                      ref="commentFileInput"
                      type="file"
                      accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
                      class="form-control form-control-sm flex-grow-1"
                      style="min-width: 12rem; max-width: 100%"
                      @change="commentFile = $event.target.files?.[0] || null"
                    />
                    <button
                      type="button"
                      class="btn btn-primary staff-page-primary text-nowrap flex-shrink-0"
                      :disabled="commentSubmitting"
                      @click="submitAccountComment"
                    >
                      {{ commentSubmitting ? "Adding…" : "Add Note" }}
                    </button>
                  </div>
                  <p
                    v-if="commentError"
                    class="text-danger small mt-2 mb-0"
                  >
                    {{ commentError }}
                  </p>
                  <p class="text-secondary small mt-2 mb-0">
                    Optional attachment: image, PDF, or small document (max 5
                    MB).
                  </p>
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="activeTab === TAB_STORES && canViewStores">
              <div class="staff-table-card staff-datatable-card">
                <div class="staff-table-toolbar">
                  <div class="staff-table-toolbar--row">
                    <input
                      v-model="storeSearch"
                      type="search"
                      class="form-control staff-toolbar-search staff-toolbar-search--inline"
                      placeholder="Search stores"
                      autocomplete="off"
                    />
                    <div
                      class="position-relative flex-shrink-0"
                      data-store-toolbar-filter
                    >
                      <button
                        type="button"
                        class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
                        :aria-expanded="storeFilterMenuOpen"
                        aria-haspopup="true"
                        aria-controls="store-filter-panel"
                        :disabled="storesLoading"
                        @click.stop="storeFilterMenuOpen = !storeFilterMenuOpen"
                      >
                        <svg
                          width="18"
                          height="18"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                          viewBox="0 0 24 24"
                          aria-hidden="true"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                          />
                        </svg>
                        <span class="d-none d-sm-inline">Filters</span>
                      </button>
                      <div
                        v-if="storeFilterMenuOpen"
                        id="store-filter-panel"
                        class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
                        role="dialog"
                        aria-label="Table filters"
                        @click.stop
                      >
                        <div class="staff-toolbar-filter-dropdown__head">
                          <span>Filters</span>
                          <button
                            type="button"
                            class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                            :disabled="storesLoading"
                            @click="
                              storeStatusFilter = 'all';
                              storeFilterMenuOpen = false;
                            "
                          >
                            Reset
                          </button>
                        </div>
                        <div class="staff-toolbar-filter-dropdown__body">
                          <label class="form-label" for="store-filter-status"
                            >Status</label
                          >
                          <select
                            id="store-filter-status"
                            v-model="storeStatusFilter"
                            class="form-select staff-datatable-filters__select mb-0"
                            :disabled="storesLoading"
                          >
                            <option value="all">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div
                      class="d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
                    >
                      <button
                        v-if="canCreateStore"
                        type="button"
                        class="btn btn-primary staff-page-primary btn-sm"
                        @click="addStoreOpen = true"
                      >
                        Add Store
                      </button>
                      <button
                        v-if="canUpdateStore"
                        type="button"
                        class="btn btn-outline-secondary staff-toolbar-btn"
                        :disabled="!selectedStoreIds.length || storesLoading"
                        @click="openStoreBulkEdit"
                      >
                        Bulk Edit
                      </button>
                      <button
                        v-if="canDeleteStore"
                        type="button"
                        class="btn btn-outline-danger staff-toolbar-btn"
                        :disabled="!selectedStoreIds.length || storesLoading"
                        @click="openStoreBulkDelete"
                      >
                        Bulk Delete
                      </button>
                    </div>
                  </div>
                </div>
                <div class="table-responsive staff-table-wrap">
                  <div v-if="storesLoading" class="d-flex justify-content-center py-5">
                    <CrmLoadingSpinner message="Loading stores…" />
                  </div>
                  <table
                    v-else
                    class="table table-hover align-middle mb-0 staff-data-table"
                  >
                    <thead class="table-light staff-table-head">
                      <tr>
                        <th
                          v-if="showStoreCheckboxCol"
                          class="staff-table-head__th staff-table-head__th--select"
                          scope="col"
                        >
                          <input
                            type="checkbox"
                            class="form-check-input staff-table-head__check mt-0"
                            :checked="isAllStoresPageSelected"
                            :disabled="storesLoading || !paginatedStores.length"
                            aria-label="Select all stores on this page"
                            @change="toggleSelectAllStores"
                          />
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Store name
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Status
                        </th>
                        <th class="staff-table-head__th" scope="col">
                          Marketplace
                        </th>
                        <th
                          v-if="canUpdateStore || canDeleteStore"
                          class="staff-table-head__th staff-actions-col text-center client-account-stores-actions-col"
                          scope="col"
                        >
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!filteredStores.length">
                        <td
                          :colspan="storeTableColspan"
                          class="px-4 py-5 text-center text-secondary"
                        >
                          No stores yet.
                        </td>
                      </tr>
                      <tr
                        v-for="row in paginatedStores"
                        v-else
                        :key="row.id"
                        class="align-middle"
                      >
                        <td
                          v-if="showStoreCheckboxCol"
                          class="staff-table-cell--tight-check"
                        >
                          <input
                            type="checkbox"
                            class="form-check-input staff-table-head__check mt-0"
                            :checked="selectedStoreIds.includes(row.id)"
                            :aria-label="`Select ${row.name}`"
                            @change="toggleStoreRowSelect(row.id)"
                          />
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-3 min-w-0">
                            <span
                              class="flex-shrink-0 rounded-circle d-inline-flex align-items-center justify-content-center small fw-semibold"
                              style="width: 2.25rem; height: 2.25rem"
                              :class="avatarClassForEmail(row.name)"
                            >
                              {{ initials(row.name) }}
                            </span>
                            <div class="min-w-0">
                              <span class="d-block fw-semibold text-body text-truncate">{{
                                row.name
                              }}</span>
                              <a
                                v-if="row.website && storeWebsiteHref(row.website)"
                                class="d-block small text-primary text-truncate text-decoration-none mt-1"
                                :href="storeWebsiteHref(row.website)"
                                target="_blank"
                                rel="noopener noreferrer"
                              >
                                {{ storeWebsiteLinkLabel(row.website) }}
                              </a>
                            </div>
                          </div>
                        </td>
                        <td>
                          <span
                            class="text-capitalize fw-medium"
                            :class="storeStatusBadgeClass(row.status)"
                          >
                            {{ row.status }}
                          </span>
                        </td>
                        <td class="text-secondary staff-table-cell__meta">
                          {{ display(row.marketplace) }}
                        </td>
                        <td
                          v-if="canUpdateStore || canDeleteStore"
                          class="staff-actions-cell text-center client-account-stores-actions-cell"
                        >
                          <div
                            class="staff-actions-inner staff-actions-inner--single d-inline-flex"
                            data-store-menu-anchor
                          >
                            <button
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :class="{ 'is-open': storeMenuOpenId === row.id }"
                              :aria-expanded="storeMenuOpenId === row.id"
                              aria-haspopup="true"
                              aria-label="Store actions"
                              @click="toggleStoreMenu(row.id, $event)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p
                  v-if="canViewStores"
                  class="staff-table-mobile-scroll-cue d-md-none"
                  aria-hidden="true"
                >
                  Scroll sideways or swipe to see all columns.
                </p>
                <div
                  v-if="!storesLoading && filteredStores.length > 0"
                  class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
                >
                  <p
                    class="small text-secondary mb-0 order-2 order-lg-1 text-center text-lg-start"
                  >
                    Showing
                    <span class="fw-semibold text-body">{{ showingStoresFrom }}</span>
                    to
                    <span class="fw-semibold text-body">{{ showingStoresTo }}</span>
                    of
                    <span class="fw-semibold text-body">{{ storeListTotal }}</span>
                    entries
                  </p>
                  <nav
                    class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
                    aria-label="Store list pages"
                  >
                    <div class="staff-page-pager staff-page-pager--cluster">
                      <div class="staff-page-pager__start">
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="storesLoading || storeListQuery.page <= 1"
                          aria-label="First page"
                          @click="storeFirstPage"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path
                              d="M5.59 18L7 16.59 2.41 12 7 7.41 5.59 6l-6 6 6 6zm8 0L15 16.59 10.41 12 15 7.41 13.59 6l-6 6 6 6z"
                            />
                          </svg>
                        </button>
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="storesLoading || storeListQuery.page <= 1"
                          aria-label="Previous page"
                          @click="storeGoPage(storeListQuery.page - 1)"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                          </svg>
                        </button>
                      </div>
                      <div class="staff-page-pager__pages">
                        <div class="staff-page-pager-inner d-flex align-items-center">
                          <template
                            v-for="(item, idx) in storePageItems"
                            :key="'st-pi-' + idx"
                          >
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
                                  item.value === storeListQuery.page,
                              }"
                              :disabled="storesLoading"
                              @click="storeGoPage(item.value)"
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
                          :disabled="
                            storesLoading ||
                            storeListQuery.page >= storeListLastPage
                          "
                          aria-label="Next page"
                          @click="storeGoPage(storeListQuery.page + 1)"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" />
                          </svg>
                        </button>
                        <button
                          type="button"
                          class="staff-page-pager-tile staff-page-pager-tile--nav"
                          :disabled="
                            storesLoading ||
                            storeListQuery.page >= storeListLastPage
                          "
                          aria-label="Last page"
                          @click="storeLastPageFn"
                        >
                          <svg
                            width="18"
                            height="18"
                            fill="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
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
          </template>

          <template v-else-if="activeTab === TAB_FEES">
            <div class="staff-surface p-3 p-md-4">
              <ClientAccountFeesPanel
                :account="account"
                :account-id="String(props.id)"
                :can-edit="canUpdateAccount"
                @fees-updated="onAccountFeesUpdated"
              />
            </div>
          </template>

          <template v-else-if="activeTab === TAB_BILLING">
            <div class="staff-surface p-3 p-md-4">
              <p class="text-secondary small mb-0">
                Billing history and invoices will appear here.
              </p>
            </div>
          </template>
        </div>
        </div>
      </div>

      <section
        class="staff-user-timeline-card mt-3"
        aria-labelledby="client-account-activity-heading"
      >
        <h2
          id="client-account-activity-heading"
          class="staff-user-timeline-card__title mb-3"
        >
          Account activity
        </h2>
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
              width="36"
              height="36"
            />
            <span
              v-else
              class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
              style="width: 36px; height: 36px; font-size: 0.6875rem"
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
                  >{{ formatDateTimeUs(row.created_at) }}</time
                >
              </div>
              <p class="staff-user-timeline__body">
                {{ row.body || row.line }}
              </p>
            </div>
          </div>
        </div>
        <p v-else class="staff-user-timeline__empty">
          No activity logged yet.
        </p>
      </section>

      <Teleport to="body">
        <Transition
          enter-active-class="transition ease-out duration-100"
          enter-from-class="opacity-0"
          enter-to-class="opacity-100"
          leave-active-class="transition ease-in duration-75"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0"
        >
          <div
            v-if="storeMenuRow"
            class="staff-row-menu fixed z-[300]"
            role="menu"
            :style="{
              top: `${storeMenuRect.top}px`,
              left: `${storeMenuRect.left}px`,
              minWidth: '11rem',
            }"
            @click.stop
          >
            <button
              v-if="canUpdateStore"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openEditStore(storeMenuRow)"
            >
              Edit
            </button>
            <button
              v-if="canDeleteStore"
              type="button"
              class="staff-row-menu__item staff-row-menu__item--danger"
              role="menuitem"
              @click="requestStoreDelete(storeMenuRow)"
            >
              Delete
            </button>
          </div>
        </Transition>
      </Teleport>
    </template>
  </div>
</template>

<style scoped>
.account-note-avatar {
  width: 2.25rem;
  height: 2.25rem;
  font-size: 0.6875rem;
}
.notes-pre-wrap {
  white-space: pre-wrap;
}
.object-fit-cover {
  object-fit: cover;
}
</style>
