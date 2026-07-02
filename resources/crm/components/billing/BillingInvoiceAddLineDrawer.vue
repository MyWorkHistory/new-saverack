<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

defineProps({
  open: { type: Boolean, default: false },
  form: { type: Object, required: true },
  categoryOptions: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "submit"]);

function close() {
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Add To Invoice"
    :busy="busy"
    form-id="billing-invoice-add-line-form"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <label class="form-label">Service</label>
    <input v-model="form.display_name" type="text" class="form-control mb-2" :disabled="busy" />
    <label class="form-label">Category</label>
    <select v-model="form.category" class="form-select mb-2" :disabled="busy">
      <option value="">Select category</option>
      <option
        v-for="category in categoryOptions"
        :key="`add-category-${category.value}`"
        :value="category.value"
      >
        {{ category.label }}
      </option>
    </select>
    <label class="form-label">Order # (optional)</label>
    <input v-model="form.order_number" type="text" class="form-control mb-2" :disabled="busy" />
    <div class="row g-2">
      <div class="col-6">
        <label class="form-label">Qty</label>
        <input v-model="form.quantity" type="text" class="form-control text-end" :disabled="busy" />
      </div>
      <div class="col-6">
        <label class="form-label">Price</label>
        <input v-model="form.unit_price" type="text" class="form-control text-end" :disabled="busy" />
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="billing-invoice-add-line-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Saving…" : "Add" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
