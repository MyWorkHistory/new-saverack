<script setup>
import { computed, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  mode: { type: String, default: "locations" },
  count: { type: Number, default: 0 },
});

const emit = defineEmits(["close", "save"]);

const locations = ref([]);
const reasons = ref([]);
const packagingOptions = ref([]);
const materialOptions = ref([]);
const loading = ref(false);
const packagingQuery = ref("");
const materialQuery = ref("");

const form = reactive({
  location_id: "",
  available: 1,
  reason: "",
  packaging_item_id: "",
  packaging_material_item_id: "",
  weight: "",
  weight_unit: "POUNDS",
  length: "",
  width: "",
  height: "",
});

const title = computed(() => {
  if (props.mode === "packaging") return "Bulk Edit Packaging";
  if (props.mode === "weights") return "Bulk Edit Weights";
  if (props.mode === "dimensions") return "Bulk Edit Dimensions";
  return "Bulk Edit Locations";
});

const filteredPackaging = computed(() => filterOptions(packagingOptions.value, packagingQuery.value));
const filteredMaterials = computed(() => filterOptions(materialOptions.value, materialQuery.value));

const cubicFeet = computed(() => {
  const l = Number(form.length);
  const w = Number(form.width);
  const h = Number(form.height);
  if (![l, w, h].every((n) => Number.isFinite(n) && n > 0)) return "—";
  return (l * w * h / 1728).toLocaleString("en-US", { maximumFractionDigits: 3 });
});

function filterOptions(rows, query) {
  const q = String(query || "").trim().toLowerCase();
  if (!q) return rows;
  return rows.filter((row) => `${row.type_label || ""} ${row.name || ""}`.toLowerCase().includes(q));
}

function optionLabel(row, withType) {
  if (!row) return "";
  if (withType && row.type_label && row.name && row.type_label !== row.name) {
    return `${row.type_label}: ${row.name}`;
  }
  return row.name || row.type_label || "Packaging";
}

function resetForm() {
  form.location_id = "";
  form.available = 1;
  form.reason = reasons.value[0] || "";
  form.packaging_item_id = "";
  form.packaging_material_item_id = "";
  form.weight = "";
  form.weight_unit = "POUNDS";
  form.length = "";
  form.width = "";
  form.height = "";
  packagingQuery.value = "";
  materialQuery.value = "";
}

async function loadMeta() {
  loading.value = true;
  try {
    if (props.mode === "locations") {
      const [locRes, metaRes] = await Promise.all([
        api.get("/shopify/locations/options"),
        api.get("/shopify/locations/meta"),
      ]);
      locations.value = Array.isArray(locRes.data?.data) ? locRes.data.data : [];
      reasons.value = Array.isArray(metaRes.data?.add_item_reasons) ? metaRes.data.add_item_reasons : [];
      form.reason = metaRes.data?.default_add_item_reason || reasons.value[0] || "";
    } else if (props.mode === "packaging") {
      const [packaging, materials] = await Promise.all([
        api.get("/shopify/packaging", { params: { category: "packaging", per_page: 1000 } }),
        api.get("/shopify/packaging", { params: { category: "packaging_materials", per_page: 1000 } }),
      ]);
      packagingOptions.value = packaging.data?.data || [];
      materialOptions.value = materials.data?.data || [];
    }
  } catch {
    locations.value = [];
    reasons.value = [];
    packagingOptions.value = [];
    materialOptions.value = [];
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    resetForm();
    void loadMeta();
  },
);

function blankOrNumber(value) {
  const text = String(value ?? "").trim();
  if (text === "") return null;
  return Number(text);
}

