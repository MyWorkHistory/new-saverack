<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import WholesaleBarcodeUploadModal from "../../components/orders/WholesaleBarcodeUploadModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs, formatDateTimeUs } from "../../utils/formatUserDates.js";
import {
  wholesaleStatusBadgeClass,
  wholesaleStatusLabel,
  wholesaleTypeLabel,
} from "../../utils/formatWholesaleOrderDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const lineBusy = ref(false);
const addPanelOpen = ref(false);
const order = ref(null);

const instructionsDraft = ref("");
const instructionsSaving = ref(false);

const barcodeModalOpen = ref(false);
const barcodeUploadBusy = ref(false);
const barcodeLine = ref(null);

const commentBody = ref("");
const commentFile = ref(null);
const commentFileInput = ref(null);
const commentSubmitting = ref(false);
const commentError = ref("");
const imagePreviewUrls = ref({});

const orderId = computed(() => String(route.params.id || ""));
const clientAccountId = computed(() => Number(order.value?.client_account_id || 0));
const isEditable = computed(() => Boolean(order.value?.is_editable));
const lines = computed(() => (Array.isArray(order.value?.lines) ? order.value.lines : []));
const comments = computed(() => (Array.isArray(order.value?.comments) ? order.value.comments : []));

function applyOrderData(data) {
  order.value = data;
  instructionsDraft.value = String(data?.instructions || "");
}

function isImageMime(mime) {
  return String(mime || "").toLowerCase().startsWith("image/");
}

function initials(name) {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function formatFileSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n <= 0) return "";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/wholesale-orders/${orderId.value}`);
    applyOrderData(data);
    setCrmPageMeta({
      title: `Save Rack | Wholesale | ${data.order_number || "Order"}`,
      description: "Wholesale order detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale order.");
    router.push({ name: "wholesale-orders" });
  } finally {
    loading.value = false;
  }
}

async function saveInstructions() {
  if (!order.value?.id || !isEditable.value) return;
  const next = instructionsDraft.value.trim();
  if (next === String(order.value.instructions || "").trim()) return;
  instructionsSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, {
      instructions: next || null,
    });
    applyOrderData(data);
    toast.success("Instructions saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save instructions.");
  } finally {
    instructionsSaving.value = false;
  }
}

function buildLinePayload(product, quantity) {
  const sku = String(product?.sku || "").trim();
  const name = String(product?.name || product?.product_name || sku).trim();
  const imageUrl = product?.image_url || product?.thumbnail || product?.small_image || null;
  return {
    sku,
    name,
    image_url: imageUrl,
    quantity: Math.max(1, Math.floor(Number(quantity) || 0)),
  };
}

async function addFromCatalog({ product, quantity }) {
  if (!order.value?.id || !isEditable.value) return;
  const payload = buildLinePayload(product, quantity);
  if (!payload.sku) {
    toast.error("This product has no SKU.");
    return;
  }
  lineBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/lines`, payload);
    applyOrderData(data);
    toast.success("Product added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add product.");
  } finally {
    lineBusy.value = false;
  }
}

async function saveLineQty(line, rawQty) {
  if (!order.value?.id || !isEditable.value || !line?.id) return;
  const qty = Math.max(1, Number(rawQty) || 1);
  if (qty === Number(line.quantity)) return;
  lineBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}/lines/${line.id}`, {
      quantity: qty,
    });
    applyOrderData(data);
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
    await load();
  } finally {
    lineBusy.value = false;
  }
}

async function removeLine(line) {
  if (!order.value?.id || !isEditable.value || !line?.id) return;
  lineBusy.value = true;
  try {
    const { data } = await api.delete(`/admin/wholesale-orders/${order.value.id}/lines/${line.id}`);
    applyOrderData(data);
    toast.success("Line removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove line.");
  } finally {
    lineBusy.value = false;
  }
}

function openBarcodeModal(line) {
  if (!isEditable.value || !line?.id) return;
  barcodeLine.value = line;
  barcodeModalOpen.value = true;
}

function closeBarcodeModal() {
  if (barcodeUploadBusy.value) return;
  barcodeModalOpen.value = false;
  barcodeLine.value = null;
}

async function uploadBarcode(file) {
  if (!order.value?.id || !barcodeLine.value?.id || !file) return;
  barcodeUploadBusy.value = true;
  const fd = new FormData();
  fd.append("barcode", file);
  try {
    const { data } = await api.post(
      `/admin/wholesale-orders/${order.value.id}/lines/${barcodeLine.value.id}/barcode`,
      fd,
      { headers: { "Content-Type": undefined } },
    );
    applyOrderData(data);
    barcodeModalOpen.value = false;
    barcodeLine.value = null;
    toast.success("Barcode uploaded.");
  } catch (e) {
    toast.errorFrom(e, "Could not upload barcode.");
  } finally {
    barcodeUploadBusy.value = false;
  }
}

async function printBarcode(line) {
  if (!order.value?.id || !line?.id || !line.has_barcode) return;
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/lines/${line.id}/barcode.pdf`,
      { responseType: "blob" },
    );
    const blob = data instanceof Blob ? data : new Blob([data]);
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, "Could not open barcode.");
  }
}

