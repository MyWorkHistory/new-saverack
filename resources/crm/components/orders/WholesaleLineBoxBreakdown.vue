<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  orderId: { type: [String, Number], required: true },
  lineId: { type: [String, Number], required: true },
  lineQuantity: { type: [Number, String], default: 0 },
  boxes: { type: Array, default: () => [] },
  readOnly: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const toast = useToast();
const rows = ref([]);
const busy = ref(false);
let saveTimer = null;
let syncingFromProps = false;

function emptyRow() {
  return { length: "", width: "", height: "", weight: "", quantity: "" };
}

function mapBoxes(list) {
  const src = Array.isArray(list) ? list : [];
  return src.map((b) => ({
    length: b?.length != null ? String(b.length) : "",
    width: b?.width != null ? String(b.width) : "",
    height: b?.height != null ? String(b.height) : "",
    weight: b?.weight != null ? String(b.weight) : "",
    quantity: b?.quantity != null ? String(b.quantity) : "",
  }));
}

function hydrateFromProps() {
  syncingFromProps = true;
  const mapped = mapBoxes(props.boxes);
  rows.value = mapped.length ? mapped : props.readOnly ? [] : [];
  syncingFromProps = false;
}

watch(
  () => [props.lineId, props.boxes, props.readOnly],
  () => hydrateFromProps(),
  { immediate: true, deep: true },
);

const boxCount = computed(() => rows.value.length);

const qtySum = computed(() =>
  rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0),
);

const lineQty = computed(() => Number(props.lineQuantity) || 0);

const qtyMismatch = computed(
  () => rows.value.length > 0 && qtySum.value !== lineQty.value,
);

function formatDim(value) {
  if (value === null || value === undefined || value === "") return "—";
  const n = Number(value);
  if (Number.isNaN(n)) return String(value);
  return String(n);
}

function formatBoxSize(row) {
  return `${formatDim(row.length)} x ${formatDim(row.width)} x ${formatDim(row.height)} in`;
}

function scheduleSave() {
  if (props.readOnly || syncingFromProps || busy.value) return;
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    saveTimer = null;
    save();
  }, 450);
}

function addBox() {
  if (props.readOnly) return;
  rows.value = [...rows.value, emptyRow()];
  scheduleSave();
}

function removeBox(index) {
  if (props.readOnly) return;
  rows.value = rows.value.filter((_, i) => i !== index);
  scheduleSave();
}

function payloadBoxes() {
  return rows.value.map((r) => ({
    length: r.length === "" ? null : Number(r.length),
    width: r.width === "" ? null : Number(r.width),
    height: r.height === "" ? null : Number(r.height),
    weight: r.weight === "" ? null : Number(r.weight),
    quantity: r.quantity === "" ? 0 : Math.max(0, parseInt(String(r.quantity), 10) || 0),
  }));
}

