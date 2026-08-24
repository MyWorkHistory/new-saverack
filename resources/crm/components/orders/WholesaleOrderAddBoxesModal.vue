<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderId: { type: [String, Number], required: true },
});

const emit = defineEmits(["update:open", "submit"]);

const loading = ref(false);
const rows = ref([]);
const error = ref("");

const selectedRows = computed(() => rows.value.filter((row) => row.checked));

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      loadSuggestions();
    } else {
      rows.value = [];
      error.value = "";
    }
  },
);

async function loadSuggestions() {
  if (!props.orderId) return;
  loading.value = true;
  error.value = "";
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${props.orderId}/line-boxes/fee-suggestions`,
    );
    rows.value = (Array.isArray(data?.rows) ? data.rows : []).map((row) => ({
      ...row,
      checked: true,
    }));
    if (!rows.value.length) {
      error.value = "Add box dimensions on line items before billing boxes.";
    }
  } catch (e) {
    error.value = e?.response?.data?.message || "Could not load box fee suggestions.";
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function formatPrice(row) {
  const n = Number(row?.unit_price);
  if (!Number.isFinite(n)) return "$0.00";
  return `$${n.toFixed(2)}`;
}

function submit() {
  if (!selectedRows.value.length) {
    error.value = "Select at least one box row.";
    return;
  }
  emit(
    "submit",
    selectedRows.value.map((row) => ({
      line_type: row.line_type,
      client_account_fee_id: row.client_account_fee_id,
      name: row.display_name,
      quantity: row.quantity,
      unit_price: row.unit_price,
    })),
  );
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('update:open', false)">
      <div class="crm-vx-modal crm-vx-modal--lg" role="dialog" aria-modal="true">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Add Boxes</h2>
          <p class="crm-vx-modal__subtitle mb-0">
            Match line box sizes to account packaging fees and add them to pricing.
          </p>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loading" class="py-4 text-center text-secondary small">Loading box sizes…</div>
          <div v-else-if="rows.length" class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col" class="text-center" style="width: 2.5rem">
                    <span class="visually-hidden">Include</span>
                  </th>
                  <th scope="col">Box</th>
                  <th scope="col" class="text-end">QTY</th>
                  <th scope="col" class="text-end">Price</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in rows" :key="`${row.length}-${row.width}-${row.height}-${index}`">
                  <td class="text-center">
                    <input v-model="row.checked" type="checkbox" class="form-check-input m-0" :disabled="busy" />
                  </td>
                  <td>{{ row.display_name }}</td>
                  <td class="text-end">{{ row.quantity }}</td>
                  <td class="text-end">{{ formatPrice(row) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-secondary small mb-0">{{ error || "No box sizes found." }}</p>
          <p v-if="error && rows.length" class="text-danger small mt-2 mb-0">{{ error }}</p>
        </div>
        <footer class="crm-vx-modal__footer">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy"
            @click="emit('update:open', false)"
          >
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || loading || !selectedRows.length"
            @click="submit"
          >
            {{ busy ? "Adding…" : "Add to Fees" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
