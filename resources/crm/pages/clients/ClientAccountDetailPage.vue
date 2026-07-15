<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import CrmStatusUpdateModal from "../../components/common/CrmStatusUpdateModal.vue";
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientAccountFeesPanel from "../../components/clients/ClientAccountFeesPanel.vue";
import ClientAccountOnboardingPanel from "../../components/clients/ClientAccountOnboardingPanel.vue";
import ClientAccountBillingPanel from "../../components/clients/ClientAccountBillingPanel.vue";
import ClientAccountOrdersPanel from "../../components/clients/ClientAccountOrdersPanel.vue";
import ClientAccountInventoryPanel from "../../components/clients/ClientAccountInventoryPanel.vue";
import ClientAccountAsnPanel from "../../components/clients/ClientAccountAsnPanel.vue";
import AccountDetailSectionHead from "../../components/clients/AccountDetailSectionHead.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import {
  ONBOARDING_ACTIVATION_BLOCKED_MESSAGE,
  checkOnboardingReadyForActivation,
} from "../../utils/clientAccountOnboardingActivation.js";
import { inHouseSlackDisplayLabel, inHouseSlackHref } from "../../utils/slackChannel.js";
import { warnIfShipheroSyncFailed } from "../../utils/clientAccountShipheroSync.js";
import { CLIENT_ACCOUNT_PAUSE_REASONS } from "../../constants/clientAccountPauseReasons.js";
import {
  SHIPHERO_STORE_TYPE_OPTIONS,
  shipHeroStoreTypeLabel,
  shipHeroStoreSettingsUrl,
  shipHeroStoreShopId,
  isShipHeroStoreApiType,
} from "../../constants/shipHeroStoreTypes.js";

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
const canImportStores = computed(() => userHasPerm("stores.create"));
const canUpdateStores = computed(() => userHasPerm("stores.update"));
const canDeleteStores = computed(() => userHasPerm("stores.delete"));

const loading = ref(true);
const errorMsg = ref("");
const account = ref(null);
const storesLoading = ref(false);
const storesImporting = ref(false);
const stores = ref([]);
const storesImportedAt = ref(null);

const storeActionMenuRow = ref(null);
const storeActionMenuRect = ref({ top: 0, left: 0 });
const storeEditTypeOpen = ref(false);
const storeEditTypeBusy = ref(false);
const storeEditTypeRow = ref(null);
const storeEditTypeForm = ref({ store_type: "", shop_id: "" });
const storeDeleteOpen = ref(false);
const storeDeleteBusy = ref(false);
const storeDeleteRow = ref(null);

const editAccountOpen = ref(false);
const editAccountSection = ref("");
const accountManagers = ref([]);
const accountStatuses = ref(["pending", "active", "paused", "inactive"]);
const accountStatusModalOpen = ref(false);
const accountStatusForm = ref("pending");
const accountPauseReasonForm = ref("");
const accountStatusSaving = ref(false);

const brandLogoInput = ref(null);
const brandLogoUploadBusy = ref(false);

const historyItems = ref([]);

const TAB_ACCOUNT_INFO = "account-info";
const TAB_STORES = "stores";
const TAB_FEES = "fees";
const TAB_BILLING = "billing";
const TAB_ORDERS = "orders";
const TAB_INVENTORY = "inventory";
const TAB_ASN = "asn";
const TAB_ONBOARDING = "onboarding";
const TAB_HISTORY = "history";
const TAB_SETTINGS = "settings";

const accountTabList = computed(() => {
  const tabs = [{ id: TAB_ACCOUNT_INFO, label: "Account Info" }];
  if (canViewStores.value) {
    tabs.push({ id: TAB_STORES, label: "Stores" });
  }
  tabs.push(
    { id: TAB_FEES, label: "Fees" },
    { id: TAB_BILLING, label: "Billing" },
    { id: TAB_ORDERS, label: "Orders" },
    { id: TAB_INVENTORY, label: "Inventory" },
    { id: TAB_ASN, label: "ASN" },
    { id: TAB_ONBOARDING, label: "Onboarding" },
    { id: TAB_HISTORY, label: "History" },
    { id: TAB_SETTINGS, label: "Settings" },
  );
  return tabs;
});

