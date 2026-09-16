<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  mode: { type: String, default: "weight" },
  row: { type: Object, default: null },
});

const emit = defineEmits(["close", "save"]);

const weight = ref("");
const weightUnit = ref("POUNDS");
const length = ref("");
const width = ref("");
const height = ref("");
const reason = ref("");
const reasons = ref([]);
const groups = ref([]);

const title = computed(() => {
  if (props.mode === "dimensions") return "Edit Dimensions";
  if (props.mode === "locations") return "Edit Locations";
  return "Edit Weight";
});

const cubicFeet = computed(() => {
  const l = Number(length.value);
  const w = Number(width.value);
  const h = Number(height.value);
  if (![l, w, h].every((n) => Number.isFinite(n) && n > 0)) return "—";
  return (l * w * h / 1728).toLocaleString("en-US", { maximumFractionDigits: 3 });
});

const hasLocations = computed(() => groups.value.some((group) => group.locations.length));

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    const row = props.row || {};
    weight.value = row.weight == null ? "" : String(row.weight);
    weightUnit.value = row.weight_unit || "POUNDS";
    length.value = row.length == null ? "" : String(row.length);
    width.value = row.width == null ? "" : String(row.width);
    height.value = row.height == null ? "" : String(row.height);
    groups.value = (row.location_groups || []).map((group) => ({
      key: group.key,
      label: group.label,
      locations: (group.locations || []).map((loc) => ({
        item_id: loc.item_id,
        location_id: loc.location_id,
        name: loc.name,
        available: String(loc.available ?? 0),
        original: Number(loc.available ?? 0),
      })),
    }));
    if (props.mode === "locations") loadReasons();
  }
);

async function loadReasons() {
  try {
    const { data } = await api.get("/shopify/locations/meta");
    reasons.value = Array.isArray(data?.add_item_reasons) ? data.add_item_reasons : [];
    reason.value = data?.default_add_item_reason || reasons.value[0] || "";
  } catch {
    reasons.value = [];
    reason.value = "";
  }
}

function blankOrNumber(value) {
  const text = String(value ?? "").trim();
  if (text === "") return null;
  return Number(text);
}

function onSave() {
  if (props.mode === "weight") {
    emit("save", {
      weight: blankOrNumber(weight.value),
      weight_unit: weightUnit.value,
    });
    return;
  }
  if (props.mode === "dimensions") {
    emit("save", {
      length: blankOrNumber(length.value),
      width: blankOrNumber(width.value),
      height: blankOrNumber(height.value),
      dimension_unit: "INCHES",
    });
    return;
  }
  const changes = [];
  groups.value.forEach((group) => {
    group.locations.forEach((loc) => {
      const next = Math.max(0, parseInt(loc.available, 10) || 0);
      if (next !== loc.original) {
        changes.push({
          item_id: loc.item_id,
          location_id: loc.location_id,
          available: next,
        });
      }
    });
  });
  emit("save", { changes, reason: reason.value });
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="busy ? null : emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" role="dialog" aria-modal="true" @click.stop>
        <button type="button" class="crm-vx-modal__close" aria-label="Close" :disabled="busy" @click="emit('close')">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <header class="crm-vx-modal__head" style="text-align: left">
          <h2 class="crm-vx-modal__title">{{ title }}</h2>
          <p class="small text-secondary mb-0 mt-1">{{ row?.product_title || row?.sku || "Product" }}</p>
        </header>
        <div class="crm-vx-modal__body" style="text-align: left">
          <template v-if="mode === 'weight'">
            <label class="form-label" for="sip-weight">Weight</label>
            <div class="d-flex gap-2">
              <input id="sip-weight" v-model="weight" type="number" min="0" step="0.01" class="form-control" :disabled="busy" />
              <select v-model="weightUnit" class="form-select" style="max-width: 8rem" aria-label="Weight unit" :disabled="busy">
                <option value="POUNDS">lbs</option>
                <option value="OUNCES">oz</option>
                <option value="GRAMS">g</option>
                <option value="KILOGRAMS">kg</option>
              </select>
            </div>
          </template>

          <template v-else-if="mode === 'dimensions'">
            <div class="row g-3">
              <div class="col-4">
                <label class="form-label" for="sip-length">Length (in)</label>
                <input id="sip-length" v-model="length" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
              <div class="col-4">
                <label class="form-label" for="sip-width">Width (in)</label>
                <input id="sip-width" v-model="width" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
              <div class="col-4">
                <label class="form-label" for="sip-height">Height (in)</label>
                <input id="sip-height" v-model="height" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
            </div>
            <p class="small text-secondary mt-3 mb-0">Cubic Ft: {{ cubicFeet }}</p>
          </template>

          <template v-else>
            <p v-if="!hasLocations" class="text-secondary mb-0">No locations assigned.</p>
            <div v-for="group in groups" :key="group.key" class="mb-3">
              <div v-if="group.locations.length" class="fw-semibold mb-2">{{ group.label }}</div>
              <div v-for="loc in group.locations" :key="loc.item_id" class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <span class="text-truncate">{{ loc.name }}</span>
                <input v-model="loc.available" type="number" min="0" step="1" class="form-control" style="max-width: 7rem" :disabled="busy" :aria-label="`${loc.name} quantity`" />
              </div>
            </div>
            <template v-if="hasLocations">
              <label class="form-label" for="sip-loc-reason">Reason</label>
              <select id="sip-loc-reason" v-model="reason" class="form-select" :disabled="busy">
                <option v-for="item in reasons" :key="item" :value="item">{{ item }}</option>
              </select>
            </template>
          </template>
        </div>
        <footer class="crm-vx-modal__footer justify-content-end">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">Cancel</button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="onSave">
            {{ busy ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
