<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  submitLabel: { type: String, default: "Save" },
  busy: { type: Boolean, default: false },
  errorMsg: { type: String, default: "" },
  chargeOptions: { type: Array, default: () => [] },
  lineType: { type: String, default: "" },
  name: { type: String, default: "" },
  quantity: { type: String, default: "1" },
  unitPrice: { type: String, default: "0.00" },
});

const emit = defineEmits([
  "update:open",
  "update:lineType",
  "update:name",
  "update:quantity",
  "update:unitPrice",
  "submit",
]);

const formId = computed(() => `asn-line-form-${props.title.replace(/\s+/g, "-").toLowerCase()}`);

function close() {
  if (!props.busy) emit("update:open", false);
}

function onLineTypeChange(event) {
  const value = event.target.value;
  emit("update:lineType", value);
  const opt = props.chargeOptions.find((o) => o.line_type === value);
  if (opt && !props.name) {
    emit("update:name", opt.display_name || "");
  }
  if (opt) {
    emit("update:unitPrice", ((Number(opt.default_unit_price_cents) || 0) / 100).toFixed(2));
  }
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
        @click.self="close"
      >
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 :id="`${formId}-title`" class="crm-vx-modal__title">{{ title }}</h2>
          </header>

          <div class="crm-vx-modal__body">
            <p v-if="errorMsg" class="small text-danger text-center mb-3">{{ errorMsg }}</p>

            <div class="mb-3">
              <label class="form-label" :for="`${formId}-line-type`">Service type</label>
              <select
                :id="`${formId}-line-type`"
                class="form-select"
                :value="lineType"
                :disabled="busy"
                @change="onLineTypeChange"
              >
                <option value="">Select…</option>
                <option v-for="opt in chargeOptions" :key="opt.line_type" :value="opt.line_type">
                  {{ opt.display_name }}
                </option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label" :for="`${formId}-name`">Service / Name</label>
              <input
                :id="`${formId}-name`"
                type="text"
                class="form-control"
                :value="name"
                :disabled="busy"
                @input="emit('update:name', $event.target.value)"
              />
            </div>

            <div class="row g-3">
              <div class="col-6">
                <label class="form-label" :for="`${formId}-qty`">Qty</label>
                <input
                  :id="`${formId}-qty`"
                  type="number"
                  min="0.0001"
                  step="any"
                  class="form-control"
                  :value="quantity"
                  :disabled="busy"
                  @input="emit('update:quantity', $event.target.value)"
                />
              </div>
              <div class="col-6">
                <label class="form-label" :for="`${formId}-price`">Unit price</label>
                <input
                  :id="`${formId}-price`"
                  type="number"
                  min="0"
                  step="0.01"
                  class="form-control"
                  :value="unitPrice"
                  :disabled="busy"
                  @input="emit('update:unitPrice', $event.target.value)"
                />
              </div>
            </div>
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
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="busy"
              @click="emit('submit')"
            >
              {{ busy ? "Saving…" : submitLabel }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