/** Stroke icon paths (24×24) for account detail tab buttons. */
function accountTabIconPath(tabId) {
  switch (tabId) {
    case TAB_ACCOUNT_INFO:
      return "M3 7.5A2.25 2.25 0 015.25 5.25h13.5A2.25 2.25 0 0121 7.5v9A2.25 2.25 0 0118.75 18.75H5.25A2.25 2.25 0 013 16.5v-9zM8.25 9.75h7.5M8.25 12.75h4.5";
    case TAB_STORES:
      return "M3 9.75L12 4.5l9 5.25M4.5 10.5v8.25A1.5 1.5 0 006 20.25h3.75M4.5 10.5h15M19.5 10.5v8.25a1.5 1.5 0 01-1.5 1.5H15M9 20.25h6M9 14.25h.008v.008H9v-.008zm3 0h.008v.008H12v-.008zm3 0h.008v.008H15v-.008z";
    case TAB_FEES:
      return "M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z";
    case TAB_BILLING:
      return "M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z";
    case TAB_ORDERS:
      return "M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z";
    case TAB_INVENTORY:
      return "M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z";
    case TAB_ASN:
      return "M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3.75";
    case TAB_ONBOARDING:
      return "M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z";
    case TAB_HISTORY:
      return "M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z";
    case TAB_SETTINGS:
      return "M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z M15 12a3 3 0 11-6 0 3 3 0 016 0z";
    default:
      return "M4.5 6.75h15M4.5 12h15m-15 5.25h15";
  }
}

const activeTab = ref(TAB_ACCOUNT_INFO);

function tabFromRouteQuery(tab) {
  const t = String(tab || "").toLowerCase();
  if (t === TAB_FEES) return TAB_FEES;
  if (t === TAB_BILLING) return TAB_BILLING;
  if (t === TAB_ORDERS) return TAB_ORDERS;
  if (t === TAB_INVENTORY) return TAB_INVENTORY;
  if (t === TAB_ASN) return TAB_ASN;
  if (t === TAB_ONBOARDING) return TAB_ONBOARDING;
  if (t === TAB_HISTORY) return TAB_HISTORY;
  if (t === TAB_SETTINGS) return TAB_SETTINGS;
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

const noteDeleteModalOpen = computed(() => noteDeleteTarget.value !== null);

const noteDeleteMessage = computed(() => {
  const c = noteDeleteTarget.value;
  return c ? "Remove this note? This cannot be undone." : "";
});

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function accountStartDate(acc) {
  if (!acc) return null;
  return acc.contract_date || acc.created_at || null;
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
    return "badge bg-danger-subtle text-danger";
  }
  if (s === "inactive") {
    return "badge bg-secondary-subtle text-secondary";
  }
  return "badge bg-body-secondary text-body-secondary";
}

const storeCountDisplay = computed(() => stores.value.length);

const hasShipheroCustomerId = computed(() => {
  const id = account.value?.shiphero_customer_account_id;
  return id != null && String(id).trim() !== "";
});

