<script setup>
import { computed, ref } from "vue";
import api from "../../services/api";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import WholesaleShippingLabelsDrawer from "./WholesaleShippingLabelsDrawer.vue";
import { useToast } from "../../composables/useToast.js";
import { wholesaleShippingLabelsProviderLabel } from "../../utils/formatWholesaleOrderDisplay.js";

const props = defineProps({
  order: { type: Object, required: true },
  editable: { type: Boolean, default: false },
  /** @deprecated Card builds address lines from order.shipping_address */
  formattedAddress: { type: String, default: "" },
});

const emit = defineEmits(["saved"]);

const toast = useToast();
const drawerOpen = ref(false);
const saving = ref(false);
const deleteBusyId = ref(null);

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

const addressLines = computed(() => {
  const a =
    props.order?.shipping_address && typeof props.order.shipping_address === "object"
      ? props.order.shipping_address
      : {};
  const name = [a.first_name, a.last_name].map((p) => String(p || "").trim()).filter(Boolean).join(" ");
  const company = String(a.company || "").trim();
  const street1 = String(a.address1 || "").trim();
  const street2 = String(a.address2 || "").trim();
  const cityStateZip = [a.city, a.state, a.zip]
    .map((p) => String(p || "").trim())
    .filter(Boolean)
    .join(", ")
    .replace(/,\s*,/g, ",");
  // Prefer "City, ST 12345" style
  const city = String(a.city || "").trim();
  const state = String(a.state || "").trim();
  const zip = String(a.zip || "").trim();
  let locality = "";
  if (city || state || zip) {
    locality = [city, [state, zip].filter(Boolean).join(" ")].filter(Boolean).join(", ");
  } else if (cityStateZip) {
    locality = cityStateZip;
  }
  const country = String(a.country || "").trim();
  const lines = [];
  if (name) lines.push(name);
  if (company) lines.push(company);
  if (street1) lines.push(street1);
  if (street2) lines.push(street2);
  if (locality) lines.push(locality);
  if (country && country.toUpperCase() !== "US" && country.toUpperCase() !== "USA") {
    lines.push(country);
  } else if (country) {
    lines.push(country);
  }
  if (lines.length) return lines;
  // Fallback to preformatted string from parent
  const raw = String(props.formattedAddress || "").trim();
  return raw ? raw.split(/\n+/).map((l) => l.trim()).filter(Boolean) : [];
});

const carrierLine = computed(() => {
  const carrier = String(props.order?.shipping_carrier || "").trim();
  const method = String(props.order?.shipping_method || "").trim();
  if (!carrier && !method) return "";
  if (carrier && method) return `${carrier} · ${method}`;
  return carrier || method;
});

const busy = computed(() => saving.value || deleteBusyId.value !== null);

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

async function deleteLabel(label) {
  if (!props.editable || !label?.id || label.legacy) {
    if (label?.legacy) {
      toast.error("This legacy label cannot be removed here. Use Edit to re-upload.");
    }
    return;
  }
  deleteBusyId.value = label.id;
  try {
    const { data } = await api.delete(
      `/admin/wholesale-orders/${props.order.id}/shipping-labels/${label.id}`,
    );
    emit("saved", data);
    toast.success("Shipping Label Removed.");
  } catch (e) {
    toast.errorFrom(e, "Could Not Delete Label.");
  } finally {
    deleteBusyId.value = null;
  }
}
</script>

