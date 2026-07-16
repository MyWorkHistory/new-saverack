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

const shippingLabels = computed(() =>
  Array.isArray(props.order?.shipping_labels) ? props.order.shipping_labels : [],
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

async function downloadLabel(label) {
  const id = props.order?.id;
  if (!id) return;
  const qs = label?.id && !label.legacy ? `?label_id=${label.id}` : "";
  try {
    const { data } = await api.get(`/admin/wholesale-orders/${id}/shipping-label.pdf${qs}`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = label?.original_name || "shipping-label.pdf";
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    window.open(`/api/admin/wholesale-orders/${id}/shipping-label.pdf${qs}`, "_blank");
  }
}
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
      <h3 class="h6 fw-semibold mb-0">Shipping &amp; Handling</h3>
    </div>

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

        <ul
          v-if="isClientProvides && shippingLabels.length"
          class="list-unstyled mb-0 mt-2"
        >
          <li
            v-for="label in shippingLabels"
            :key="label.id"
            class="d-flex align-items-center gap-2 py-1"
          >
            <CrmMaterialIcon name="description" :size="18" class="text-primary flex-shrink-0" />
            <button
              type="button"
              class="btn btn-link btn-sm p-0 text-decoration-none text-truncate"
              @click="downloadLabel(label)"
            >
              {{ label.original_name || "Shipping label" }}
            </button>
          </li>
        </ul>
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
      :shipping-labels="shippingLabels"
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
