<script setup>
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import AccountFeeAmountModal from "../clients/AccountFeeAmountModal.vue";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  returnId: { type: [Number, String], default: null },
  fees: { type: Object, default: () => ({}) },
  editable: { type: Boolean, default: false },
  returnBillId: { type: [Number, String], default: null },
});

const emit = defineEmits(["update:fees"]);

const toast = useToast();
const localFees = ref({});
const feeModalOpen = ref(false);
const feeModalSaving = ref(false);
const feeEditTarget = ref(null);

watch(
  () => props.fees,
  (value) => {
    localFees.value = { ...(value || {}) };
  },
  { immediate: true, deep: true },
);

const feeRows = computed(() => [
  {
    key: "first_item",
    name: localFees.value.first_item_label || "Returns (First Item)",
    amount: localFees.value.first_item,
  },
  {
    key: "additional_item",
    name: localFees.value.additional_item_label || "Returns (Additional Items)",
    amount: localFees.value.additional_item,
  },
]);

function formatFeeAmount(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return "—";
  return `$${n.toFixed(2)}`;
}

function openFeeModal(row) {
  if (!props.editable || localFees.value.locked) return;
  feeEditTarget.value = row;
  feeModalOpen.value = true;
}

function closeFeeModal() {
  if (feeModalSaving.value) return;
  feeModalOpen.value = false;
  feeEditTarget.value = null;
}

async function saveFeeAmount({ amount }) {
  if (!feeEditTarget.value) return;
  const key = feeEditTarget.value.key;
  const parsed = amount === null || amount === "" ? null : Number(amount);
  if (parsed !== null && (!Number.isFinite(parsed) || parsed < 0)) {
    toast.error("Enter a valid price.");
    return;
  }

  const payload = {};
  if (key === "first_item") payload.first_item = parsed;
  if (key === "additional_item") payload.additional_item = parsed;

  if (props.returnId) {
    feeModalSaving.value = true;
    try {
      const { data } = await api.patch(`/admin/returns/${props.returnId}/fees`, payload);
      localFees.value = { ...localFees.value, ...(data?.return_fees || {}) };
      emit("update:fees", { ...localFees.value });
      feeModalOpen.value = false;
      feeEditTarget.value = null;
      toast.success("Return fees updated.");
    } catch (e) {
      toast.errorFrom(e, "Could not update return fees.");
    } finally {
      feeModalSaving.value = false;
    }
    return;
  }

  if (key === "first_item") localFees.value.first_item = parsed;
  if (key === "additional_item") localFees.value.additional_item = parsed;
  emit("update:fees", { ...localFees.value });
  feeModalOpen.value = false;
  feeEditTarget.value = null;
}

const modalFee = computed(() => {
  if (!feeEditTarget.value) return null;
  return {
    name: feeEditTarget.value.name,
    amount: feeEditTarget.value.amount,
  };
});
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
    <h3 class="h6 fw-semibold mb-2">Return Fees</h3>
    <ul class="list-unstyled mb-0 small">
      <li
        v-for="row in feeRows"
        :key="row.key"
        class="d-flex justify-content-between align-items-start gap-2 mb-2"
      >
        <span class="min-w-0">{{ row.name }}</span>
        <button
          v-if="editable && !localFees.locked"
          type="button"
          class="btn btn-link btn-sm p-0 text-secondary text-decoration-none flex-shrink-0"
          @click="openFeeModal(row)"
        >
          {{ formatFeeAmount(row.amount) }}
        </button>
        <span v-else class="text-secondary flex-shrink-0">{{ formatFeeAmount(row.amount) }}</span>
      </li>
    </ul>
    <p v-if="localFees.locked" class="small text-secondary mb-0 mt-2">Fees locked after processing.</p>
    <RouterLink
      v-if="returnBillId"
      :to="{ name: 'billing-return-bill-detail', params: { id: String(returnBillId) } }"
      class="small d-inline-block mt-2"
      target="_blank"
      rel="noopener noreferrer"
    >
      View Return Bill
    </RouterLink>

    <AccountFeeAmountModal
      :open="feeModalOpen"
      :fee="modalFee"
      :saving="feeModalSaving"
      @close="closeFeeModal"
      @save="saveFeeAmount"
    />
  </div>
</template>