function onSave() {
  if (props.mode === "locations") {
    emit("save", {
      mode: "locations",
      location_id: Number(form.location_id || 0),
      available: Math.max(1, Number(form.available) || 1),
      reason: form.reason,
    });
    return;
  }
  if (props.mode === "packaging") {
    emit("save", {
      mode: "packaging",
      packaging_item_id: form.packaging_item_id ? Number(form.packaging_item_id) : null,
      packaging_material_item_id: form.packaging_material_item_id ? Number(form.packaging_material_item_id) : null,
    });
    return;
  }
  if (props.mode === "weights") {
    emit("save", {
      mode: "weights",
      weight: blankOrNumber(form.weight),
      weight_unit: form.weight_unit,
    });
    return;
  }
  emit("save", {
    mode: "dimensions",
    length: blankOrNumber(form.length),
    width: blankOrNumber(form.width),
    height: blankOrNumber(form.height),
    dimension_unit: "INCHES",
  });
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
          <p class="small text-secondary mb-0 mt-1">
            Apply to {{ count }} selected product{{ count === 1 ? "" : "s" }}.
          </p>
        </header>
        <div class="crm-vx-modal__body" style="text-align: left">
          <template v-if="mode === 'locations'">
            <label class="form-label">Location</label>
            <CrmSearchableSelect
              v-model="form.location_id"
              class="mb-3"
              appearance="staff"
              aria-label="Select Location"
              :options="locations"
              :disabled="busy || loading"
              :allow-empty="false"
              placeholder="Select Location"
              empty-label="Select Location"
              search-placeholder="Search locations…"
              teleport-panel
            />
            <label class="form-label" for="sip-bulk-loc-qty">QTY</label>
            <input
              id="sip-bulk-loc-qty"
              v-model.number="form.available"
              type="number"
              min="1"
              class="form-control mb-3"
              :disabled="busy"
            />
            <label class="form-label" for="sip-bulk-loc-reason">Reason</label>
            <select id="sip-bulk-loc-reason" v-model="form.reason" class="form-select" :disabled="busy || loading">
              <option v-for="item in reasons" :key="item" :value="item">{{ item }}</option>
            </select>
          </template>

          <template v-else-if="mode === 'packaging'">
            <label class="form-label" for="sip-bulk-pack-search">Packaging</label>
            <input
              id="sip-bulk-pack-search"
              v-model="packagingQuery"
              type="search"
              class="form-control mb-2"
              placeholder="Search by name"
              :disabled="busy || loading"
            />
            <select v-model="form.packaging_item_id" class="form-select mb-3" aria-label="Packaging" :disabled="busy || loading">
              <option value="">None</option>
              <option v-for="row in filteredPackaging" :key="row.id" :value="String(row.id)">
                {{ optionLabel(row, true) }}
              </option>
            </select>
            <label class="form-label" for="sip-bulk-mat-search">Packaging Materials</label>
            <input
              id="sip-bulk-mat-search"
              v-model="materialQuery"
              type="search"
              class="form-control mb-2"
              placeholder="Search by name"
              :disabled="busy || loading"
            />
            <select v-model="form.packaging_material_item_id" class="form-select" aria-label="Packaging Materials" :disabled="busy || loading">
              <option value="">None</option>
              <option v-for="row in filteredMaterials" :key="row.id" :value="String(row.id)">
                {{ optionLabel(row, false) }}
              </option>
            </select>
          </template>

          <template v-else-if="mode === 'weights'">
            <label class="form-label" for="sip-bulk-weight">Weight</label>
            <div class="d-flex gap-2">
              <input id="sip-bulk-weight" v-model="form.weight" type="number" min="0" step="0.01" class="form-control" :disabled="busy" />
              <select v-model="form.weight_unit" class="form-select" style="max-width: 8rem" aria-label="Weight unit" :disabled="busy">
                <option value="POUNDS">lbs</option>
                <option value="OUNCES">oz</option>
                <option value="GRAMS">g</option>
                <option value="KILOGRAMS">kg</option>
              </select>
            </div>
          </template>

          <template v-else>
            <div class="row g-3">
              <div class="col-4">
                <label class="form-label" for="sip-bulk-length">Length (in)</label>
                <input id="sip-bulk-length" v-model="form.length" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
              <div class="col-4">
                <label class="form-label" for="sip-bulk-width">Width (in)</label>
                <input id="sip-bulk-width" v-model="form.width" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
              <div class="col-4">
                <label class="form-label" for="sip-bulk-height">Height (in)</label>
                <input id="sip-bulk-height" v-model="form.height" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
              </div>
            </div>
            <p class="small text-secondary mt-3 mb-0">Cubic Ft: {{ cubicFeet }}</p>
          </template>
          <p v-if="loading" class="small text-secondary mt-3 mb-0">Loading…</p>
        </div>
        <footer class="crm-vx-modal__footer justify-content-end">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">Cancel</button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy || loading" @click="onSave">
            {{ busy ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
