<script setup>
import { computed, ref } from "vue";
import api from "../../services/api";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import WholesaleShippingLabelsDrawer from "./WholesaleShippingLabelsDrawer.vue";
import { wholesaleShippingLabelsProviderLabel } from "../../utils/formatWholesaleOrderDisplay.js";

const props = defineProps({
  order: { type: Object, required: true },
  editable: { type: Boolean, default: false },
  formattedAddress: { type: String, default: "" },
});

const emit = defineEmits(["saved"]);

const drawerOpen = ref(false);
const saving = ref(false);

const providerLabel = computed(() => {
  const fromApi = props.order?.shipping_labels_provider_label;
  if (fromApi) return fromApi;
  return wholesaleShippingLabelsProviderLabel(props.order?.shipping_labels_provider) || null;
});

const comment = computed(() => String(props.order?.shipping_labels_comment || "").trim());

const isClientProvides = computed(
  () => String(props.order?.shipping_labels_provider || "") === "client_provides",
);
const isSaveRackProvides = computed(
  () => String(props.order?.shipping_labels_provider || "") === "save_rack_provides",
);

function openDrawer() {
  if (!props.editable) return;
  drawerOpen.value = true;
}

async function onSaved(data) {
  saving.value = true;
  try {
    emit("saved", data);
  } finally {
    saving.value = false;
    drawerOpen.value = false;
  }
}

async function downloadLabel() {
  const id = props.order?.id;
  if (!id) return;
  try {
    const { data } = await api.get(`/admin/wholesale-orders/${id}/shipping-label.pdf`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = props.order?.shipping_label_original_name || "shipping-label.pdf";
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    window.open(`/api/admin/wholesale-orders/${id}/shipping-label.pdf`, "_blank");
  }
}
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
    <div class="wholesale-req-row wholesale-req-row--card-head">
      <div
        class="wholesale-req-row__icon"
        :style="{ background: '#dbeafe', color: '#1e3a8a' }"
        aria-hidden="true"
      >
        <CrmMaterialIcon name="localShipping" :size="20" />
      </div>
      <div class="wholesale-req-row__body min-w-0 flex-grow-1">
        <div class="wholesale-req-row__line">
          <span class="wholesale-req-row__label">Shipping Labels:</span>
          <span class="wholesale-req-row__value">{{ providerLabel || "—" }}</span>
        </div>
        <p v-if="comment" class="wholesale-req-row__comment mb-0">{{ comment }}</p>
        <template v-if="isSaveRackProvides && formattedAddress">
          <p class="wholesale-req-row__comment mb-0 mt-2" style="white-space: pre-line">
            {{ formattedAddress }}
          </p>
          <p
            v-if="order.shipping_carrier || order.shipping_method"
            class="wholesale-req-row__comment mb-0 mt-1"
          >
            Carrier: {{ order.shipping_carrier || "—" }} · Method: {{ order.shipping_method || "—" }}
          </p>
        </template>
        <button
          v-if="isClientProvides && order.has_shipping_label_file"
          type="button"
          class="btn btn-link btn-sm p-0 text-decoration-none mt-1"
          @click="downloadLabel"
        >
          {{ order.shipping_label_original_name || "Download shipping label" }}
        </button>
      </div>
      <button
        v-if="editable"
        type="button"
        class="btn btn-link btn-sm p-0 text-decoration-none flex-shrink-0 wholesale-req-row__edit"
        @click="openDrawer"
      >
        Edit
      </button>
    </div>

    <WholesaleShippingLabelsDrawer
      v-model:open="drawerOpen"
      :busy="saving"
      :order-id="order.id"
      :provider="order.shipping_labels_provider"
      :comment="order.shipping_labels_comment"
      :shipping-address="order.shipping_address"
      :shipping-carrier="order.shipping_carrier"
      :shipping-method="order.shipping_method"
      :has-shipping-label-file="order.has_shipping_label_file"
      :shipping-label-original-name="order.shipping_label_original_name"
      @saved="onSaved"
    />
  </div>
</template>

<style scoped>
.wholesale-req-row--card-head {
  padding: 0;
  border: 0;
}
</style>
