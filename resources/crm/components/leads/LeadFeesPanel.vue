<script setup>
import { computed, inject, ref, watch } from "vue";
import api from "../../services/api";
import AccountFeeAmountModal from "../clients/AccountFeeAmountModal.vue";
import PricingFeeList from "../settings/PricingFeeList.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { normalizeAccountFeeItems } from "../../utils/accountFees.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const props = defineProps({
  leadId: { type: [String, Number], required: true },
  lead: { type: Object, default: null },
});

const emit = defineEmits(["fees-updated"]);

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("leads.update");
});

const feeItems = computed(() => normalizeAccountFeeItems({ fees: props.lead?.fees || {} }));

const amountModalOpen = ref(false);
const amountModalFee = ref(null);
const amountSaving = ref(false);

function openFee(fee) {
  if (!canUpdate.value) return;
  amountModalFee.value = fee;
  amountModalOpen.value = true;
}

async function saveFeeAmount(payload) {
  const fee = amountModalFee.value;
  if (!fee?.id || amountSaving.value) return;
  amountSaving.value = true;
  try {
    const { data } = await api.patch(`/leads/${props.leadId}/fees/${fee.id}`, {
      amount: payload.amount,
      cost: payload.cost,
    });
    amountModalOpen.value = false;
    amountModalFee.value = null;
    emit("fees-updated", data);
    toast.success("Fee updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update fee.");
  } finally {
    amountSaving.value = false;
  }
}

watch(
  () => props.leadId,
  () => {
    amountModalOpen.value = false;
    amountModalFee.value = null;
  },
);
</script>

<template>
  <div>
    <AccountFeeAmountModal
      :open="amountModalOpen"
      :fee="amountModalFee"
      :saving="amountSaving"
      @close="amountModalOpen = false"
      @save="saveFeeAmount"
    />
    <div v-if="!feeItems.length" class="text-secondary py-4 text-center">
      No fee schedule loaded for this lead.
    </div>
    <PricingFeeList
      v-else
      variant="schedule"
      :fees="feeItems"
      :clickable="canUpdate"
      @select="openFee"
    />
    <div v-if="amountSaving" class="text-secondary small mt-2">
      <CrmLoadingSpinner small class="me-1" />
      Saving…
    </div>
  </div>
</template>