<template>
  <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
      <h3 class="h6 fw-semibold mb-0">Shipping &amp; Handling</h3>
    </div>

    <div class="wholesale-shipping-labels-block">
      <div class="wholesale-shipping-labels-head">
        <div
          class="wholesale-shipping-labels-head__icon"
          :style="{ background: '#dbeafe', color: '#1e3a8a' }"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="localShipping" :size="20" />
        </div>
        <div class="wholesale-shipping-labels-head__title min-w-0 flex-grow-1">
          <h4 class="wholesale-shipping-labels-head__name mb-0">Shipping Labels</h4>
          <p v-if="providerLabel" class="wholesale-shipping-labels-head__provider mb-0">
            {{ providerLabel }}
          </p>
          <p v-else class="wholesale-shipping-labels-head__provider wholesale-shipping-labels-head__provider--empty mb-0">
            —
          </p>
        </div>
        <button
          v-if="editable"
          type="button"
          class="btn btn-link btn-sm p-0 text-decoration-none flex-shrink-0"
          :disabled="busy"
          @click="openDrawer"
        >
          Edit
        </button>
      </div>

      <p v-if="comment" class="wholesale-shipping-labels-comment mb-0">{{ comment }}</p>

      <template v-if="isSaveRackProvides && addressLines.length">
        <div class="wholesale-shipping-labels-address mt-3">
          <p
            v-for="(line, idx) in addressLines"
            :key="'addr-' + idx"
            class="wholesale-shipping-labels-address__line mb-0"
          >
            {{ line }}
          </p>
          <p v-if="carrierLine" class="wholesale-shipping-labels-address__carrier mb-0 mt-2">
            <span class="wholesale-shipping-labels-address__carrier-label">Carrier</span>
            {{ carrierLine }}
          </p>
        </div>
      </template>

      <template v-if="isClientProvides">
        <ul
          v-if="shippingLabels.length"
          class="list-unstyled mb-0 mt-3 wholesale-shipping-labels-files"
        >
          <li
            v-for="label in shippingLabels"
            :key="label.id"
            class="wholesale-shipping-labels-file"
          >
            <CrmMaterialIcon name="description" :size="20" class="text-primary flex-shrink-0" />
            <button
              type="button"
              class="btn btn-link btn-sm p-0 text-decoration-none text-truncate flex-grow-1 text-start wholesale-shipping-labels-file__name"
              @click="downloadLabel(label)"
            >
              {{ label.original_name || "Shipping Label" }}
            </button>
            <button
              v-if="editable && !label.legacy"
              type="button"
              class="btn btn-link btn-sm text-danger p-0 flex-shrink-0 wholesale-shipping-labels-file__x"
              :disabled="busy"
              aria-label="Delete Label"
              @click="deleteLabel(label)"
            >
              ×
            </button>
          </li>
        </ul>
        <button
          v-if="editable"
          type="button"
          class="btn btn-link btn-sm p-0 text-decoration-none mt-2"
          :disabled="busy"
          @click="openDrawer"
        >
          {{ shippingLabels.length ? "Upload More" : "Upload Labels" }}
        </button>
      </template>
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
.wholesale-shipping-labels-head {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.wholesale-shipping-labels-head__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.4375rem;
}

.wholesale-shipping-labels-head__name {
  font-size: 0.875rem;
  font-weight: 700;
  line-height: 1.35;
  color: var(--bs-body-color);
}

.wholesale-shipping-labels-head__provider {
  margin-top: 0.15rem;
  font-size: 0.8125rem;
  line-height: 1.35;
  color: var(--bs-body-color);
}

.wholesale-shipping-labels-head__provider--empty {
  color: var(--bs-secondary-color);
}

.wholesale-shipping-labels-comment {
  margin-top: 0.5rem;
  padding-left: calc(2.25rem + 0.75rem);
  font-size: 0.75rem;
  color: var(--bs-secondary-color);
  line-height: 1.35;
  white-space: pre-wrap;
}

.wholesale-shipping-labels-address {
  padding-left: calc(2.25rem + 0.75rem);
}

.wholesale-shipping-labels-address__line {
  font-size: 0.8125rem;
  line-height: 1.45;
  color: var(--bs-body-color);
}

.wholesale-shipping-labels-address__carrier {
  font-size: 0.8125rem;
  line-height: 1.45;
  color: var(--bs-body-color);
}

.wholesale-shipping-labels-address__carrier-label {
  font-weight: 700;
  margin-right: 0.35rem;
}

.wholesale-shipping-labels-files {
  padding-left: calc(2.25rem + 0.75rem);
}

.wholesale-shipping-labels-file {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0;
}

.wholesale-shipping-labels-file + .wholesale-shipping-labels-file {
  border-top: 1px solid var(--bs-border-color);
}

.wholesale-shipping-labels-file__name {
  font-size: 0.8125rem;
}

.wholesale-shipping-labels-file__x {
  font-size: 1.125rem;
  line-height: 1;
  min-width: 1.25rem;
}
</style>
