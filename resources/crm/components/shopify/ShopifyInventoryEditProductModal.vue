<script setup>
import { computed, reactive, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variant: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "save"]);

const form = reactive({
  product_title: "",
  sku: "",
  barcode: "",
  length: "",
  width: "",
  height: "",
  dimension_unit: "INCHES",
  weight: "",
  weight_unit: "POUNDS",
});

const dimSuffix = computed(() =>
  String(form.dimension_unit || "").toUpperCase() === "CENTIMETERS" ? "cm" : "in",
);

const weightSuffix = computed(() => {
  switch (String(form.weight_unit || "").toUpperCase()) {
    case "OUNCES":
      return "oz";
    case "GRAMS":
      return "g";
    case "KILOGRAMS":
      return "kg";
    default:
      return "lbs";
  }
});

function reset() {
  const v = props.variant || {};
  form.product_title = v.product_title || "";
  form.sku = v.sku || "";
  form.barcode = v.barcode || "";
  form.length = v.length ?? "";
  form.width = v.width ?? "";
  form.height = v.height ?? "";
  form.dimension_unit = v.dimension_unit || "INCHES";
  form.weight = v.weight ?? "";
  form.weight_unit = v.weight_unit || "POUNDS";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) reset();
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function submit() {
  emit("save", {
    product_title: String(form.product_title || "").trim(),
    sku: String(form.sku || "").trim(),
    barcode: String(form.barcode || "").trim(),
    length: form.length === "" || form.length == null ? null : Number(form.length),
    width: form.width === "" || form.width == null ? null : Number(form.width),
    height: form.height === "" || form.height == null ? null : Number(form.height),
    dimension_unit: form.dimension_unit,
    weight: form.weight === "" || form.weight == null ? null : Number(form.weight),
    weight_unit: form.weight_unit,
  });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="shopify-edit-product-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shopify-edit-product-title"
      @click.self="close"
    >
      <div class="shopify-edit-product" @click.stop>
        <header class="shopify-edit-product__head">
          <h2 id="shopify-edit-product-title" class="shopify-edit-product__title">
            Edit Product
          </h2>
          <button
            type="button"
            class="shopify-edit-product__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="shopify-edit-product__body">
          <section class="shopify-edit-product__section">
            <h3 class="shopify-edit-product__section-title">Product Information</h3>
            <label class="form-label" for="shopify-edit-product-name">Product Name</label>
            <input
              id="shopify-edit-product-name"
              v-model="form.product_title"
              type="text"
              class="form-control mb-3"
              maxlength="500"
              :disabled="busy"
            />
            <label class="form-label" for="shopify-edit-sku">SKU</label>
            <input
              id="shopify-edit-sku"
              v-model="form.sku"
              type="text"
              class="form-control mb-3"
              maxlength="255"
              :disabled="busy"
            />
            <label class="form-label" for="shopify-edit-barcode">Barcode</label>
            <input
              id="shopify-edit-barcode"
              v-model="form.barcode"
              type="text"
              class="form-control"
              maxlength="255"
              :disabled="busy"
            />
          </section>

          <section class="shopify-edit-product__section">
            <h3 class="shopify-edit-product__section-title">Dimensions</h3>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label" for="shopify-edit-length">Length</label>
                <div class="shopify-edit-product__unit-field">
                  <input
                    id="shopify-edit-length"
                    v-model="form.length"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                    :disabled="busy"
                  />
                  <span class="shopify-edit-product__unit">{{ dimSuffix }}</span>
                </div>
              </div>
              <div class="col-4">
                <label class="form-label" for="shopify-edit-width">Width</label>
                <div class="shopify-edit-product__unit-field">
                  <input
                    id="shopify-edit-width"
                    v-model="form.width"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                    :disabled="busy"
                  />
                  <span class="shopify-edit-product__unit">{{ dimSuffix }}</span>
                </div>
              </div>
              <div class="col-4">
                <label class="form-label" for="shopify-edit-height">Height</label>
                <div class="shopify-edit-product__unit-field">
                  <input
                    id="shopify-edit-height"
                    v-model="form.height"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                    :disabled="busy"
                  />
                  <span class="shopify-edit-product__unit">{{ dimSuffix }}</span>
                </div>
              </div>
            </div>
          </section>

          <section class="shopify-edit-product__section shopify-edit-product__section--last">
            <h3 class="shopify-edit-product__section-title">Weight</h3>
            <label class="form-label" for="shopify-edit-weight">Net Weight</label>
            <div class="shopify-edit-product__unit-field" style="max-width: 10rem">
              <input
                id="shopify-edit-weight"
                v-model="form.weight"
                type="number"
                min="0"
                step="0.001"
                class="form-control"
                :disabled="busy"
              />
              <span class="shopify-edit-product__unit">{{ weightSuffix }}</span>
            </div>
          </section>
        </div>

        <footer class="shopify-edit-product__foot">
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="busy"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="busy"
            @click="submit"
          >
            {{ busy ? "Saving…" : "Save Changes" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.shopify-edit-product-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
}
.shopify-edit-product {
  width: 100%;
  max-width: 34rem;
  max-height: min(90vh, 40rem);
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}
.shopify-edit-product__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e5e7eb;
  flex-shrink: 0;
}
.shopify-edit-product__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.shopify-edit-product__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: #6b7280;
}
.shopify-edit-product__close:hover:not(:disabled) {
  background: #f3f4f6;
  color: #111827;
}
.shopify-edit-product__body {
  padding: 1.1rem 1.25rem;
  overflow-y: auto;
  min-height: 0;
}
.shopify-edit-product__section {
  padding-bottom: 1.1rem;
  margin-bottom: 1.1rem;
  border-bottom: 1px solid #e5e7eb;
}
.shopify-edit-product__section--last {
  border-bottom: 0;
  margin-bottom: 0;
  padding-bottom: 0;
}
.shopify-edit-product__section-title {
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: #111827;
}
.shopify-edit-product__unit-field {
  position: relative;
}
.shopify-edit-product__unit-field .form-control {
  padding-right: 2.35rem;
}
.shopify-edit-product__unit {
  position: absolute;
  right: 0.7rem;
  top: 50%;
  transform: translateY(-50%);
  font-size: 0.8rem;
  font-weight: 600;
  color: #9ca3af;
  pointer-events: none;
}
.shopify-edit-product__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.9rem 1.25rem;
  border-top: 1px solid #e5e7eb;
  flex-shrink: 0;
}
</style>
