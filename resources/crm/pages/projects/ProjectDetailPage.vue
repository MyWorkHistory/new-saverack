<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import BillingCustomBillLineModal from "../../components/billing/BillingCustomBillLineModal.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ProjectStatusChip from "../../components/projects/ProjectStatusChip.vue";
import {
  DEFAULT_INVOICE_CATEGORY,
  INVOICE_CATEGORY_OPTIONS,
  invoiceCategoryLabel,
} from "../../constants/invoiceCategoryOptions.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  id: { type: [String, Number], required: true },
});

const toast = useToast();

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

const money = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

const categoryOptions = computed(() => {
  const fromApi = project.value?.category_options;
  if (Array.isArray(fromApi) && fromApi.length) return fromApi;
  return INVOICE_CATEGORY_OPTIONS;
});

const quoteItems = computed(() =>
  Array.isArray(project.value?.quote_items) ? project.value.quote_items : [],
);

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
  if (!project.value?.id || statusBusy.value) return;
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
  if (!body || noteBusy.value) return;
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

onMounted(load);
</script>

<template>
  <div class="staff-page">
    <div class="mb-3">
      <RouterLink to="/admin/clients/projects" class="small text-decoration-none">
        ← Projects
      </RouterLink>
    </div>

    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading project…" />
    </div>

    <div v-else-if="!project" class="text-secondary">Project not found.</div>

    <div v-else class="row g-4">
      <div class="col-12 col-lg-7">
        <div class="staff-table-card p-4 mb-4">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
              <div class="text-secondary small">{{ project.pid }}</div>
              <h1 class="staff-page-title mb-1">{{ project.name }}</h1>
              <div class="text-secondary">
                {{ project.client_account_name || "—" }}
              </div>
            </div>
            <ProjectStatusChip
              :status="project.status"
              :disabled="statusBusy"
              @change="changeStatus"
            />
          </div>

          <p v-if="project.description" class="mb-3">{{ project.description }}</p>
          <dl class="row mb-0 small">
            <dt class="col-sm-4 text-secondary">Date Created</dt>
            <dd class="col-sm-8">{{ formatDateUs(project.created_at) || "—" }}</dd>
            <dt class="col-sm-4 text-secondary">Date Completed</dt>
            <dd class="col-sm-8">{{ formatDateUs(project.completed_at) || "—" }}</dd>
            <dt v-if="project.custom_bill_id" class="col-sm-4 text-secondary">Custom Bill</dt>
            <dd v-if="project.custom_bill_id" class="col-sm-8">
              <RouterLink
                class="text-decoration-none"
                :to="`/admin/billing/custom-bills/${project.custom_bill_id}`"
              >
                {{ project.custom_bill_name || project.custom_bill_number || project.pid }}
              </RouterLink>
            </dd>
          </dl>
        </div>

        <div class="staff-table-card p-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h5 mb-0">Notes</h2>
            <span class="small text-secondary">Internal only</span>
          </div>
          <div class="mb-3">
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

      <div class="col-12 col-lg-5">
        <div class="staff-table-card p-4">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Project Quote</h2>
            <button
              type="button"
              class="btn btn-primary staff-page-primary btn-sm"
              :disabled="!project.quote_open"
              @click="openAddQuote"
            >
              Add to Quote
            </button>
          </div>
          <p v-if="!project.quote_open" class="small text-secondary">
            Quote is locked because the custom bill is invoiced.
          </p>
          <div v-if="!quoteItems.length" class="text-secondary small py-3">
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
                      v-if="project.quote_open"
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
        </div>
      </div>
    </div>

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
  </div>
</template>