async function save() {
  if (props.readOnly || busy.value) return;
  busy.value = true;
  try {
    const { data } = await api.put(
      `/admin/wholesale-orders/${props.orderId}/lines/${props.lineId}/boxes`,
      { boxes: payloadBoxes() },
    );
    emit("saved", data);
  } catch (e) {
    toast.errorFrom(e, "Could not save box breakdown.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="wholesale-line-box-breakdown">
    <div class="wholesale-line-box-breakdown__head">
      Box Breakdown ({{ boxCount }} {{ boxCount === 1 ? "box" : "boxes" }})
    </div>

    <p v-if="qtyMismatch" class="wholesale-line-box-breakdown__warn small mb-2">
      Box quantities ({{ qtySum }}) do not match line qty ({{ lineQty }}).
    </p>

    <div v-if="rows.length" class="table-responsive">
      <table class="table table-sm align-middle mb-2 wholesale-line-box-breakdown__table">
        <thead>
          <tr>
            <th scope="col">Box #</th>
            <th scope="col">Box Size (L x W x H)</th>
            <th scope="col">Weight</th>
            <th scope="col">Quantity</th>
            <th v-if="!readOnly" scope="col" class="text-end">
              <span class="visually-hidden">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="'box-' + lineId + '-' + index">
            <td class="wholesale-line-box-breakdown__num text-secondary">{{ index + 1 }}</td>
            <td>
              <template v-if="readOnly">
                <span class="text-secondary">{{ formatBoxSize(row) }}</span>
              </template>
              <div v-else class="wholesale-line-box-breakdown__dims">
                <input
                  v-model="row.length"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control form-control-sm"
                  aria-label="Length inches"
                  :disabled="busy"
                  @input="scheduleSave"
                />
                <span class="text-secondary" aria-hidden="true">x</span>
                <input
                  v-model="row.width"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control form-control-sm"
                  aria-label="Width inches"
                  :disabled="busy"
                  @input="scheduleSave"
                />
                <span class="text-secondary" aria-hidden="true">x</span>
                <input
                  v-model="row.height"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control form-control-sm"
                  aria-label="Height inches"
                  :disabled="busy"
                  @input="scheduleSave"
                />
                <span class="text-secondary small">in</span>
              </div>
            </td>
            <td>
              <template v-if="readOnly">
                <span class="text-secondary">{{ formatDim(row.weight) }} lb</span>
              </template>
              <div v-else class="wholesale-line-box-breakdown__weight">
                <input
                  v-model="row.weight"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control form-control-sm"
                  aria-label="Weight pounds"
                  :disabled="busy"
                  @input="scheduleSave"
                />
                <span class="text-secondary small">lb</span>
              </div>
            </td>
            <td>
              <template v-if="readOnly">
                <span>{{ row.quantity || "—" }}</span>
              </template>
              <input
                v-else
                v-model="row.quantity"
                type="number"
                min="0"
                step="1"
                class="form-control form-control-sm wholesale-line-box-breakdown__qty"
                aria-label="Quantity in box"
                :disabled="busy"
                @input="scheduleSave"
              />
            </td>
            <td v-if="!readOnly" class="text-end">
              <button
                type="button"
                class="btn btn-link btn-sm text-danger p-0 wholesale-line-box-breakdown__delete"
                :disabled="busy"
                aria-label="Delete Box"
                @click="removeBox(index)"
              >
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-else-if="readOnly" class="small text-secondary mb-0">No boxes yet.</p>

    <button
      v-if="!readOnly"
      type="button"
      class="btn btn-link btn-sm p-0 text-decoration-none wholesale-line-box-breakdown__add"
      :disabled="busy"
      @click="addBox"
    >
      + Add Box
    </button>
  </div>
</template>

<style scoped>
.wholesale-line-box-breakdown {
  padding: 0.35rem 0.25rem 0.5rem;
}

.wholesale-line-box-breakdown__head {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--bs-secondary-color, #6c757d);
  margin-bottom: 0.5rem;
}

.wholesale-line-box-breakdown__warn {
  color: #b45309;
}

.wholesale-line-box-breakdown__table {
  --bs-table-bg: transparent;
  font-size: 0.8125rem;
}

.wholesale-line-box-breakdown__table thead th {
  font-weight: 500;
  color: var(--bs-secondary-color, #6c757d);
  border-bottom-width: 1px;
  white-space: nowrap;
}

.wholesale-line-box-breakdown__num {
  width: 3.25rem;
}

.wholesale-line-box-breakdown__dims {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}

.wholesale-line-box-breakdown__dims .form-control {
  width: 4.25rem;
  max-width: 100%;
}

.wholesale-line-box-breakdown__weight {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.wholesale-line-box-breakdown__weight .form-control {
  width: 4.5rem;
}

.wholesale-line-box-breakdown__qty {
  width: 4.5rem;
}

.wholesale-line-box-breakdown__add {
  font-weight: 600;
}

.wholesale-line-box-breakdown__delete {
  line-height: 1;
}
</style>
