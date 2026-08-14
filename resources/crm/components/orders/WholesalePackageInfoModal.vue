<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import Modal from "../Modal.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [String, Number], required: true },
  packageType: { type: String, required: true }, // box | pallet
  packages: { type: Array, default: () => [] },
  savedAt: { type: String, default: null },
  readOnly: { type: Boolean, default: false },
  /** Staff-only Slack notify (portal users cannot call that endpoint). */
  hideSlack: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const busy = ref(false);
const slackBusy = ref(false);
const rows = ref([]);

const isBox = computed(() => props.packageType === "box");
const title = computed(() => (isBox.value ? "Box Info" : "Pallet Info"));
const addLabel = computed(() => (isBox.value ? "Add Box" : "Add Pallet"));
const itemLabel = computed(() => (isBox.value ? "Box" : "Pallet"));

const emptyPlaceholder = computed(() =>
  isBox.value
    ? "Save Rack will provide box dimensions and weights for each box when order is ready to ship"
    : "Save Rack will provide pallet dimensions and weights when order is ready to ship",
);

const showEmptyPlaceholder = computed(
  () => props.readOnly && (!Array.isArray(rows.value) || rows.value.length === 0),
);

function emptyRow() {
  return { width: "", length: "", height: "", weight: "" };
}

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    const src = Array.isArray(props.packages) ? props.packages : [];
    rows.value = src.length
      ? src.map((p) => ({
          width: p.width != null ? String(p.width) : "",
          length: p.length != null ? String(p.length) : "",
          height: p.height != null ? String(p.height) : "",
          weight: p.weight != null ? String(p.weight) : "",
        }))
      : props.readOnly
        ? []
        : [emptyRow()];
  },
);

function close() {
  if (busy.value || slackBusy.value) return;
  emit("update:open", false);
}

function addRow() {
  rows.value = [...rows.value, emptyRow()];
}

function removeRow(index) {
  rows.value = rows.value.filter((_, i) => i !== index);
}

async function save() {
  if (busy.value || props.readOnly) return;
  busy.value = true;
  try {
    const { data } = await api.put(`/admin/wholesale-orders/${props.orderId}/packages`, {
      package_type: props.packageType,
      packages: rows.value.map((r) => ({
        width: r.width === "" ? null : Number(r.width),
        length: r.length === "" ? null : Number(r.length),
        height: r.height === "" ? null : Number(r.height),
        weight: r.weight === "" ? null : Number(r.weight),
      })),
    });
    emit("saved", data);
    toast.success(`${title.value} saved.`);
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, `Could not save ${title.value.toLowerCase()}.`);
  } finally {
    busy.value = false;
  }
}

async function sendToSlack() {
  if (slackBusy.value || props.readOnly) return;
  slackBusy.value = true;
  try {
    await api.post(`/admin/wholesale-orders/${props.orderId}/packages/send-slack`, {
      package_type: props.packageType,
    });
    toast.success("Sent to Slack.");
  } catch (e) {
    toast.errorFrom(e, "Could not send to Slack.");
  } finally {
    slackBusy.value = false;
  }
}
</script>

<template>
  <Modal :open="open" :title="title" @close="close">
    <div class="wholesale-package-modal">
      <template v-if="showEmptyPlaceholder">
        <p class="text-secondary mb-0">{{ emptyPlaceholder }}</p>
        <div class="d-flex justify-content-end mt-4">
          <button type="button" class="btn btn-outline-secondary" @click="close">Close</button>
        </div>
      </template>
      <template v-else>
        <p v-if="!readOnly" class="small text-secondary mb-3">
          Add the dimensions and weight for each {{ itemLabel.toLowerCase() }}. You can add multiple if
          needed.
        </p>
        <p v-else class="small text-secondary mb-3">
          Dimensions and weights provided by Save Rack.
        </p>
        <p v-if="savedAt" class="small text-secondary mb-3">
          Last saved {{ formatDateTimeUs(savedAt) }}
        </p>

        <div
          v-for="(row, index) in rows"
          :key="'pkg-' + index"
          class="border rounded p-3 mb-3"
        >
          <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <span class="fw-semibold small">{{ itemLabel }} {{ index + 1 }}</span>
            <button
              v-if="!readOnly"
              type="button"
              class="btn btn-link btn-sm text-danger p-0"
              :disabled="busy || slackBusy"
              @click="removeRow(index)"
            >
              Delete
            </button>
          </div>
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <label class="form-label small text-secondary mb-1">W (inches)</label>
              <input
                v-model="row.width"
                type="number"
                min="0"
                step="0.01"
                class="form-control"
                :disabled="busy || readOnly"
                :readonly="readOnly"
              />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-secondary mb-1">L (inches)</label>
              <input
                v-model="row.length"
                type="number"
                min="0"
                step="0.01"
                class="form-control"
                :disabled="busy || readOnly"
                :readonly="readOnly"
              />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-secondary mb-1">H (inches)</label>
              <input
                v-model="row.height"
                type="number"
                min="0"
                step="0.01"
                class="form-control"
                :disabled="busy || readOnly"
                :readonly="readOnly"
              />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-secondary mb-1">Weight (lbs)</label>
              <input
                v-model="row.weight"
                type="number"
                min="0"
                step="0.01"
                class="form-control"
                :disabled="busy || readOnly"
                :readonly="readOnly"
              />
            </div>
          </div>
        </div>

        <button
          v-if="!readOnly"
          type="button"
          class="btn btn-outline-secondary w-100 mb-3"
          :disabled="busy || slackBusy"
          @click="addRow"
        >
          + {{ addLabel }}
        </button>

        <div class="d-flex flex-wrap justify-content-end gap-2">
          <template v-if="!readOnly">
            <button
              v-if="!hideSlack"
              type="button"
              class="btn btn-outline-primary"
              :disabled="busy || slackBusy || !rows.length"
              @click="sendToSlack"
            >
              <CrmLoadingSpinner v-if="slackBusy" small class="me-1" />
              Send to Slack
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="busy || slackBusy"
              @click="close"
            >
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary"
              :disabled="busy || slackBusy"
              @click="save"
            >
              <CrmLoadingSpinner v-if="busy" small class="me-1" />
              Save
            </button>
          </template>
          <button v-else type="button" class="btn btn-outline-secondary" @click="close">
            Close
          </button>
        </div>
      </template>
    </div>
  </Modal>
</template>

<style scoped>
.wholesale-package-modal {
  max-width: 36rem;
}
</style>