async function submitComment() {
  if (!order.value?.id) return;
  const body = commentBody.value?.trim() || "";
  if (!body) {
    commentError.value = "Write a comment first.";
    return;
  }
  commentSubmitting.value = true;
  commentError.value = "";
  const fd = new FormData();
  fd.append("body", body);
  if (commentFile.value) fd.append("attachment", commentFile.value);
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/comments`, fd, {
      headers: { "Content-Type": undefined },
    });
    const list = Array.isArray(order.value.comments) ? [...order.value.comments] : [];
    list.push(data);
    order.value = { ...order.value, comments: list };
    commentBody.value = "";
    commentFile.value = null;
    if (commentFileInput.value) commentFileInput.value.value = "";
    toast.success("Comment posted.");
  } catch (e) {
    commentError.value = e?.response?.data?.message || "Could not post comment.";
  } finally {
    commentSubmitting.value = false;
  }
}

async function downloadAttachment(commentId) {
  if (!order.value?.id) return;
  try {
    const { data, headers } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    const c = comments.value.find((x) => x.id === commentId);
    let name = "attachment";
    if (c?.attachment?.original_name) name = c.attachment.original_name;
    const url = window.URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    window.URL.revokeObjectURL(url);
  } catch (e) {
    toast.errorFrom(e, "Could not download attachment.");
  }
}

async function loadImagePreview(commentId) {
  if (!order.value?.id || imagePreviewUrls.value[commentId]) return;
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    imagePreviewUrls.value = {
      ...imagePreviewUrls.value,
      [commentId]: window.URL.createObjectURL(data),
    };
  } catch {
    /* ignore preview failures */
  }
}

onMounted(load);

onUnmounted(() => {
  Object.values(imagePreviewUrls.value).forEach((url) => {
    if (url) window.URL.revokeObjectURL(url);
  });
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5">
    <CrmLoadingSpinner message="Loading order…" :center="true" />
  </div>

  <div v-else-if="order" class="staff-page staff-page--wide order-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">Order #{{ order.order_number }}</h1>
              <span class="badge rounded-pill fw-medium" :class="wholesaleStatusBadgeClass(order.status)">
                {{ order.status_label || wholesaleStatusLabel(order.status) }}
              </span>
            </div>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-2 text-decoration-none"
              @click="router.push({ name: 'wholesale-orders' })"
            >
              &lt; Wholesale Orders
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8 d-flex flex-column gap-4">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0">
          <div class="px-4 py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0 fw-semibold">Items</h2>
            <button
              v-if="isEditable"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="lineBusy"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
            </button>
          </div>

          <div v-if="isEditable && addPanelOpen" class="border-bottom">
            <AsnProductCatalogPanel
              :client-account-id="clientAccountId"
              :wholesale-order-id="orderId"
              :active="addPanelOpen"
              :busy="lineBusy"
              qty-label="Quantity"
              search-input-id="wholesale-order-catalog-search"
              @add="addFromCatalog"
            />
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th order-detail-page__items-col" scope="col">Item</th>
                  <th class="staff-table-head__th text-center" scope="col">Qty</th>
                  <th class="staff-table-head__th text-center" scope="col">Barcodes</th>
                  <th v-if="isEditable" class="staff-table-head__th text-center" scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in lines" :key="line.id">
                  <td>
                    <div class="d-flex align-items-center gap-2 order-detail-page__item-cell">
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="order-detail-page__item-thumb rounded border flex-shrink-0"
                        width="48"
                        height="48"
                        loading="lazy"
                      />
                      <div class="min-w-0">
                        <div class="fw-semibold text-truncate">{{ line.name || "—" }}</div>
                        <div class="small text-secondary">{{ line.sku }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="text-center">
                    <input
                      v-if="isEditable"
                      type="number"
                      min="1"
                      class="form-control form-control-sm text-center mx-auto wholesale-line-qty-input"
                      :value="line.quantity"
                      :disabled="lineBusy"
                      @change="saveLineQty(line, $event.target.value)"
                    />
                    <span v-else>{{ line.quantity }}</span>
                  </td>
                  <td class="text-center">
                    <button
                      v-if="line.has_barcode"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      @click="printBarcode(line)"
                    >
                      Print Barcode
                    </button>
                    <button
                      v-else-if="isEditable"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      @click="openBarcodeModal(line)"
                    >
                      Ship As Is
                    </button>
                    <span v-else class="text-secondary">Ship As Is</span>
                  </td>
                  <td v-if="isEditable" class="text-center">
                    <div class="d-flex flex-column gap-1 align-items-center">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
                        :disabled="lineBusy"
                        @click="openBarcodeModal(line)"
                      >
                        Upload Barcode
                      </button>
                      <button
                        type="button"
                        class="btn btn-link btn-sm text-danger text-decoration-none p-0"
                        :disabled="lineBusy"
                        @click="removeLine(line)"
                      >
                        Remove
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!lines.length">
                  <td :colspan="isEditable ? 4 : 3" class="text-center text-secondary py-4">No items yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h2 class="h6 fw-semibold mb-3">Instructions</h2>
          <textarea
            v-if="isEditable"
            v-model="instructionsDraft"
            class="form-control mb-3"
            rows="5"
            maxlength="20000"
            placeholder="Warehouse instructions for this order…"
            :disabled="instructionsSaving"
          />
          <p v-else class="small mb-0 text-secondary" style="white-space: pre-wrap">
            {{ order.instructions || "—" }}
          </p>
          <button
            v-if="isEditable"
            type="button"
            class="btn btn-primary btn-sm staff-page-primary fw-semibold"
            :disabled="instructionsSaving"
            @click="saveInstructions"
          >
            {{ instructionsSaving ? "Saving…" : "Save Instructions" }}
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h2 class="h6 fw-semibold mb-3">Comments</h2>
          <ul v-if="comments.length" class="list-unstyled mb-0 pb-4 border-bottom">
            <li v-for="c in comments" :key="c.id" class="d-flex gap-3 mb-4">
              <span
                class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold bg-primary-subtle text-primary-emphasis"
                style="width: 2rem; height: 2rem"
              >
                {{ initials(c.user?.name) }}
              </span>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap align-items-baseline gap-2">
                  <span class="small fw-medium">{{ c.user?.name || "User" }}</span>
                  <span class="small text-secondary">{{ formatDateTimeUs(c.created_at) }}</span>
                </div>
                <p class="mt-1 mb-0 small" style="white-space: pre-wrap">{{ c.body }}</p>
                <div v-if="c.attachment" class="mt-2">
                  <img
                    v-if="isImageMime(c.attachment.mime)"
                    :src="imagePreviewUrls[c.id]"
                    alt=""
                    class="img-fluid rounded border"
                    style="max-height: 12rem"
                    @load="loadImagePreview(c.id)"
                  />
                  <button
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none p-0"
                    @click="downloadAttachment(c.id)"
                  >
                    {{ c.attachment.original_name || "Download attachment" }}
                    <span v-if="formatFileSize(c.attachment.size)" class="text-secondary">
                      ({{ formatFileSize(c.attachment.size) }})
                    </span>
                  </button>
                </div>
              </div>
            </li>
          </ul>
          <p v-else class="text-secondary small border-bottom pb-4 mb-0">No comments yet.</p>

          <div class="pt-4">
            <label class="form-label small text-secondary" for="wholesale-order-comment">Add comment</label>
            <textarea
              id="wholesale-order-comment"
              v-model="commentBody"
              rows="3"
              class="form-control"
              placeholder="Write an update…"
            />
            <div class="mt-3 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2">
              <input
                ref="commentFileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
                class="form-control form-control-sm"
                @change="commentFile = $event.target.files?.[0] || null"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="commentSubmitting"
                @click="submitComment"
              >
                {{ commentSubmitting ? "Posting…" : "Post Comment" }}
              </button>
            </div>
            <p v-if="commentError" class="text-danger small mt-2 mb-0">{{ commentError }}</p>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Requirements Needed</h3>
          <p class="small text-secondary mb-0">None yet.</p>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Order Info</h3>
          <dl class="small mb-0">
            <dt class="text-secondary fw-normal">Type</dt>
            <dd class="mb-2">{{ order.order_type_label || wholesaleTypeLabel(order.order_type) }}</dd>
            <dt class="text-secondary fw-normal">Create Date</dt>
            <dd class="mb-2">{{ formatDateUs(order.created_at) || "—" }}</dd>
            <dt class="text-secondary fw-normal">Created By</dt>
            <dd class="mb-0">{{ order.created_by_name || "—" }}</dd>
          </dl>
        </div>
      </div>
    </div>

    <WholesaleBarcodeUploadModal
      :open="barcodeModalOpen"
      :busy="barcodeUploadBusy"
      :line-label="barcodeLine ? `${barcodeLine.sku} — ${barcodeLine.name}` : ''"
      @close="closeBarcodeModal"
      @upload="uploadBarcode"
    />
  </div>
</template>

<style scoped>
.wholesale-line-qty-input {
  max-width: 5rem;
}
</style>