const storesEmptyMessage = computed(() => {
  if (!hasShipheroCustomerId.value) {
    return "Set ShipHero customer account ID on this account to import stores.";
  }
  if (storesImportedAt.value) {
    return "No stores returned from ShipHero.";
  }
  return "No stores imported yet.";
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

function usersListRoute() {
  return {
    name: "client-users",
    query: { client_account_id: String(props.id) },
  };
}

function accountHistoryRoute() {
  return `/admin/clients/accounts/${props.id}/history`;
}

function historyItemBody(row) {
  if (row?.body) return row.body;
  if (row?.line) return row.line;
  const changes = row?.changes;
  if (Array.isArray(changes) && changes.length) {
    const labels = changes.map((c) => c?.label || c?.field).filter(Boolean);
    if (labels.length) {
      return `Updated ${labels.join(", ")}`;
    }
  }
  return "";
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

function toggleNoteMenu(commentId, e) {
  e.stopPropagation();
  noteMenuOpenId.value = noteMenuOpenId.value === commentId ? null : commentId;
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

  if (storeActionMenuRow.value !== null) {
    if (!(t instanceof Element && t.closest("[data-store-row-actions]"))) {
      closeStoreActionMenu();
    }
  }

  if (noteMenuOpenId.value === null) return;
  if (t instanceof Element && t.closest("[data-note-menu-root]")) return;
  closeNoteMenu();
}

function onWindowScrollOrResize() {
  closeNoteMenu();
  closeStoreActionMenu();
}

function openAccountEdit(section = "") {
  editAccountSection.value = section;
  editAccountOpen.value = true;
}

function onOnboardingAccountUpdated(payload) {
  if (!payload || typeof payload !== "object" || !account.value) return;
  account.value = {
    ...account.value,
    ...(payload.brand_logo_url ? { brand_logo_url: payload.brand_logo_url } : {}),
    ...(payload.notification_email !== undefined
      ? { notification_email: payload.notification_email }
      : {}),
    ...(payload.notify_email !== undefined ? { notify_email: payload.notify_email } : {}),
    ...(payload.whatsapp_e164 !== undefined ? { whatsapp_e164: payload.whatsapp_e164 } : {}),
    ...(payload.slack_channel !== undefined ? { slack_channel: payload.slack_channel } : {}),
  };
}

const accountBrandLogoUrl = computed(() => {
  const raw = account.value?.brand_logo_url;
  if (!raw) return "";

  return resolvePublicUrl(raw) || raw;
});

function openBrandLogoPicker() {
  if (!canUpdateAccount.value || brandLogoUploadBusy.value) return;
  brandLogoInput.value?.click();
}

async function onBrandLogoChange(e) {
  const input = e.target;
  const file = input.files?.[0];
  input.value = "";
  if (!file || !canUpdateAccount.value || !account.value) return;

  brandLogoUploadBusy.value = true;
  try {
    const fd = new FormData();
    fd.append("logo", file);
    const { data } = await api.post(
      `/client-accounts/${props.id}/onboarding/branding/logo`,
      fd,
    );
    const nextUrl = data?.brand_logo_url || data?.onboarding?.brand_logo_url;
    if (nextUrl) {
      onOnboardingAccountUpdated({ brand_logo_url: nextUrl });
    }
    toast.success("Brand logo updated.");
  } catch (err) {
    toast.errorFrom(err, "Could not upload brand logo.");
  } finally {
    brandLogoUploadBusy.value = false;
  }
}

function openAccountStatusModal() {
  if (!account.value || !canUpdateAccount.value) return;
  accountStatusForm.value = account.value.status || "pending";
  accountPauseReasonForm.value = String(account.value.pause_reason || "");
  accountStatusModalOpen.value = true;
}

async function saveAccountStatusFromModal() {
  if (!account.value || !canUpdateAccount.value) return;
  const next = accountStatusForm.value;
  const pauseReason = String(accountPauseReasonForm.value || "").trim();
  const prevStatus = String(account.value.status || "");
  const prevReason = String(account.value.pause_reason || "");
  const statusUnchanged = prevStatus === next;
  const reasonUnchanged = prevReason === pauseReason;
  if (statusUnchanged && reasonUnchanged) {
    accountStatusModalOpen.value = false;
    return;
  }
  if (next === "paused" && !pauseReason) {
    toast.error("Select a pause reason.");
    return;
  }
  if (next === "active") {
    const u = crmUser.value;
    const canBypassOnboarding = crmIsAdmin(u) || !!u?.is_crm_owner;
    if (!canBypassOnboarding) {
      const ready = await checkOnboardingReadyForActivation(api, props.id);
      if (!ready) {
        toast.error(ONBOARDING_ACTIVATION_BLOCKED_MESSAGE);
        return;
      }
    }
  }
  accountStatusSaving.value = true;
  try {
    const payload = { status: next };
    if (next === "paused") {
      payload.pause_reason = pauseReason;
    }
    const { data } = await api.patch(`/client-accounts/${props.id}`, payload);
    account.value = {
      ...account.value,
      status: data?.status ?? next,
      pause_reason: data?.pause_reason ?? (next === "paused" ? pauseReason : null),
      pause_reason_label: data?.pause_reason_label ?? null,
    };
    accountStatusModalOpen.value = false;
    toast.success("Account status updated.");
    warnIfShipheroSyncFailed(data, toast);
    await loadHistory();
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    accountStatusSaving.value = false;
  }
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
    if (Array.isArray(data?.statuses) && data.statuses.length) {
      accountStatuses.value = data.statuses;
    }
  } catch (e) {
    accountManagers.value = [];
    toast.errorFrom(e, "Could not load account manager list.");
  }
}

async function loadAccount({ quiet = false } = {}) {
  if (!quiet) {
    loading.value = true;
    errorMsg.value = "";
    account.value = null;
  }
  try {
    const { data } = await api.get(`/client-accounts/${props.id}`);
    account.value = data;
    if (quiet) {
      errorMsg.value = "";
    }
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
    const { data } = await api.get(`/client-accounts/${props.id}/shiphero-stores`);
    stores.value = Array.isArray(data?.stores) ? data.stores : [];
    storesImportedAt.value = data?.imported_at ?? null;
  } catch (e) {
    stores.value = [];
    storesImportedAt.value = null;
    if (e.response?.status !== 403) {
      toast.errorFrom(e, "Could not load stores.");
    }
  } finally {
    storesLoading.value = false;
  }
}

async function importStores() {
  if (!canImportStores.value || !props.id) return;
  if (!hasShipheroCustomerId.value) {
    toast.error("Set ShipHero customer account ID on this account first.");
    return;
  }
  storesImporting.value = true;
  try {
    const { data } = await api.post(`/client-accounts/${props.id}/shiphero-stores/import`);
    stores.value = Array.isArray(data?.stores) ? data.stores : [];
    storesImportedAt.value = data?.imported_at ?? null;
    const count = stores.value.length;
    if (count === 0) {
      toast.error("No stores returned from ShipHero for this account.");
    } else {
      toast.success(count === 1 ? "Imported 1 store." : `Imported ${count} stores.`);
    }
  } catch (e) {
    toast.errorFrom(e, "Could not import stores from ShipHero.");
  } finally {
    storesImporting.value = false;
  }
}

function closeStoreActionMenu() {
  storeActionMenuRow.value = null;
}

function toggleStoreActionMenu(row, event) {
  event?.stopPropagation?.();
  if (storeActionMenuRow.value === row) {
    closeStoreActionMenu();
    return;
  }
  const btn = event?.currentTarget;
  if (!btn?.getBoundingClientRect) return;
  const rect = btn.getBoundingClientRect();
  storeActionMenuRect.value = {
    top: Math.round(rect.bottom + 4),
    left: Math.round(Math.min(rect.right - 180, window.innerWidth - 196)),
  };
  storeActionMenuRow.value = row;
}

function openStoreEditType(row) {
  closeStoreActionMenu();
  if (!canUpdateStores.value) {
    toast.error("You don't have permission to update stores.");
    return;
  }
  storeEditTypeRow.value = row;
  storeEditTypeForm.value = {
    store_type: row?.store_type ? String(row.store_type) : "",
    shop_id: shipHeroStoreShopId(row),
  };
  storeEditTypeOpen.value = true;
}

function closeStoreEditType() {
  if (storeEditTypeBusy.value) return;
  storeEditTypeOpen.value = false;
  storeEditTypeRow.value = null;
}

async function saveStoreEditType() {
  const row = storeEditTypeRow.value;
  if (!row?.store_key || !props.id) return;
  if (!storeEditTypeForm.value.store_type) {
    toast.error("Select a store type.");
    return;
  }
  storeEditTypeBusy.value = true;
  try {
    const key = encodeURIComponent(String(row.store_key));
    const { data } = await api.patch(`/client-accounts/${props.id}/shiphero-stores/${key}`, {
      store_type: storeEditTypeForm.value.store_type || null,
      shop_id: String(storeEditTypeForm.value.shop_id || "").trim() || null,
    });
    stores.value = Array.isArray(data?.stores) ? data.stores : stores.value;
    storesImportedAt.value = data?.imported_at ?? storesImportedAt.value;
    toast.success("Store type updated.");
    storeEditTypeOpen.value = false;
    storeEditTypeRow.value = null;
  } catch (e) {
    toast.errorFrom(e, "Could not update store type.");
  } finally {
    storeEditTypeBusy.value = false;
  }
}

function openStoreInShipHero(row) {
  closeStoreActionMenu();
  const url = shipHeroStoreSettingsUrl(row);
  if (!url) {
    if (isShipHeroStoreApiType(row?.store_type)) {
      toast.error("Public API stores have no ShipHero settings link.");
      return;
    }
    toast.error("This store has no shop ID yet. Use Edit Type to set Shop ID.");
    return;
  }
  window.open(url, "_blank", "noopener,noreferrer");
}

function storeCanOpenShipHero(row) {
  return Boolean(shipHeroStoreSettingsUrl(row));
}

function storeTypeDisplay(row) {
  return shipHeroStoreTypeLabel(row?.store_type) || "—";
}

function openStoreDelete(row) {
  closeStoreActionMenu();
  if (!canDeleteStores.value) {
    toast.error("You don't have permission to delete stores.");
    return;
  }
  storeDeleteRow.value = row;
  storeDeleteOpen.value = true;
}

function closeStoreDelete() {
  if (storeDeleteBusy.value) return;
  storeDeleteOpen.value = false;
  storeDeleteRow.value = null;
}

async function confirmStoreDelete() {
  const row = storeDeleteRow.value;
  if (!row?.store_key || !props.id) return;
  storeDeleteBusy.value = true;
  try {
    const key = encodeURIComponent(String(row.store_key));
    const { data } = await api.delete(`/client-accounts/${props.id}/shiphero-stores/${key}`);
    stores.value = Array.isArray(data?.stores) ? data.stores : [];
    storesImportedAt.value = data?.imported_at ?? storesImportedAt.value;
    toast.success("Store removed from CRM. Import again to restore from ShipHero.");
    storeDeleteOpen.value = false;
    storeDeleteRow.value = null;
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
  <div class="staff-user-view staff-page--wide account-detail-page">
    <div class="account-detail-page-head">
      <nav
        class="staff-user-view__breadcrumb account-detail-page-head__breadcrumb d-flex flex-wrap align-items-center gap-1"
        aria-label="Breadcrumb"
      >
        <RouterLink to="/admin/home">Home</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <RouterLink to="/admin/clients/accounts">Accounts</RouterLink>
        <span class="text-secondary" aria-hidden="true">/</span>
        <span class="text-body-secondary">{{
          account?.company_name || "Account"
        }}</span>
      </nav>

      <div v-if="loading" class="d-flex justify-content-center py-4">
        <CrmLoadingSpinner message="Loading account…" />
      </div>

      <template v-else-if="errorMsg">
        <p class="text-danger small mb-2">
          {{ errorMsg }}
        </p>
        <RouterLink to="/admin/clients/accounts" class="small"
          >Back to accounts</RouterLink
        >
      </template>

      <div
        v-else-if="account"
        class="account-detail-header d-flex flex-row align-items-center gap-3"
      >
        <h1 class="staff-user-view__title account-detail-header__title mb-0 flex-shrink-0">
          {{ account.company_name }}
        </h1>
        <div
          class="staff-detail-tab-bar-wrap staff-detail-tab-bar-wrap--nav ms-lg-auto flex-grow-1"
          role="presentation"
        >
          <div class="staff-detail-tab-bar staff-detail-tab-bar--nav" role="tablist">
            <button
              v-for="t in accountTabList"
              :key="t.id"
              type="button"
              class="staff-detail-tab-btn"
              :class="{ 'staff-detail-tab-btn--active': activeTab === t.id }"
              role="tab"
              :aria-selected="activeTab === t.id"
              :title="t.label"
              @click="setActiveTab(t.id)"
            >
              <svg
                class="staff-detail-tab-btn__icon"
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  :d="accountTabIconPath(t.id)"
                />
              </svg>
              <span class="staff-detail-tab-btn__label">{{ t.label }}</span>
            </button>
          </div>
        </div>
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
    <CrmStatusUpdateModal
      v-if="canUpdateAccount"
      v-model:open="accountStatusModalOpen"
      v-model:status="accountStatusForm"
      v-model:reason="accountPauseReasonForm"
      title="Account status"
      subtitle="Choose the directory status for this client account."
      :statuses="accountStatuses"
      :reason-options="CLIENT_ACCOUNT_PAUSE_REASONS"
      :busy="accountStatusSaving"
      @save="saveAccountStatusFromModal"
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
    <ConfirmModal
      :open="storeEditTypeOpen"
      title="Edit Type"
      subtitle="Choose the ShipHero store type and shop ID for deep links."
      confirm-label="Save"
      cancel-label="Cancel"
      :busy="storeEditTypeBusy"
      :danger="false"
      form
      @close="closeStoreEditType"
      @confirm="saveStoreEditType"
    >
      <div class="text-start">
        <div class="mb-3">
          <label class="form-label small mb-1 text-secondary" for="store-edit-type">Type</label>
          <select
            id="store-edit-type"
            v-model="storeEditTypeForm.store_type"
            class="form-select"
            :disabled="storeEditTypeBusy"
            required
          >
            <option value="">Select type</option>
            <option
              v-for="opt in SHIPHERO_STORE_TYPE_OPTIONS"
              :key="opt.value"
              :value="opt.value"
            >
              {{ opt.label }}
            </option>
          </select>
        </div>
        <div>
          <label class="form-label small mb-1 text-secondary" for="store-edit-shop-id">Shop ID</label>
          <input
            id="store-edit-shop-id"
            v-model="storeEditTypeForm.shop_id"
            type="text"
            class="form-control"
            placeholder="31888"
            :disabled="storeEditTypeBusy"
            autocomplete="off"
          />
                          <p class="small text-secondary mb-0 mt-1">
                            Used in ShipHero settings URL as <code>?shop=</code>. Auto-filled from
                            ShipHero store id when available. Public API has no link.
                          </p>
        </div>
      </div>
    </ConfirmModal>
    <ConfirmModal
      :open="storeDeleteOpen"
      title="Delete Store"
      message="Remove this store from CRM only? Importing again will bring it back from ShipHero."
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="storeDeleteBusy"
      danger
      @close="closeStoreDelete"
      @confirm="confirmStoreDelete"
    />

    <template v-if="!loading && !errorMsg && account">
      <div class="row g-3">
        <div class="col-12 col-xl-4">
          <aside class="staff-user-profile">
            <input
              ref="brandLogoInput"
              type="file"
              accept="image/jpeg,image/png,image/jpg,image/webp,.jpg,.jpeg,.png,.webp"
              class="d-none"
              @change="onBrandLogoChange"
            />
            <div class="staff-user-profile__avatar-wrap">
              <button
                v-if="canUpdateAccount"
                type="button"
                class="staff-user-profile__avatar-btn rounded focus-ring"
                :title="accountBrandLogoUrl ? 'Change brand logo' : 'Upload brand logo'"
                :disabled="brandLogoUploadBusy"
                @click="openBrandLogoPicker"
              >
                <img
                  v-if="accountBrandLogoUrl"
                  :src="accountBrandLogoUrl"
                  alt=""
                  class="staff-user-profile__avatar staff-user-profile__avatar--brand-logo"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(account.email)"
                >
                  {{ initials(account.company_name) }}
                </span>
              </button>
              <template v-else>
                <img
                  v-if="accountBrandLogoUrl"
                  :src="accountBrandLogoUrl"
                  alt=""
                  class="staff-user-profile__avatar staff-user-profile__avatar--brand-logo"
                />
                <span
                  v-else
                  class="staff-user-profile__avatar staff-user-profile__avatar--initials"
                  :class="avatarClassForEmail(account.email)"
                >
                  {{ initials(account.company_name) }}
                </span>
              </template>
            </div>
            <h2 class="staff-user-profile__name">
              {{ account.company_name }}
            </h2>
            <div class="text-center mb-3">
              <button
                v-if="canUpdateAccount"
                type="button"
                class="staff-status-badge text-capitalize"
                :class="accountStatusBadgeClass(account.status)"
                title="Change account status"
                @click="openAccountStatusModal"
              >
                {{ account.status }}
              </button>
              <span
                v-else
                class="text-capitalize"
                :class="accountStatusBadgeClass(account.status)"
              >{{ account.status }}</span>
              <div
                v-if="String(account.status || '').toLowerCase() === 'paused' && account.pause_reason_label"
                class="small text-secondary mt-2"
              >
                Reason: {{ account.pause_reason_label }}
              </div>
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
                <RouterLink
                  :to="usersListRoute()"
                  class="staff-user-profile__stat-link text-decoration-none text-body"
                >
                  <div class="staff-user-profile__stat-val">
                    {{ nf.format(usersCountDisplay) }}
                  </div>
                  <div class="staff-user-profile__stat-lbl">Users</div>
                </RouterLink>
              </div>
            </div>

            <AccountDetailSectionHead
              title="Details"
              icon="details"
              icon-style="plain"
              title-class="staff-user-profile__details-title mb-0"
              head-class="mb-2"
              :show-edit="canUpdateAccount"
              @edit="openAccountEdit('left')"
            />

            <dl class="staff-user-profile__dl mb-4">
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
                      :notification-email="account.notification_email || ''"
                      :telegram-handle="account.telegram_handle || ''"
                      :whatsapp-e164="account.whatsapp_e164 || ''"
                      :slack-channel="account.slack_channel || ''"
                    />
                  </div>
                </dd>
              </div>
              <div>
                <dt class="staff-user-profile__dt">In-House Slack</dt>
                <dd class="staff-user-profile__dd text-end text-break">
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
              <div>
                <dt class="staff-user-profile__dt">Start Date</dt>
                <dd class="staff-user-profile__dd text-end">
                  {{ formatDateUs(accountStartDate(account)) }}
                </dd>
              </div>
            </dl>

            <section class="staff-user-profile__activity" aria-labelledby="sidebar-activity-heading">
              <AccountDetailSectionHead
                title="Account activity"
                icon="activity"
                title-class="staff-user-profile__details-title mb-0"
                head-class="mb-2"
                heading-id="sidebar-activity-heading"
              >
                <template #actions>
                  <RouterLink
                    :to="accountHistoryRoute()"
                    class="small link-primary text-decoration-none"
                  >
                    View All
                  </RouterLink>
                </template>
              </AccountDetailSectionHead>
              <div v-if="timelinePreview.length" class="staff-user-timeline staff-user-timeline--sidebar">
                <div
                  v-for="row in timelinePreview"
                  :key="row.id"
                  class="staff-user-timeline__item"
                >
                  <img
                    v-if="timelineActorAvatarUrl(row)"
                    :src="timelineActorAvatarUrl(row)"
                    alt=""
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 object-fit-cover"
                    width="28"
                    height="28"
                  />
                  <span
                    v-else
                    class="staff-user-timeline__avatar-img rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
                    style="width: 28px; height: 28px; font-size: 0.625rem"
                    :class="avatarClassForTimelineActor(row.actor_name)"
                    aria-hidden="true"
                  >{{ row.actor_initials || "?" }}</span>
                  <div class="staff-user-timeline__content min-w-0 flex-grow-1">
                    <div class="staff-user-timeline__row">
                      <h4 class="staff-user-timeline__heading small mb-0">
                        {{ row.actor_name || "System" }}
                      </h4>
                      <time class="staff-user-timeline__time" :datetime="row.created_at">{{
                        formatDateTimeUs(row.created_at)
                      }}</time>
                    </div>
                    <p class="staff-user-timeline__body small mb-0">
                      {{ historyItemBody(row) }}
                    </p>
                  </div>
                </div>
              </div>
              <p v-else class="staff-user-timeline__empty small mb-0">
                No activity logged yet.
              </p>
            </section>
          </aside>
        </div>

        <div class="col-12 col-xl-8">
        <div
          class="staff-user-tab-panel"
          role="tabpanel"
          :aria-label="accountTabList.find((x) => x.id === activeTab)?.label"
        >
          <template v-if="activeTab === TAB_ACCOUNT_INFO">
            <div class="staff-surface p-3 p-md-4 mb-4">
              <AccountDetailSectionHead
                title="Personal information"
                icon="personal"
                head-class="mb-3"
                :show-edit="canUpdateAccount"
                @edit="openAccountEdit('account')"
              />
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
            </div>

            <div class="staff-surface p-3 p-md-4 mb-4">
              <AccountDetailSectionHead
                title="Address"
                icon="address"
                head-class="mb-3"
                :show-edit="canUpdateAccount"
                @edit="openAccountEdit('address')"
              />
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
                <AccountDetailSectionHead title="Notes" icon="notes" />
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
                    <div
                      class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0 w-100 justify-content-end"
                    >
                      <button
                        v-if="canImportStores"
                        type="button"
                        class="btn btn-primary staff-page-primary staff-toolbar-btn fw-semibold"
                        :disabled="storesLoading || storesImporting || !hasShipheroCustomerId"
                        @click="importStores"
                      >
                        {{ storesImporting ? "Importing…" : "Import Stores" }}
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
                        <th class="staff-table-head__th" scope="col">Name</th>
                        <th class="staff-table-head__th" scope="col">Type</th>
                        <th
                          class="staff-table-head__th staff-actions-col text-center client-account-stores-actions-col"
                          scope="col"
                        >
                          Action
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!stores.length">
                        <td colspan="3" class="px-4 py-5 text-center text-secondary">
                          {{ storesEmptyMessage }}
                        </td>
                      </tr>
                      <tr
                        v-for="row in stores"
                        v-else
                        :key="row.store_key || row.shiphero_id || row.legacy_id || row.shop_name"
                        class="align-middle"
                      >
                        <td>
                          <div class="d-flex align-items-center gap-3 min-w-0">
                            <span
                              class="flex-shrink-0 rounded-circle d-inline-flex align-items-center justify-content-center small fw-semibold"
                              style="width: 2.25rem; height: 2.25rem"
                              :class="avatarClassForEmail(row.shop_name)"
                            >
                              {{ initials(row.shop_name) }}
                            </span>
                            <a
                              v-if="storeCanOpenShipHero(row)"
                              :href="shipHeroStoreSettingsUrl(row)"
                              class="d-block fw-semibold text-truncate text-decoration-none text-primary"
                              target="_blank"
                              rel="noopener noreferrer"
                            >
                              {{ row.shop_name || "—" }}
                            </a>
                            <span v-else class="d-block fw-semibold text-body text-truncate">{{
                              row.shop_name || "—"
                            }}</span>
                          </div>
                        </td>
                        <td class="text-body staff-table-cell__meta">
                          {{ storeTypeDisplay(row) }}
                        </td>
                        <td class="staff-actions-cell text-center client-account-stores-actions-cell" @click.stop>
                          <div data-store-row-actions class="staff-actions-inner staff-actions-inner--single">
                            <button
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :aria-expanded="storeActionMenuRow === row"
                              aria-label="Row Actions"
                              @click="toggleStoreActionMenu(row, $event)"
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
                  v-if="storesImportedAt && !storesLoading"
                  class="small text-secondary px-3 px-md-4 py-3 mb-0 border-top"
                >
                  Last imported {{ formatDateTimeUs(storesImportedAt) }}.
                </p>
              </div>
          </template>

          <template v-else-if="activeTab === TAB_FEES">
            <div class="p-3 p-md-4">
              <ClientAccountFeesPanel
                :account="account"
                :account-id="String(props.id)"
                :can-edit="canUpdateAccount"
                @fees-updated="onAccountFeesUpdated"
              />
            </div>
          </template>

          <template v-else-if="activeTab === TAB_BILLING">
            <ClientAccountBillingPanel
              :account="account"
              :account-id="id"
              :can-edit="canUpdateAccount"
              @edit="openAccountEdit('billing')"
            />
          </template>

          <template v-else-if="activeTab === TAB_ORDERS">
            <ClientAccountOrdersPanel :account-id="id" />
          </template>

          <template v-else-if="activeTab === TAB_INVENTORY">
            <ClientAccountInventoryPanel :account-id="id" />
          </template>

          <template v-else-if="activeTab === TAB_ASN">
            <ClientAccountAsnPanel :account-id="id" />
          </template>

          <template v-else-if="activeTab === TAB_ONBOARDING">
            <div class="staff-surface p-3 p-md-4">
              <AccountDetailSectionHead
                title="Onboarding"
                icon="onboarding"
                head-class="mb-3"
              />
              <ClientAccountOnboardingPanel
                :client-account-id="account.id"
                :can-edit="canUpdateAccount"
                @account-updated="onOnboardingAccountUpdated"
              />
            </div>
          </template>

          <template v-else-if="activeTab === TAB_HISTORY">
            <div v-if="historyItems.length" class="staff-user-timeline staff-user-timeline--flat">
              <div
                v-for="row in historyItems"
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
                  aria-hidden="true"
                >{{ row.actor_initials || "?" }}</span>
                <div class="staff-user-timeline__content min-w-0 flex-grow-1">
                  <div class="staff-user-timeline__row">
                    <h3 class="staff-user-timeline__heading">
                      {{ row.actor_name || "System" }}
                    </h3>
                    <time class="staff-user-timeline__time" :datetime="row.created_at">{{
                      formatDateTimeUs(row.created_at)
                    }}</time>
                  </div>
                  <p class="staff-user-timeline__body">{{ historyItemBody(row) }}</p>
                </div>
              </div>
            </div>
            <p v-else class="staff-user-timeline__empty mb-0">No activity logged yet.</p>
            <div class="mt-3 pt-3 border-top">
              <RouterLink
                :to="accountHistoryRoute()"
                class="btn btn-sm btn-outline-primary"
              >
                View Full History
              </RouterLink>
            </div>
          </template>

          <template v-else-if="activeTab === TAB_SETTINGS">
            <div class="staff-surface p-3 p-md-4">
              <AccountDetailSectionHead
                title="Settings"
                icon="settings"
                head-class="mb-3"
                :show-edit="canUpdateAccount"
                @edit="openAccountEdit('settings')"
              />
              <div class="row g-3">
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      WhatsApp API ID
                    </dt>
                    <dd class="mb-0 fw-semibold text-body text-break">
                      {{ display(account.whatsapp_api_id) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-md-6">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      ShipHero customer account ID
                    </dt>
                    <dd class="mb-0 fw-semibold text-body text-break">
                      {{ display(account.shiphero_customer_account_id) }}
                    </dd>
                  </dl>
                </div>
                <div class="col-12">
                  <dl class="mb-0 small">
                    <dt
                      class="text-secondary text-uppercase fw-semibold mb-1"
                      style="font-size: 0.65rem"
                    >
                      Terms and Conditions
                    </dt>
                    <dd class="mb-0">
                      <RouterLink
                        :to="{
                          name: 'client-account-terms',
                          params: { id: String(account.id) },
                        }"
                        class="link-primary text-decoration-none fw-semibold"
                      >
                        Terms and Conditions
                      </RouterLink>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </template>
        </div>
        </div>
      </div>

    </template>

    <Teleport to="body">
      <div
        v-if="storeActionMenuRow"
        data-store-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${storeActionMenuRect.top}px`, left: `${storeActionMenuRect.left}px` }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="!canUpdateStores"
          @click="openStoreEditType(storeActionMenuRow)"
        >
          Edit Type
        </button>
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="!storeCanOpenShipHero(storeActionMenuRow)"
          @click="openStoreInShipHero(storeActionMenuRow)"
        >
          Edit Store
        </button>
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          :disabled="!canDeleteStores"
          @click="openStoreDelete(storeActionMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>
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

.staff-user-profile__stat-link {
  display: block;
  text-align: center;
}

.staff-user-profile__stat-link:hover .staff-user-profile__stat-lbl {
  color: #2563eb;
}

.account-detail-page-head {
  margin-bottom: 1rem;
}

.account-detail-header {
  min-width: 0;
  margin-top: 0;
}
</style>
