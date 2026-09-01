<script setup>
import { computed, reactive, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variant: { type: Object, default: null },
  mode: { type: String, default: "type-only" },
});

const emit = defineEmits(["update:open", "save"]);

const form = reactive({
  status: "active",
  product_type: "standard",
});

const isTypeOnly = computed(() => props.mode === "type-only");

const modalTitle = computed(() =>
  isTypeOnly.value ? "Edit Product Type" : "Product Settings",
);

function reset() {
  const v = props.variant || {};
  const status = String(v.status || "active").toLowerCase();
  form.status = status === "inactive" ? "inactive" : "active";
  form.product_type = String(v.product_type || "").toLowerCase() === "bundle" ? "bundle" : "standard";
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
    status: form.status,
    product_type: form.product_type,
  });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="shopify-product-settings-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shopify-product-settings-title"
      @click.self="close"
    >
      <div class="shopify-product-settings" @click.stop>
        <header class="shopify-product-settings__head">
          <h2 id="shopify-product-settings-title" class="shopify-product-settings__title">
            {{ modalTitle }}
          </h2>
          <button
            type="button"
            class="shopify-product-settings__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="shopify-product-settings__body">
          <template v-if="!isTypeOnly">
            <label class="form-label" for="shopify-product-settings-status">Status</label>
            <select
              id="shopify-product-settings-status"
              v-model="form.status"
              class="form-select mb-3"
              :disabled="busy"
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </template>

          <label class="form-label" for="shopify-product-settings-type">Type</label>
          <select
            id="shopify-product-settings-type"
            v-model="form.product_type"
            class="form-select"
            :disabled="busy"
          >
            <option value="standard">Standard Product</option>
            <option value="bundle">Bundle</option>
          </select>
        </div>

        <footer class="shopify-product-settings__foot">
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
            {{ busy ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.shopify-product-settings-overlay {
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
.shopify-product-settings {
  width: 100%;
  max-width: 26rem;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}
.shopify-product-settings__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e5e7eb;
}
.shopify-product-settings__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.shopify-product-settings__close {
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
.shopify-product-settings__close:hover:not(:disabled) {
  background: #f3f4f6;
  color: #111827;
}
.shopify-product-settings__body {
  padding: 1.15rem 1.25rem;
}
.shopify-product-settings__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  padding: 0.9rem 1.25rem 1.15rem;
  border-top: 1px solid #e5e7eb;
}
</style>
