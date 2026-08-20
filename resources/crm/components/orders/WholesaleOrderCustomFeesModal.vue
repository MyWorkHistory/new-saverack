<script setup>
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  packagingOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:open", "submit"]);
const error = ref("");
const form = reactive({
  kind: "packaging",
  client_account_fee_id: "",
  name: "",
  quantity: "1",
  unit_price: "",
});

const selectedOption = computed(() =>
  props.packagingOptions.find((option) => String(option.client_account_fee_id) === String(form.client_account_fee_id)),
);

watch(() => form.client_account_fee_id, () => {
  if (!selectedOption.value) return;
  form.name = selectedOption.value.display_name || "";
  form.unit_price = ((Number(selectedOption.value.default_unit_price_cents) || 0) / 100).toFixed(2);
});

watch(() => props.open, (open) => {
  if (!open) return;
  error.value = "";
  Object.assign(form, { kind: "packaging", client_account_fee_id: "", name: "", quantity: "1", unit_price: "" });
});

function submit() {
  const quantity = Number(form.quantity);
  const unitPrice = Number(form.unit_price);
  if (!form.name.trim() || !Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(unitPrice) || unitPrice < 0) {
    error.value = "Enter a name, quantity, and valid price.";
    return;
  }
  if (form.kind === "packaging" && !selectedOption.value) {
    error.value = "Select a packaging fee.";
    return;
  }
  emit("submit", {
    line_type: form.kind === "packaging" ? selectedOption.value.line_type : "custom_new",
    source: form.kind,
    client_account_fee_id: form.kind === "packaging" ? Number(form.client_account_fee_id) : null,
    name: form.name.trim(),
    quantity,
    unit_price: unitPrice,
  });
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('update:open', false)">
      <div class="crm-vx-modal" role="dialog" aria-modal="true">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Add Custom Fees</h2>
        </header>
        <div class="crm-vx-modal__body d-flex flex-column gap-3">
          <div>
            <label class="form-label">Fee Type</label>
            <select v-model="form.kind" class="form-select" :disabled="busy">
              <option value="packaging">Packaging</option>
              <option value="custom">Custom</option>
            </select>
          </div>
          <div v-if="form.kind === 'packaging'">
            <label class="form-label">Packaging Fee</label>
            <select v-model="form.client_account_fee_id" class="form-select" :disabled="busy">
              <option value="">Select Packaging Fee</option>
              <option v-for="option in packagingOptions" :key="option.client_account_fee_id" :value="option.client_account_fee_id">
                {{ option.display_name }}
              </option>
            </select>
          </div>
          <div v-else>
            <label class="form-label">Name</label>
            <input v-model="form.name" class="form-control" maxlength="255" :disabled="busy" />
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Quantity</label>
              <input v-model="form.quantity" type="number" min="0.0001" step="any" class="form-control" :disabled="busy" />
            </div>
            <div class="col-6">
              <label class="form-label">Price</label>
              <input v-model="form.unit_price" type="number" min="0" step="0.01" class="form-control" :disabled="busy" />
            </div>
          </div>
          <p v-if="error" class="small text-danger mb-0">{{ error }}</p>
        </div>
        <footer class="crm-vx-modal__footer">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('update:open', false)">Cancel</button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="submit">
            {{ busy ? "Saving…" : "Add Fee" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
