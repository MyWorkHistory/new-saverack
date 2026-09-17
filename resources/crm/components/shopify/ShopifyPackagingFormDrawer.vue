<script setup>
import { computed, reactive, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";

const CATEGORIES = [
  { value: "packaging", label: "Packaging" },
  { value: "packaging_materials", label: "Packaging Materials" },
];

const TYPES = {
  packaging: [
    { value: "box", label: "Box" },
    { value: "poly_mailer", label: "Poly Mailer" },
    { value: "bubble_mailer", label: "Bubble Mailer" },
    { value: "kraft_mailer", label: "Kraft Mailer" },
  ],
  packaging_materials: [
    { value: "kraft_paper", label: "Kraft Paper" },
    { value: "bubble_wrap", label: "Bubble Wrap" },
    { value: "peanuts", label: "Peanuts" },
    { value: "tissue_paper", label: "Tissue Paper" },
  ],
};

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save"]);

const form = reactive(blankForm());
const typeOptions = computed(() => TYPES[form.category] || []);

function blankForm() {
  return {
    name: "",
    sku: "",
    category: "packaging",
    type: "box",
    cost: "",
    price: "",
    on_hand: "0",
    length: "",
    width: "",
    height: "",
    weight: "",
    link_url: "",
  };
}

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    Object.assign(form, blankForm());
  }
);

watch(
  () => form.category,
  (category) => {
    const options = TYPES[category] || [];
    if (!options.some((opt) => opt.value === form.type)) {
      form.type = options[0]?.value || "";
    }
  }
);

function blankToNull(value) {
  const text = String(value ?? "").trim();
  return text === "" ? null : text;
}

function onSave() {
  const name = String(form.name || "").trim();
  if (!name) return;
  emit("save", {
    name,
    sku: blankToNull(form.sku),
    category: form.category,
    type: form.type,
    cost: blankToNull(form.cost) == null ? 0 : Number(form.cost),
    price: blankToNull(form.price) == null ? 0 : Number(form.price),
    on_hand: Math.max(0, parseInt(form.on_hand, 10) || 0),
    length: blankToNull(form.length) == null ? null : Number(form.length),
    width: blankToNull(form.width) == null ? null : Number(form.width),
    height: blankToNull(form.height) == null ? null : Number(form.height),
    weight: blankToNull(form.weight) == null ? null : Number(form.weight),
    link_url: blankToNull(form.link_url),
  });
}

function close() {
  if (props.busy) return;
  emit("close");
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Add Packaging"
    :busy="busy"
    max-width="xl"
    @close="close"
    @update:open="(value) => { if (!value) close(); }"
  >
    <label class="form-label" for="pkg-add-name">Name</label>
    <input id="pkg-add-name" v-model="form.name" type="text" class="form-control mb-3" maxlength="255" :disabled="busy" />

    <label class="form-label" for="pkg-add-sku">SKU</label>
    <input id="pkg-add-sku" v-model="form.sku" type="text" class="form-control mb-3" maxlength="64" placeholder="Create SKU" :disabled="busy" />

    <label class="form-label" for="pkg-add-category">Category</label>
    <select id="pkg-add-category" v-model="form.category" class="form-select mb-3" :disabled="busy">
      <option v-for="opt in CATEGORIES" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>

    <label class="form-label" for="pkg-add-type">Type</label>
    <select id="pkg-add-type" v-model="form.type" class="form-select mb-3" :disabled="busy">
      <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>

    <label class="form-label" for="pkg-add-cost">Cost</label>
    <input id="pkg-add-cost" v-model="form.cost" type="number" min="0" step="0.01" class="form-control mb-3" :disabled="busy" />

    <label class="form-label" for="pkg-add-price">Price</label>
    <input id="pkg-add-price" v-model="form.price" type="number" min="0" step="0.01" class="form-control mb-3" :disabled="busy" />

    <label class="form-label" for="pkg-add-on-hand">On Hand</label>
    <input id="pkg-add-on-hand" v-model="form.on_hand" type="number" min="0" step="1" class="form-control mb-3" :disabled="busy" />

    <div class="row g-3">
      <div class="col-6">
        <label class="form-label" for="pkg-add-length">Length (in)</label>
        <input id="pkg-add-length" v-model="form.length" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
      </div>
      <div class="col-6">
        <label class="form-label" for="pkg-add-width">Width (in)</label>
        <input id="pkg-add-width" v-model="form.width" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
      </div>
      <div class="col-6">
        <label class="form-label" for="pkg-add-height">Height (in)</label>
        <input id="pkg-add-height" v-model="form.height" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
      </div>
      <div class="col-6">
        <label class="form-label" for="pkg-add-weight">Weight (lb)</label>
        <input id="pkg-add-weight" v-model="form.weight" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
      </div>
    </div>

    <label class="form-label mt-3" for="pkg-add-link">Link</label>
    <input id="pkg-add-link" v-model="form.link_url" type="url" class="form-control" maxlength="2048" placeholder="https://" :disabled="busy" />

    <template #footer>
      <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800">
        <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="close">Cancel</button>
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold"
          :disabled="busy || !String(form.name || '').trim()"
          @click="onSave"
        >
          {{ busy ? "Saving…" : "Add Packaging" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
