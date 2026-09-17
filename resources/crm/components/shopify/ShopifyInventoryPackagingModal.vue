<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variant: { type: Object, default: null },
});

const emit = defineEmits(["close", "save"]);

const loading = ref(false);
const packagingOptions = ref([]);
const materialOptions = ref([]);
const selectedPackaging = ref([]);
const selectedMaterials = ref([]);
const packagingPick = ref("");
const materialPick = ref("");

const availablePackaging = computed(() => selectOptions(packagingOptions.value, selectedPackaging.value));
const availableMaterials = computed(() => selectOptions(materialOptions.value, selectedMaterials.value));

function optionLabel(row) {
  if (!row) return "";
  return row.name || row.type_label || "Packaging";
}

function selectOptions(rows, selected) {
  const taken = new Set(selected.map((row) => Number(row.id)));
  return rows
    .filter((row) => !taken.has(Number(row.id)))
    .map((row) => ({
      id: row.id,
      name: optionLabel(row),
    }));
}

function assignedRows(listKey, singleKey) {
  const list = props.variant?.[listKey];
  if (Array.isArray(list) && list.length) {
    return list.filter((row) => row?.id).map((row) => ({ ...row }));
  }
  const single = props.variant?.[singleKey];
  return single?.id ? [{ ...single }] : [];
}

async function loadOptions() {
  loading.value = true;
  try {
    const [packaging, materials] = await Promise.all([
      api.get("/shopify/packaging", { params: { category: "packaging", per_page: 1000 } }),
      api.get("/shopify/packaging", { params: { category: "packaging_materials", per_page: 1000 } }),
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

function addPicked(pick, selected, options) {
  const id = String(pick.value || "");
  if (!id) return;
  const row = options.value.find((item) => String(item.id) === id);
  if (row && !selected.value.some((item) => Number(item.id) === Number(row.id))) {
    selected.value = [
      ...selected.value,
      {
        id: row.id,
        name: row.name,
        label: optionLabel(row),
      },
    ];
  }
  pick.value = "";
}

watch(packagingPick, () => addPicked(packagingPick, selectedPackaging, packagingOptions));
watch(materialPick, () => addPicked(materialPick, selectedMaterials, materialOptions));

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    selectedPackaging.value = assignedRows("packaging_items", "packaging");
    selectedMaterials.value = assignedRows("packaging_materials", "packaging_material");
    packagingPick.value = "";
    materialPick.value = "";
    loadOptions();
  },
);

function removePackaging(id) {
  selectedPackaging.value = selectedPackaging.value.filter((row) => Number(row.id) !== Number(id));
}

function removeMaterial(id) {
  selectedMaterials.value = selectedMaterials.value.filter((row) => Number(row.id) !== Number(id));
}

function onSave() {
  emit("save", {
    packaging_item_ids: selectedPackaging.value.map((row) => Number(row.id)),
    packaging_material_item_ids: selectedMaterials.value.map((row) => Number(row.id)),
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
          <label class="form-label">Packaging</label>
          <CrmSearchableSelect
            v-model="packagingPick"
            class="mb-2"
            appearance="staff"
            aria-label="Packaging"
            :options="availablePackaging"
            :disabled="busy || loading"
            :allow-empty="false"
            placeholder="Select Packaging"
            empty-label="Select Packaging"
            search-placeholder="Search packaging…"
            teleport-panel
          />
          <ul v-if="selectedPackaging.length" class="inv-pack-picks mb-3">
            <li v-for="row in selectedPackaging" :key="row.id" class="inv-pack-pick">
              <span class="text-truncate">{{ row.name || row.label }}</span>
              <button type="button" class="inv-pack-pick__remove" :aria-label="`Remove ${row.label || row.name}`" :disabled="busy" @click.stop="removePackaging(row.id)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </li>
          </ul>
          <div v-else class="mb-3"></div>

          <label class="form-label">Packaging Materials</label>
          <CrmSearchableSelect
            v-model="materialPick"
            class="mb-2"
            appearance="staff"
            aria-label="Packaging Materials"
            :options="availableMaterials"
            :disabled="busy || loading"
            :allow-empty="false"
            placeholder="Select Packaging Materials"
            empty-label="Select Packaging Materials"
            search-placeholder="Search packaging materials…"
            teleport-panel
          />
          <ul v-if="selectedMaterials.length" class="inv-pack-picks">
            <li v-for="row in selectedMaterials" :key="row.id" class="inv-pack-pick">
              <span class="text-truncate">{{ row.name || row.label }}</span>
              <button type="button" class="inv-pack-pick__remove" :aria-label="`Remove ${row.label || row.name}`" :disabled="busy" @click.stop="removeMaterial(row.id)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </li>
          </ul>
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

<style scoped>
.inv-pack-picks {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.inv-pack-pick {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 0.4rem 0.55rem 0.4rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: #111827;
  background: #f8fafc;
}
.inv-pack-pick__remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: #64748b;
  flex-shrink: 0;
}
.inv-pack-pick__remove:hover:not(:disabled) {
  background: #e2e8f0;
  color: #111827;
}
</style>
