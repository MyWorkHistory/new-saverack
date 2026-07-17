<script setup>
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  ref,
} from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import BillingCustomBillLineModal from "../../components/billing/BillingCustomBillLineModal.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLinkedText from "../../components/common/CrmLinkedText.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmNoteAuthorAvatar from "../../components/common/CrmNoteAuthorAvatar.vue";
import ProjectEditModal from "../../components/projects/ProjectEditModal.vue";
import ProjectStatusChip from "../../components/projects/ProjectStatusChip.vue";
import {
  DEFAULT_INVOICE_CATEGORY,
  INVOICE_CATEGORY_OPTIONS,
  invoiceCategoryLabel,
} from "../../constants/invoiceCategoryOptions.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { noteAuthorFromRecord } from "../../utils/noteAuthor.js";

const props = defineProps({
  id: { type: [String, Number], required: true },
});

const crmUser = inject("crmUser", ref(null));
const router = useRouter();
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("projects.update"));
const canDelete = computed(() => userHasPerm("projects.delete"));

const loading = ref(true);
const project = ref(null);
const statusBusy = ref(false);

const noteBody = ref("");
const noteBusy = ref(false);
const notesExpanded = ref(false);
const NOTES_PREVIEW_LIMIT = 3;

const projectNotes = computed(() =>
  Array.isArray(project.value?.notes) ? project.value.notes : [],
);

const visibleProjectNotes = computed(() => {
  const list = projectNotes.value;
  if (notesExpanded.value || list.length <= NOTES_PREVIEW_LIMIT) {
    return list;
  }
  return list.slice(0, NOTES_PREVIEW_LIMIT);
});

const showSeeAllNotes = computed(
  () => !notesExpanded.value && projectNotes.value.length > NOTES_PREVIEW_LIMIT,
);

function noteAuthor(note) {
  return noteAuthorFromRecord(note);
}
const deleteNoteOpen = ref(false);
const deleteNoteBusy = ref(false);
const deleteNoteTarget = ref(null);

const editOpen = ref(false);
const editBusy = ref(false);
const editError = ref("");

const quoteOpen = ref(false);
const quoteBusy = ref(false);
const quoteError = ref("");
const quoteCategory = ref(DEFAULT_INVOICE_CATEGORY);
const quoteName = ref("");
const quoteQty = ref("1");
const quotePrice = ref("0.00");
const quoteSku = ref("");
const quoteEditId = ref(null);

const quoteMenuOpenId = ref(null);
const quoteMenuRect = ref({ top: 0, left: 0 });
const QUOTE_MENU_W = 160;
const QUOTE_MENU_H = 88;

const deleteItemOpen = ref(false);
const deleteItemBusy = ref(false);
const deleteItemTarget = ref(null);

const createBillBusy = ref(false);

const deleteProjectOpen = ref(false);
const deleteProjectBusy = ref(false);

const actionsMenuOpen = ref(false);
const actionsMenuRect = ref({ top: 0, left: 0 });
const ACTIONS_MENU_W = 180;
const ACTIONS_MENU_H = 104;

const quoteModalTitle = computed(() =>
  quoteEditId.value ? "Edit Quote Line" : "Add To Quote",
);

const money = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

const categoryOptions = computed(() => {
  const fromApi = project.value?.category_options;
  if (Array.isArray(fromApi) && fromApi.length) return fromApi;
  return INVOICE_CATEGORY_OPTIONS;
});

const quoteItems = computed(() =>
  Array.isArray(project.value?.quote_items) ? project.value.quote_items : [],
);

const hasBill = computed(() => Boolean(project.value?.custom_bill_id));

const canShowActionsMenu = computed(
  () => Boolean(project.value) && (canUpdate.value || canDelete.value),
);

function cents(n) {
  return money.format((Number(n) || 0) / 100);
}

const timeFmt = new Intl.DateTimeFormat("en-US", {
  hour: "numeric",
  minute: "2-digit",
});

