<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variant: { type: Object, default: null },
});

const emit = defineEmits(["close", "save"]);

const loading = ref(false);
const packagingOptions = ref([]);
const materialOptions = ref([]);
const packagingId = ref("");
const materialId = ref("");
const packagingQuery = ref("");
const materialQuery = ref("");

const filteredPackaging = computed(() => filterOptions(packagingOptions.value, packagingQuery.value));
const filteredMaterials = computed(() => filterOptions(materialOptions.value, materialQuery.value));

function filterOptions(rows, query) {
  const q = String(query || "").trim().toLowerCase();
  if (!q) return rows;
  return rows.filter((row) => {
    const hay = `${row.type_label || ""} ${row.name || ""}`.toLowerCase();
    return hay.includes(q);
  });
}

function optionLabel(row, withType) {
  if (!row) return "";
  if (withType && row.type_label && row.name && row.type_label !== row.name) {
    return `${row.type_label}: ${row.name}`;
  }
  return row.name || row.type_label || "Packaging";
}

async function loadOptions() {
  loading.value = true;
  try {
    const [packaging, materials] = await Promise.all([
      api.get("/shopify/packaging", { params: { category: "packaging", per_page: 100 } }),
      api.get("/shopify/packaging", { params: { category: "packaging_materials", per_page: 100 } }),
    ]);
    packagingOptions.value = packaging.data?.data || [];
    materialOptions.value = materials.data?.data || [];
  } catch {
    packagingOptions.value = [];
    materialOptions.value = [];
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    packagingId.value = props.variant?.packaging?.id ? String(props.variant.packaging.id) : "";
    materialId.value = props.variant?.packaging_material?.id ? String(props.variant.packaging_material.id) : "";
    packagingQuery.value = "";
    materialQuery.value = "";
    loadOptions();
  }
);

function onSave() {
  emit("save", {
    packaging_item_id: packagingId.value ? Number(packagingId.value) : null,
    packaging_material_item_id: materialId.value ? Number(materialId.value) : null,
  });
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="busy ? null : emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" role="dialog" aria-modal="true" aria-labelledby="inv-packaging-title" @click.stop>
        <button type="button" class="crm-vx-modal__close" aria-label="Close" :disabled="busy" @click="emit('close')">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <header class="crm-vx-modal__head" style="text-align: left">
          <h2 id="inv-packaging-title" class="crm-vx-modal__title">Edit Packaging</h2>
        </header>
        <div class="crm-vx-modal__body" style="text-align: left">
          <label class="form-label" for="inv-packaging-search">Packaging</label>
          <input
            id="inv-packaging-search"
            v-model="packagingQuery"
            type="search"
            class="form-control mb-2"
            placeholder="Search by name"
            :disabled="busy || loading"
          />
          <select v-model="packagingId" class="form-select mb-3" aria-label="Packaging" :disabled="busy || loading">
            <option value="">None</option>
            <option v-for="row in filteredPackaging" :key="row.id" :value="String(row.id)">
              {{ optionLabel(row, true) }}
            </option>
          </select>

          <label class="form-label" for="inv-material-search">Packaging Materials</label>
          <input
            id="inv-material-search"
            v-model="materialQuery"
            type="search"
            class="form-control mb-2"
            placeholder="Search by name"
            :disabled="busy || loading"
          />
          <select v-model="materialId" class="form-select" aria-label="Packaging Materials" :disabled="busy || loading">
            <option value="">None</option>
            <option v-for="row in filteredMaterials" :key="row.id" :value="String(row.id)">
              {{ optionLabel(row, false) }}
            </option>
          </select>
          <p v-if="loading" class="small text-secondary mt-3 mb-0">Loading Packaging…</p>
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
