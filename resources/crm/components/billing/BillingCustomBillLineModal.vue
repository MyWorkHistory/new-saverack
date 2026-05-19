<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  submitLabel: { type: String, default: "Save" },
  busy: { type: Boolean, default: false },
  errorMsg: { type: String, default: "" },
  categoryOptions: { type: Array, default: () => [] },
  category: { type: String, default: "" },
  name: { type: String, default: "" },
  quantity: { type: String, default: "1" },
  unitPrice: { type: String, default: "0.00" },
  sku: { type: String, default: "" },
});

const emit = defineEmits([
  "update:open",
  "update:category",
  "update:name",
  "update:quantity",
  "update:unitPrice",
  "update:sku",
  "submit",
]);

const formId = computed(() => `cb-line-form-${props.title.replace(/\s+/g, "-").toLowerCase()}`);

function close() {
  if (!props.busy) emit("update:open", false);
}

function onBackdrop() {
  close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="`${formId}-title`"
        @click.self="onBackdrop"
      >
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 :id="`${formId}-title`" class="crm-vx-modal__title">{{ title }}</h2>
          </header>

          <div class="crm-vx-modal__body">
            <p v-if="errorMsg" class="small text-danger text-center mb-3">
              {{ errorMsg }}
            </p>
            <form :id="formId" class="text-start" @submit.prevent="emit('submit')">
              <label class="form-label" :for="`${formId}-category`">Category</label>
              <select
                :id="`${formId}-category`"
                :value="category"
                class="form-select mb-2"
                :disabled="busy"
                required
                @change="emit('update:category', $event.target.value)"
              >
                <option value="">Select category</option>
                <option
                  v-for="opt in categoryOptions"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ opt.label }}
                </option>
              </select>

              <label class="form-label" :for="`${formId}-name`">Service / Name</label>
              <input
                :id="`${formId}-name`"
                :value="name"
                type="text"
                class="form-control mb-2"
                :disabled="busy"
                autocomplete="off"
                @input="emit('update:name', $event.target.value)"
              />

              <div class="row g-2 mb-2">
                <div class="col-6">
                  <label class="form-label" :for="`${formId}-qty`">Qty</label>
                  <input
                    :id="`${formId}-qty`"
                    :value="quantity"
                    type="number"
                    min="0.0001"
                    step="any"
                    class="form-control text-end"
                    :disabled="busy"
                    @input="emit('update:quantity', $event.target.value)"
                  />
                </div>
                <div class="col-6">
                  <label class="form-label" :for="`${formId}-price`">Price</label>
                  <input
                    :id="`${formId}-price`"
                    :value="unitPrice"
                    type="number"
                    step="0.01"
                    class="form-control text-end"
                    :disabled="busy"
                    @input="emit('update:unitPrice', $event.target.value)"
                  />
                </div>
              </div>

              <label class="form-label" :for="`${formId}-sku`">SKU (optional)</label>
              <input
                :id="`${formId}-sku`"
                :value="sku"
                type="text"
                class="form-control mb-0"
                :disabled="busy"
                autocomplete="off"
                @input="emit('update:sku', $event.target.value)"
              />
            </form>
          </div>

          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="busy"
              @click="close"
            >
              Cancel
            </button>
            <button
              type="submit"
              :form="formId"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="busy"
            >
              {{ busy ? "Saving…" : submitLabel }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.crm-vx-confirm-enter-active,
.crm-vx-confirm-leave-active {
  transition: opacity 0.2s ease;
}
.crm-vx-confirm-enter-from,
.crm-vx-confirm-leave-to {
  opacity: 0;
}
</style>