function timeUs(val) {
  if (!val) return "";
  const d = new Date(val);
  if (Number.isNaN(d.getTime())) return "";
  return timeFmt.format(d);
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/projects/${props.id}`);
    project.value = data;
    setCrmPageMeta({
      title: `Save Rack | ${data.pid || "Project"}`,
      description: data.name || "Project detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load project.");
    project.value = null;
  } finally {
    loading.value = false;
  }
}

async function changeStatus(status) {
  if (!project.value?.id || statusBusy.value || !canUpdate.value) return;
  statusBusy.value = true;
  try {
    const { data } = await api.patch(`/projects/${project.value.id}/status`, { status });
    project.value = data;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusBusy.value = false;
  }
}

async function addNote() {
  const body = String(noteBody.value || "").trim();
  if (!body || noteBusy.value || !canUpdate.value) return;
  noteBusy.value = true;
  try {
    const { data } = await api.post(`/projects/${project.value.id}/notes`, { body });
    project.value.notes = [data, ...(project.value.notes || [])];
    noteBody.value = "";
    toast.success("Note added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add note.");
  } finally {
    noteBusy.value = false;
  }
}

function askDeleteNote(note) {
  deleteNoteTarget.value = note;
  deleteNoteOpen.value = true;
}

async function confirmDeleteNote() {
  if (!deleteNoteTarget.value?.id) return;
  deleteNoteBusy.value = true;
  try {
    await api.delete(`/projects/${project.value.id}/notes/${deleteNoteTarget.value.id}`);
    project.value.notes = (project.value.notes || []).filter(
      (n) => n.id !== deleteNoteTarget.value.id,
    );
    deleteNoteOpen.value = false;
    deleteNoteTarget.value = null;
    toast.success("Note deleted.");
  } catch (e) {
    toast.errorFrom(e, "Could not delete note.");
  } finally {
    deleteNoteBusy.value = false;
  }
}

function openAddQuote() {
  quoteEditId.value = null;
  quoteCategory.value = DEFAULT_INVOICE_CATEGORY;
  quoteName.value = "";
  quoteQty.value = "1";
  quotePrice.value = "0.00";
  quoteSku.value = "";
  quoteError.value = "";
  quoteOpen.value = true;
}

function openEditQuote(item) {
  quoteMenuOpenId.value = null;
  if (!item) return;
  quoteEditId.value = item.id;
  quoteCategory.value = item.line_type || DEFAULT_INVOICE_CATEGORY;
  quoteName.value = item.name || "";
  quoteQty.value = String(item.quantity ?? "1");
  quotePrice.value = (Math.abs(Number(item.unit_price_cents) || 0) / 100).toFixed(2);
  quoteSku.value = item.sku || "";
  quoteError.value = "";
  quoteOpen.value = true;
}

async function submitQuote() {
  if (quoteBusy.value) return;
  const name = String(quoteName.value || "").trim();
  if (!name) {
    quoteError.value = "Service / Name is required.";
    return;
  }
  quoteBusy.value = true;
  quoteError.value = "";
  const payload = {
    line_type: quoteCategory.value,
    name,
    quantity: Number(quoteQty.value) || 1,
    unit_price: Number(quotePrice.value) || 0,
    sku: String(quoteSku.value || "").trim() || null,
  };
  try {
    const { data } = quoteEditId.value
      ? await api.put(
          `/projects/${project.value.id}/quote-items/${quoteEditId.value}`,
          payload,
        )
      : await api.post(`/projects/${project.value.id}/quote-items`, payload);
    project.value = data;
    quoteOpen.value = false;
    toast.success(quoteEditId.value ? "Quote line updated." : "Added to quote.");
    quoteEditId.value = null;
  } catch (e) {
    quoteError.value = quoteEditId.value
      ? "Could not update quote line."
      : "Could not add quote line.";
    toast.errorFrom(e, quoteError.value);
  } finally {
    quoteBusy.value = false;
  }
}

async function toggleQuoteMenu(itemId, event) {
  event?.stopPropagation?.();
  if (quoteMenuOpenId.value === itemId) {
    quoteMenuOpenId.value = null;
    return;
  }
  const btn = event?.currentTarget;
  quoteMenuOpenId.value = itemId;
  await nextTick();
  requestAnimationFrame(() => placeQuoteMenu(btn));
}

function placeQuoteMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  let top = rect.bottom + 4;
  let left = rect.right - QUOTE_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - QUOTE_MENU_W - 8));
  if (top + QUOTE_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, rect.top - QUOTE_MENU_H - 4);
  }
  quoteMenuRect.value = { top, left };
}

function askDeleteItemFromMenu(item) {
  quoteMenuOpenId.value = null;
  askDeleteItem(item);
}

function askDeleteItem(item) {
  deleteItemTarget.value = item;
  deleteItemOpen.value = true;
}

async function confirmDeleteItem() {
  if (!deleteItemTarget.value?.id) return;
  deleteItemBusy.value = true;
  try {
    const { data } = await api.delete(
      `/projects/${project.value.id}/quote-items/${deleteItemTarget.value.id}`,
    );
    project.value = data;
    deleteItemOpen.value = false;
    deleteItemTarget.value = null;
    toast.success("Line removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove line.");
  } finally {
    deleteItemBusy.value = false;
  }
}

async function createBill() {
  if (!project.value?.id || createBillBusy.value || hasBill.value || !canUpdate.value) return;
  createBillBusy.value = true;
  try {
    const { data } = await api.post(`/projects/${project.value.id}/create-bill`);
    project.value = data;
    toast.success("Bill created.");
  } catch (e) {
    toast.errorFrom(e, "Could not create bill.");
  } finally {
    createBillBusy.value = false;
  }
}

function placeActionsMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  let top = rect.bottom + 4;
  let left = rect.right - ACTIONS_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - ACTIONS_MENU_W - 8));
  if (top + ACTIONS_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, rect.top - ACTIONS_MENU_H - 4);
  }
  actionsMenuRect.value = { top, left };
}

async function toggleActionsMenu(event) {
  event?.stopPropagation?.();
  if (actionsMenuOpen.value) {
    actionsMenuOpen.value = false;
    return;
  }
  const btn = event?.currentTarget;
  actionsMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    placeActionsMenu(btn);
  });
}

function closeActionsMenu() {
  actionsMenuOpen.value = false;
}

function openEditFromMenu() {
  closeActionsMenu();
  editError.value = "";
  editOpen.value = true;
}

async function submitEditProject(payload) {
  if (!project.value?.id || editBusy.value || !canUpdate.value) return;
  const name = String(payload?.name || "").trim();
  if (!name) {
    editError.value = "Project name is required.";
    return;
  }
  editBusy.value = true;
  editError.value = "";
  try {
    const { data } = await api.patch(`/projects/${project.value.id}`, {
      name,
      description: payload?.description ?? null,
    });
    project.value = data;
    editOpen.value = false;
    toast.success("Project updated.");
  } catch (e) {
    editError.value = "Could not update project.";
    toast.errorFrom(e, "Could not update project.");
  } finally {
    editBusy.value = false;
  }
}

function openDeleteFromMenu() {
  closeActionsMenu();
  deleteProjectOpen.value = true;
}

async function confirmDeleteProject() {
  if (!project.value?.id || !canDelete.value) return;
  deleteProjectBusy.value = true;
  try {
    await api.delete(`/projects/${project.value.id}`);
    toast.success("Project deleted.");
    router.push({ name: "projects" });
  } catch (e) {
    toast.errorFrom(e, "Could not delete project.");
  } finally {
    deleteProjectBusy.value = false;
    deleteProjectOpen.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-project-actions]")) {
    actionsMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-quote-actions]")) {
    quoteMenuOpenId.value = null;
  }
}

function onWindowScrollOrResize() {
  actionsMenuOpen.value = false;
  quoteMenuOpenId.value = null;
}

function onDocKeydown(e) {
  if (e.key === "Escape") {
    actionsMenuOpen.value = false;
    quoteMenuOpenId.value = null;
  }
}

onMounted(() => {
  load();
  document.addEventListener("click", onDocClick);
  document.addEventListener("keydown", onDocKeydown);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  document.removeEventListener("keydown", onDocKeydown);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
});
</script>

<template>
  <div class="staff-user-view staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/home">Home</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">Clients</span>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/clients/projects">Projects</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span
        class="text-body-secondary text-truncate"
        style="max-width: 14rem"
        >{{ project?.name || project?.pid || "Project" }}</span
      >
    </nav>

    <div
      class="staff-user-view__title-row d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4"
    >
      <div class="min-w-0">
        <h1 class="staff-user-view__title">
          Project - {{ project?.pid || "…" }}
        </h1>
      </div>
      <div
        v-if="canShowActionsMenu"
        class="staff-detail-tab-bar-actions"
        data-project-actions
      >
        <button
          type="button"
          class="staff-detail-tab-btn"
          :class="{ 'staff-detail-tab-btn--active': actionsMenuOpen }"
          :aria-expanded="actionsMenuOpen"
          aria-label="Actions"
          @click="toggleActionsMenu"
        >
          <svg
            class="staff-detail-tab-btn__icon"
            width="26"
            height="26"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
            />
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
            />
          </svg>
          <span class="staff-detail-tab-btn__label">Actions</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading project…" />
    </div>

    <div v-else-if="!project" class="text-secondary">
      Project not found.
      <RouterLink to="/admin/clients/projects" class="d-block small mt-2"
        >Back to projects</RouterLink
      >
    </div>

    <div v-else class="row g-4">
      <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <h2 class="h5 fw-semibold text-body mb-0">{{ project.name }}</h2>
          </div>
          <section class="p-4 p-md-5">
            <h3 class="small fw-semibold text-secondary text-uppercase mb-2">
              Description
            </h3>
            <CrmLinkedText
              v-if="project.description"
              :text="project.description"
              class="whitespace-pre-wrap"
            />
            <p v-else class="text-secondary small mb-0">No description.</p>
          </section>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <h3 class="h6 fw-semibold text-body mb-0">Notes</h3>
              <div class="d-flex align-items-center gap-3">
                <button
                  v-if="showSeeAllNotes"
                  type="button"
                  class="btn btn-link btn-sm p-0 text-decoration-none"
                  @click="notesExpanded = true"
                >
                  See All Notes
                </button>
                <span class="small text-secondary">Internal only</span>
              </div>
            </div>
          </div>
          <div class="p-4 p-md-5">
            <div v-if="canUpdate" class="mb-3">
              <textarea
                v-model="noteBody"
                class="form-control mb-2"
                rows="3"
                placeholder="Add an internal note…"
                :disabled="noteBusy"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary btn-sm"
                :disabled="noteBusy || !String(noteBody || '').trim()"
                @click="addNote"
              >
                <CrmLoadingSpinner v-if="noteBusy" small class="me-1" />
                Add Note
              </button>
            </div>
            <div v-if="!projectNotes.length" class="text-secondary small">
              No notes yet.
            </div>
            <ul class="list-unstyled mb-0">
              <li
                v-for="note in visibleProjectNotes"
                :key="note.id"
                class="d-flex gap-3 border rounded p-3 mb-2"
              >
                <CrmNoteAuthorAvatar
                  :name="noteAuthor(note).name"
                  :email="noteAuthor(note).email"
                  :avatar-url="noteAuthor(note).avatarUrl"
                />
                <div class="min-w-0 flex-grow-1">
                  <div class="d-flex justify-content-between gap-2">
                    <div class="small text-secondary">
                      {{ noteAuthor(note).name }}
                      ·
                      {{ formatDateUs(note.created_at) }}
                    </div>
                    <button
                      v-if="canUpdate"
                      type="button"
                      class="btn btn-link btn-sm text-danger p-0"
                      @click="askDeleteNote(note)"
                    >
                      Delete
                    </button>
                  </div>
                  <div class="mt-1" style="white-space: pre-wrap">{{ note.body }}</div>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <aside class="col-12 col-lg-4 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden project-detail-card">
          <div class="project-detail-card__head">
            <span class="project-detail-card__head-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
              </svg>
            </span>
            <div class="min-w-0">
              <h3 class="project-detail-card__title mb-0">Details</h3>
              <p class="project-detail-card__subtitle mb-0">Overview of this project</p>
            </div>
          </div>
          <div class="project-detail-grid">
            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Account</p>
                <p class="project-detail-field__value">{{ project.client_account_name || "—" }}</p>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M3 6l6.5-3.5a2 2 0 011.9 0L21 8v10a1 1 0 01-1 1h-6v-4a2 2 0 10-4 0v4H4a1 1 0 01-1-1z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Status</p>
                <div class="project-detail-field__value">
                  <ProjectStatusChip
                    :status="project.status"
                    :disabled="statusBusy || !canUpdate"
                    @change="changeStatus"
                  />
                </div>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Date Created</p>
                <p class="project-detail-field__value">{{ formatDateUs(project.created_at) || "—" }}</p>
                <p v-if="timeUs(project.created_at)" class="project-detail-field__sub">
                  {{ timeUs(project.created_at) }}
                </p>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 21a9 9 0 110-18 9 9 0 010 18z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Date Completed</p>
                <p class="project-detail-field__value">{{ formatDateUs(project.completed_at) || "—" }}</p>
                <p v-if="!project.completed_at" class="project-detail-field__sub">Not completed</p>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Created By</p>
                <p class="project-detail-field__value">{{ project.created_by_name || "—" }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 border-bottom">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
              <h3 class="small fw-semibold text-secondary text-uppercase mb-0">Quote</h3>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-primary staff-page-primary btn-sm"
                :disabled="!project.quote_open"
                @click="openAddQuote"
              >
                Add To Quote
              </button>
            </div>
          </div>
          <div class="p-4">
            <p v-if="hasBill && !project.quote_open" class="small text-secondary mb-3">
              Quote is locked because the custom bill is invoiced.
            </p>
            <div v-if="!quoteItems.length" class="text-secondary small py-2">
              No quote lines yet. Use Add To Quote to enter lines here.
            </div>
            <div v-else class="table-responsive">
              <table class="table table-sm staff-data-table mb-0 align-middle">
                <thead>
                  <tr>
                    <th scope="col">Item</th>
                    <th scope="col" class="text-end">Qty</th>
                    <th scope="col" class="text-end">Price</th>
                    <th scope="col" class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in quoteItems" :key="item.id">
                    <td>
                      <div class="fw-semibold small">{{ item.name }}</div>
                      <div class="small text-secondary">
                        {{ invoiceCategoryLabel(item.line_type) }}
                        <span v-if="item.sku"> · {{ item.sku }}</span>
                      </div>
                    </td>
                    <td class="text-end small">{{ item.quantity }}</td>
                    <td class="text-end small">{{ cents(item.line_total_cents) }}</td>
                    <td class="text-end" @click.stop>
                      <div
                        v-if="canUpdate && project.quote_open"
                        data-quote-actions
                        class="position-relative d-inline-block"
                      >
                        <button
                          type="button"
                          class="staff-action-btn staff-action-btn--more"
                          :class="{ 'is-open': quoteMenuOpenId === item.id }"
                          :aria-expanded="quoteMenuOpenId === item.id ? 'true' : 'false'"
                          aria-haspopup="true"
                          aria-label="Quote line actions"
                          @click.stop="toggleQuoteMenu(item.id, $event)"
                        >
                          <CrmIconRowActions variant="horizontal" />
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <th colspan="2" class="text-end small">Total</th>
                    <th class="text-end small">{{ cents(project.quote_total_cents) }}</th>
                    <th></th>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div
              v-if="canUpdate && !hasBill"
              class="d-flex justify-content-end mt-3 pt-3 border-top"
            >
              <button
                type="button"
                class="btn btn-primary staff-page-primary btn-sm"
                :disabled="createBillBusy || !quoteItems.length"
                @click="createBill"
              >
                <CrmLoadingSpinner v-if="createBillBusy" small class="me-1" />
                Create Bill
              </button>
            </div>
          </div>
        </div>

        <div
          v-if="hasBill"
          class="staff-table-card overflow-hidden project-detail-card"
        >
          <div class="project-detail-card__head">
            <span class="project-detail-card__head-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 14h6m-6-4h6m-8 8V5a1 1 0 011-1h8a1 1 0 011 1v13l-2-1.5L14 18l-2-1.5L10 18l-2-1.5L6 18z" />
              </svg>
            </span>
            <div class="min-w-0">
              <h3 class="project-detail-card__title mb-0">Project Bill</h3>
              <p class="project-detail-card__subtitle mb-0">Billing details for this project</p>
            </div>
          </div>
          <div class="project-detail-grid project-detail-grid--single">
            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2zM14 3v5h5" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Bill</p>
                <p class="project-detail-field__value">
                  <RouterLink
                    class="text-decoration-none"
                    :to="`/admin/billing/bills/${project.custom_bill_id}`"
                  >
                    {{
                      project.custom_bill_name ||
                      (project.custom_bill_number
                        ? `Bill #${project.custom_bill_number}`
                        : "View Bill")
                    }}
                  </RouterLink>
                </p>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M3 6l6.5-3.5a2 2 0 011.9 0L21 8v10a1 1 0 01-1 1h-6v-4a2 2 0 10-4 0v4H4a1 1 0 01-1-1z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Status</p>
                <p class="project-detail-field__value text-capitalize">
                  {{ project.custom_bill_status || "—" }}
                </p>
              </div>
            </div>

            <div class="project-detail-field">
              <span class="project-detail-field__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M8 8a3 3 0 013-3h2a3 3 0 010 6h-2a3 3 0 000 6h2a3 3 0 003-3" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="project-detail-field__label">Total</p>
                <p class="project-detail-field__value">{{ cents(project.quote_total_cents) }}</p>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <Teleport to="body">
      <div
        v-if="actionsMenuOpen"
        data-project-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${actionsMenuRect.top}px`,
          left: `${actionsMenuRect.left}px`,
          minWidth: `${ACTIONS_MENU_W}px`,
        }"
        @click.stop
      >
        <button
          v-if="canUpdate"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditFromMenu"
        >
          Edit Project
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteFromMenu"
        >
          Delete Project
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="quoteMenuOpenId !== null"
        data-quote-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${quoteMenuRect.top}px`,
          left: `${quoteMenuRect.left}px`,
          minWidth: `${QUOTE_MENU_W}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditQuote(quoteItems.find((i) => i.id === quoteMenuOpenId))"
        >
          Edit
        </button>
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="askDeleteItemFromMenu(quoteItems.find((i) => i.id === quoteMenuOpenId))"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <ProjectEditModal
      v-model:open="editOpen"
      :busy="editBusy"
      :error-msg="editError"
      :name="project?.name || ''"
      :description="project?.description || ''"
      @submit="submitEditProject"
    />

    <BillingCustomBillLineModal
      v-model:open="quoteOpen"
      :title="quoteModalTitle"
      :submit-label="quoteModalTitle"
      :busy="quoteBusy"
      :error-msg="quoteError"
      :category-options="categoryOptions"
      v-model:category="quoteCategory"
      v-model:name="quoteName"
      v-model:quantity="quoteQty"
      v-model:unit-price="quotePrice"
      v-model:sku="quoteSku"
      @submit="submitQuote"
    />

    <ConfirmModal
      :open="deleteNoteOpen"
      title="Delete Note"
      message="Delete this internal note?"
      confirm-label="Delete"
      :busy="deleteNoteBusy"
      @close="deleteNoteOpen = false"
      @confirm="confirmDeleteNote"
    />

    <ConfirmModal
      :open="deleteItemOpen"
      title="Remove Quote Line"
      :message="deleteItemTarget ? `Remove “${deleteItemTarget.name}” from the quote?` : ''"
      confirm-label="Delete"
      :busy="deleteItemBusy"
      @close="deleteItemOpen = false"
      @confirm="confirmDeleteItem"
    />

    <ConfirmModal
      :open="deleteProjectOpen"
      title="Delete Project"
      :message="
        project
          ? `Delete project ${project.pid}? Any linked custom bill will be kept.`
          : ''
      "
      confirm-label="Delete"
      :busy="deleteProjectBusy"
      danger
      @close="deleteProjectOpen = false"
      @confirm="confirmDeleteProject"
    />
  </div>
</template>

<style scoped>
.project-detail-card__head {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 1.5rem 1.75rem;
  border-bottom: 1px solid var(--bs-border-color);
}

.project-detail-card__head-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: 999px;
  background: #eef2ff;
  color: #2563eb;
  flex-shrink: 0;
}

.project-detail-card__head-icon svg {
  width: 1.5rem;
  height: 1.5rem;
}

.project-detail-card__title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #1e293b;
}

.project-detail-card__subtitle {
  font-size: 0.85rem;
  color: var(--bs-secondary-color);
}

.project-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.5rem 1.25rem;
  padding: 1.5rem 1.75rem;
}

.project-detail-grid--single {
  grid-template-columns: minmax(0, 1fr);
}

.project-detail-field {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  min-width: 0;
}

.project-detail-field__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  background: #eef2ff;
  color: #2563eb;
  flex-shrink: 0;
}

.project-detail-field__icon svg {
  width: 1.3rem;
  height: 1.3rem;
}

.project-detail-field__label {
  margin: 0 0 0.15rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--bs-secondary-color);
}

.project-detail-field__value {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #1e293b;
  word-break: break-word;
}

.project-detail-field__sub {
  margin: 0.1rem 0 0;
  font-size: 0.8rem;
  color: var(--bs-secondary-color);
}

@media (max-width: 575.98px) {
  .project-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
