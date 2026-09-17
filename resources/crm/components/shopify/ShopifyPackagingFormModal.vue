<script setup>
import { computed, reactive, watch } from "vue";

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
  title: { type: String, default: "Add Packaging" },
  item: { type: Object, default: null },
});

const emit = defineEmits(["close", "save"]);

const form = reactive({
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
});

const typeOptions = computed(() => TYPES[form.category] || []);

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    const src = props.item || {};
    form.name = src.name || "";
    form.sku = src.sku || "";
    form.category = src.category || "packaging";
    form.type = src.type || TYPES[form.category]?.[0]?.value || "box";
    form.cost = src.cost ?? "";
    form.price = src.price ?? "";
    form.on_hand = src.on_hand == null || src.on_hand === "" ? "0" : String(src.on_hand);
    form.length = src.length == null ? "" : String(src.length);
    form.width = src.width == null ? "" : String(src.width);
    form.height = src.height == null ? "" : String(src.height);
    form.weight = src.weight == null ? "" : String(src.weight);
    form.link_url = src.link_url || "";
    const options = TYPES[form.category] || [];
    if (!options.some((opt) => opt.value === form.type)) {
      form.type = options[0]?.value || "";
    }
  },
  { immediate: true }
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
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="busy ? null : emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm packaging-form-modal" role="dialog" aria-modal="true" @click.stop>
        <button type="button" class="crm-vx-modal__close" aria-label="Close" :disabled="busy" @click="emit('close')">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <header class="crm-vx-modal__head" style="text-align: left">
          <h2 class="crm-vx-modal__title">{{ title }}</h2>
        </header>
        <div class="crm-vx-modal__body packaging-form-modal__body">
          <label class="form-label" for="pkg-name">Name</label>
          <input id="pkg-name" v-model="form.name" type="text" class="form-control mb-3" maxlength="255" :disabled="busy" />

          <label class="form-label" for="pkg-sku">SKU</label>
          <input id="pkg-sku" v-model="form.sku" type="text" class="form-control mb-3" maxlength="64" placeholder="Create SKU" :disabled="busy" />

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="pkg-category">Category</label>
              <select id="pkg-category" v-model="form.category" class="form-select" :disabled="busy">
                <option v-for="opt in CATEGORIES" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="pkg-type">Type</label>
              <select id="pkg-type" v-model="form.type" class="form-select" :disabled="busy">
                <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label" for="pkg-cost">Cost</label>
              <input id="pkg-cost" v-model="form.cost" type="number" min="0" step="0.01" class="form-control" :disabled="busy" />
            </div>
            <div class="col-md-4">
              <label class="form-label" for="pkg-price">Price</label>
              <input id="pkg-price" v-model="form.price" type="number" min="0" step="0.01" class="form-control" :disabled="busy" />
            </div>
            <div class="col-md-4">
              <label class="form-label" for="pkg-on-hand">On Hand</label>
              <input id="pkg-on-hand" v-model="form.on_hand" type="number" min="0" step="1" class="form-control" :disabled="busy" />
            </div>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <label class="form-label" for="pkg-length">Length (in)</label>
              <input id="pkg-length" v-model="form.length" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="pkg-width">Width (in)</label>
              <input id="pkg-width" v-model="form.width" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="pkg-height">Height (in)</label>
              <input id="pkg-height" v-model="form.height" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="pkg-weight">Weight (lb)</label>
              <input id="pkg-weight" v-model="form.weight" type="number" min="0" step="0.001" class="form-control" :disabled="busy" />
            </div>
          </div>

          <label class="form-label mt-3" for="pkg-link">Link</label>
          <input id="pkg-link" v-model="form.link_url" type="url" class="form-control" maxlength="2048" placeholder="https://" :disabled="busy" />
        </div>
        <footer class="crm-vx-modal__footer justify-content-end">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">Cancel</button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy || !String(form.name || '').trim()" @click="onSave">
            {{ busy ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.packaging-form-modal {
  max-width: 40rem;
}
.packaging-form-modal__body {
  text-align: left;
}
</style>
