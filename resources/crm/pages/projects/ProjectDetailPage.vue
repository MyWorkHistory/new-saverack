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
import CrmLinkedText from "../../components/common/CrmLinkedText.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
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
const deleteNoteOpen = ref(false);
const deleteNoteBusy = ref(false);
const deleteNoteTarget = ref(null);

const quoteOpen = ref(false);
const quoteBusy = ref(false);
const quoteError = ref("");
const quoteCategory = ref(DEFAULT_INVOICE_CATEGORY);
const quoteName = ref("");
const quoteQty = ref("1");
const quotePrice = ref("0.00");
const quoteSku = ref("");

const deleteItemOpen = ref(false);
const deleteItemBusy = ref(false);
const deleteItemTarget = ref(null);

const createBillBusy = ref(false);

const deleteProjectOpen = ref(false);
const deleteProjectBusy = ref(false);

const actionsMenuOpen = ref(false);
const actionsMenuRect = ref({ top: 0, left: 0 });
const ACTIONS_MENU_W = 180;
const ACTIONS_MENU_H = 56;

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

const canShowActionsMenu = computed(() => Boolean(project.value) && canDelete.value);

function cents(n) {
  return money.format((Number(n) || 0) / 100);
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
  quoteCategory.value = DEFAULT_INVOICE_CATEGORY;
  quoteName.value = "";
  quoteQty.value = "1";
  quotePrice.value = "0.00";
  quoteSku.value = "";
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
  try {
    const { data } = await api.post(`/projects/${project.value.id}/quote-items`, {
      line_type: quoteCategory.value,
      name,
      quantity: Number(quoteQty.value) || 1,
      unit_price: Number(quotePrice.value) || 0,
      sku: String(quoteSku.value || "").trim() || null,
    });
    project.value = data;
    quoteOpen.value = false;
    toast.success("Added to quote.");
  } catch (e) {
    quoteError.value = "Could not add quote line.";
    toast.errorFrom(e, "Could not add quote line.");
  } finally {
    quoteBusy.value = false;
  }
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
    toast.success("Custom bill created.");
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
}

function onWindowScrollOrResize() {
  actionsMenuOpen.value = false;
}

function onDocKeydown(e) {
  if (e.key === "Escape") {
    actionsMenuOpen.value = false;
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
        <h1 class="staff-user-view__title">{{ project?.pid || "Project" }}</h1>
        <p class="text-secondary small mb-0">Project details and quote</p>
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
              <span class="small text-secondary">Internal only</span>
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
            <div v-if="!(project.notes || []).length" class="text-secondary small">
              No notes yet.
            </div>
            <ul class="list-unstyled mb-0">
              <li
                v-for="note in project.notes || []"
                :key="note.id"
                class="border rounded p-3 mb-2"
              >
                <div class="d-flex justify-content-between gap-2">
                  <div class="small text-secondary">
                    {{ note.user_name || "Staff" }}
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
              </li>
            </ul>
          </div>
        </div>

        <div class="staff-table-card overflow-hidden">
          <div class="p-4 p-md-5 border-bottom">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <h3 class="h6 fw-semibold text-body mb-0">Project Quote</h3>
              <button
                v-if="canUpdate"
                type="button"
                class="btn btn-primary staff-page-primary btn-sm"
                :disabled="!project.quote_open"
                @click="openAddQuote"
              >
                Add to Quote
              </button>
            </div>
          </div>
          <div class="p-4 p-md-5">
            <p v-if="hasBill && !project.quote_open" class="small text-secondary">
              Quote is locked because the custom bill is invoiced.
            </p>
            <div v-if="!quoteItems.length" class="text-secondary small py-2">
              No quote lines yet.
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
                      <div class="fw-semibold">{{ item.name }}</div>
                      <div class="small text-secondary">
                        {{ invoiceCategoryLabel(item.line_type) }}
                        <span v-if="item.sku"> · {{ item.sku }}</span>
                      </div>
                    </td>
                    <td class="text-end">{{ item.quantity }}</td>
                    <td class="text-end">{{ cents(item.line_total_cents) }}</td>
                    <td class="text-end">
                      <button
                        v-if="canUpdate && project.quote_open"
                        type="button"
                        class="btn btn-link btn-sm text-danger p-0"
                        @click="askDeleteItem(item)"
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <th colspan="2" class="text-end">Total</th>
                    <th class="text-end">{{ cents(project.quote_total_cents) }}</th>
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
                class="btn btn-primary staff-page-primary"
                :disabled="createBillBusy"
                @click="createBill"
              >
                <CrmLoadingSpinner v-if="createBillBusy" small class="me-1" />
                Create Bill
              </button>
            </div>
          </div>
        </div>
      </div>

      <aside class="col-12 col-lg-4 d-flex flex-column gap-4">
        <div class="staff-table-card overflow-hidden p-4 p-md-5">
          <h3 class="small fw-semibold text-secondary text-uppercase mb-3">Details</h3>
          <dl class="row small mb-0 gy-3">
            <div class="col-12">
              <dt class="text-secondary mb-1">Account</dt>
              <dd class="mb-0 text-body">{{ project.client_account_name || "—" }}</dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Status</dt>
              <dd class="mb-0">
                <ProjectStatusChip
                  :status="project.status"
                  :disabled="statusBusy || !canUpdate"
                  @change="changeStatus"
                />
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Date Created</dt>
              <dd class="mb-0 text-body">{{ formatDateUs(project.created_at) || "—" }}</dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Date Completed</dt>
              <dd class="mb-0 text-body">
                {{ formatDateUs(project.completed_at) || "—" }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Created By</dt>
              <dd class="mb-0 text-body">{{ project.created_by_name || "—" }}</dd>
            </div>
          </dl>
        </div>

        <div
          v-if="hasBill"
          class="staff-table-card overflow-hidden p-4 p-md-5"
        >
          <h3 class="small fw-semibold text-secondary text-uppercase mb-3">
            Project Bill
          </h3>
          <dl class="row small mb-0 gy-3">
            <div class="col-12">
              <dt class="text-secondary mb-1">Custom Bill</dt>
              <dd class="mb-0">
                <RouterLink
                  class="text-decoration-none"
                  :to="`/admin/billing/custom-bills/${project.custom_bill_id}`"
                >
                  {{
                    project.custom_bill_name ||
                    (project.custom_bill_number
                      ? `Bill #${project.custom_bill_number}`
                      : "View Bill")
                  }}
                </RouterLink>
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Status</dt>
              <dd class="mb-0 text-body text-capitalize">
                {{ project.custom_bill_status || "—" }}
              </dd>
            </div>
            <div class="col-12">
              <dt class="text-secondary mb-1">Total</dt>
              <dd class="mb-0 text-body">{{ cents(project.quote_total_cents) }}</dd>
            </div>
          </dl>
        </div>
      </aside>
    </div>

    <Teleport to="body">
      <div
        v-if="actionsMenuOpen"
        class="staff-manage-menu staff-manage-menu--fixed"
        data-project-actions
        :style="{
          top: `${actionsMenuRect.top}px`,
          left: `${actionsMenuRect.left}px`,
          width: `${ACTIONS_MENU_W}px`,
        }"
      >
        <button
          type="button"
          class="staff-manage-menu__item staff-manage-menu__item--danger"
          @click="openDeleteFromMenu"
        >
          Delete Project
        </button>
      </div>
    </Teleport>

    <BillingCustomBillLineModal
      v-model:open="quoteOpen"
      title="Add to Quote"
      submit-label="Add to Quote"
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
