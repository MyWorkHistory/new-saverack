<script setup>
import { reactive, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(["update:open", "submit"]);
const error = ref("");
const form = reactive({
  name: "",
  quantity: "1",
  unit_price: "",
});

watch(() => props.open, (open) => {
  if (!open) return;
  error.value = "";
  Object.assign(form, { name: "", quantity: "1", unit_price: "" });
});

function submit() {
  const quantity = Number(form.quantity);
  const unitPrice = Number(form.unit_price);
  if (!form.name.trim() || !Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(unitPrice) || unitPrice < 0) {
    error.value = "Enter a name, quantity, and valid price.";
    return;
  }
  emit("submit", {
    line_type: "custom_new",
    source: "custom",
    client_account_fee_id: null,
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
          <h2 class="crm-vx-modal__title">Add Custom Fee</h2>
        </header>
        <div class="crm-vx-modal__body d-flex flex-column gap-3">
          <div>
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
