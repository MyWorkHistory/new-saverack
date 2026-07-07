<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { useToast } from "../../composables/useToast.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { CARRIER_PRESETS } from "../../utils/carrierPresets.js";
import {
  WHOLESALE_SHIPPING_LABELS_PROVIDER_OPTIONS,
  wholesaleShippingLabelsProviderLabel,
} from "../../utils/formatWholesaleOrderDisplay.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderId: { type: [String, Number], required: true },
  provider: { type: String, default: "" },
  comment: { type: String, default: "" },
  shippingAddress: { type: Object, default: () => ({}) },
  shippingCarrier: { type: String, default: "" },
  shippingMethod: { type: String, default: "" },
  hasShippingLabelFile: { type: Boolean, default: false },
  shippingLabelOriginalName: { type: String, default: "" },
});

const emit = defineEmits(["update:open", "saved", "close"]);

const toast = useToast();

const CARRIER_LIST = CARRIER_PRESETS;
const METHOD_PRESETS = ["Select", "Ground", "Priority", "Express", "Standard", "A124"];
const METHOD_OPTIONS_BY_CARRIER = {
  cheapest: ["Select", "Ground", "Priority", "Express", "Standard", "A124"],
  ups: ["Ground", "3 Day Select", "2nd Day Air", "Next Day Air Saver", "Next Day Air", "Standard", "Priority", "Express"],
  fedex: [
    "Ground",
    "Home Delivery",
    "Express Saver",
    "2Day",
    "Standard Overnight",
    "Priority Overnight",
    "International Priority",
    "International Economy",
  ],
  usps: ["First Class", "Priority Mail", "Priority Mail Express", "Parcel Select Ground", "Media Mail", "Ground"],
  dhl: ["Express Worldwide", "Express 12", "Express 9", "Express Easy"],
  asendia_one: ["Select", "Ground", "Priority", "Express", "Standard"],
  ontrac: ["Ground", "Express"],
  lasership: ["Select", "Ground", "Next Day"],
};

const providerDraft = ref("");
const commentDraft = ref("");
const addressDraft = ref(emptyAddress());
const carrierDraft = ref("");
const methodDraft = ref("");
const labelFile = ref(null);
const fileInput = ref(null);
const uploadBusy = ref(false);

function emptyAddress() {
  return {
    first_name: "",
    last_name: "",
    company: "",
    address1: "",
    address2: "",
    city: "",
    state: "",
    zip: "",
    country: "US",
    email: "",
    phone: "",
  };
}

function carrierPresetKey(carrier) {
  return String(carrier || "").trim().toLowerCase();
}

function resolveCarrierPreset(carrier) {
  const raw = String(carrier || "").trim();
  if (!raw) return "";
  const key = carrierPresetKey(raw);
  for (const p of CARRIER_LIST) {
    if (carrierPresetKey(p) === key) return p;
  }
  return raw;
}

const carrierSelectOptions = computed(() => {
  const labels = new Map();
  for (const p of CARRIER_LIST) {
    labels.set(carrierPresetKey(p), p);
  }
  const cur = String(carrierDraft.value || "").trim();
  const curKey = carrierPresetKey(cur);
  if (curKey && !labels.has(curKey)) {
    labels.set(curKey, resolveCarrierPreset(cur));
  }
  return ["", ...labels.values()];
});

const methodSelectOptions = computed(() => {
  const key = carrierPresetKey(carrierDraft.value);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key] || METHOD_PRESETS;
  const cur = String(methodDraft.value || "").trim();
  const out = ["", ...baseList];
  if (cur && !baseList.includes(cur) && !out.includes(cur)) {
    out.push(cur);
  }
  return out;
});

const isClientProvides = computed(
  () => String(providerDraft.value || "") === "client_provides",
);
const isSaveRackProvides = computed(
  () => String(providerDraft.value || "") === "save_rack_provides",
);

const drawerBusy = computed(() => props.busy || uploadBusy.value);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    providerDraft.value = String(props.provider || "");
    commentDraft.value = String(props.comment || "");
    const src = props.shippingAddress && typeof props.shippingAddress === "object"
      ? props.shippingAddress
      : {};
    addressDraft.value = {
      ...emptyAddress(),
      first_name: String(src.first_name || ""),
      last_name: String(src.last_name || ""),
      company: String(src.company || ""),
      address1: String(src.address1 || ""),
      address2: String(src.address2 || ""),
      city: String(src.city || ""),
      state: String(src.state || ""),
      zip: String(src.zip || ""),
      country: String(src.country || "US"),
      email: String(src.email || ""),
      phone: String(src.phone || ""),
    };
    carrierDraft.value = resolveCarrierPreset(props.shippingCarrier);
    methodDraft.value = String(props.shippingMethod || "");
    labelFile.value = null;
    if (fileInput.value) fileInput.value.value = "";
  },
);

watch(carrierDraft, (newCar, oldCar) => {
  if (carrierPresetKey(newCar) === carrierPresetKey(oldCar)) return;
  const key = carrierPresetKey(newCar);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key];
  if (!baseList) return;
  const m = String(methodDraft.value || "").trim();
  if (m !== "" && !baseList.includes(m)) {
    methodDraft.value = "";
  }
});

function close() {
  if (drawerBusy.value) return;
  emit("update:open", false);
  emit("close");
}

function onFileChange(event) {
  labelFile.value = event.target.files?.[0] || null;
}

function onDrop(event) {
  event.preventDefault();
  const file = event.dataTransfer?.files?.[0];
  if (file) labelFile.value = file;
}

function onDragOver(event) {
  event.preventDefault();
}

function addressRequiredFieldsFilled() {
  const a = addressDraft.value;
  return ["first_name", "last_name", "address1", "city", "state", "zip", "country"].every(
    (key) => String(a[key] || "").trim() !== "",
  );
}

function canSubmit() {
  if (!String(providerDraft.value || "").trim()) return false;
  if (isClientProvides.value) {
    return Boolean(labelFile.value) || props.hasShippingLabelFile;
  }
  if (isSaveRackProvides.value) {
    const carrier = String(carrierDraft.value || "").trim();
    const method = String(methodDraft.value || "").trim();
    return (
      addressRequiredFieldsFilled()
      && carrier !== ""
      && method !== ""
      && method.toLowerCase() !== "select"
    );
  }
  return false;
}

async function submit() {
  if (!canSubmit() || drawerBusy.value) return;
  uploadBusy.value = true;
  try {
    const payload = {
      shipping_labels_provider: providerDraft.value,
      shipping_labels_comment: commentDraft.value.trim() || null,
    };
    if (isSaveRackProvides.value) {
      payload.shipping_address = { ...addressDraft.value };
      payload.shipping_carrier = carrierDraft.value || null;
      payload.shipping_method = methodDraft.value || null;
    }
    const { data } = await api.patch(`/admin/wholesale-orders/${props.orderId}`, payload);
    let result = data;
    if (isClientProvides.value && labelFile.value) {
      const fd = new FormData();
      fd.append("shipping_label", labelFile.value);
      const uploadRes = await api.post(
        `/admin/wholesale-orders/${props.orderId}/shipping-label`,
        fd,
        { headers: { "Content-Type": "multipart/form-data" } },
      );
      result = uploadRes.data;
    }
    emit("saved", result);
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not save shipping labels.");
  } finally {
    uploadBusy.value = false;
  }
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Shipping Labels"
    subtitle="Choose who provides shipping labels and complete the required details."
    :busy="drawerBusy"
    form-id="wholesale-shipping-labels-form"
    max-width="2xl"
    @update:open="(v) => { emit('update:open', v); if (!v) emit('close'); }"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="form-label small text-secondary" for="wholesale-shipping-labels-provider">
          Shipping Labels <span class="text-danger">*</span>
        </label>
        <select
          id="wholesale-shipping-labels-provider"
          v-model="providerDraft"
          class="form-select"
          required
          :disabled="drawerBusy"
        >
          <option value="">Select an option</option>
          <option
            v-for="opt in WHOLESALE_SHIPPING_LABELS_PROVIDER_OPTIONS"
            :key="opt.value"
            :value="opt.value"
          >
            {{ opt.label }}
          </option>
        </select>
      </div>

      <div>
        <label class="form-label small text-secondary" for="wholesale-shipping-labels-comment">
          Comments (Optional)
        </label>
        <textarea
          id="wholesale-shipping-labels-comment"
          v-model="commentDraft"
          class="form-control"
          rows="3"
          placeholder="Enter any additional comments..."
          :disabled="drawerBusy"
        />
      </div>

      <template v-if="isClientProvides">
        <div>
          <label class="form-label small text-secondary">Upload shipping labels</label>
          <div
            class="wholesale-shipping-label-upload"
            @drop="onDrop"
            @dragover="onDragOver"
          >
            <CrmMaterialIcon name="description" :size="28" class="text-secondary mb-2" />
            <p class="small mb-2 text-secondary">
              Drag and drop a PDF or image here, or click to browse.
            </p>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              :disabled="drawerBusy"
              @click="fileInput?.click()"
            >
              Choose File
            </button>
            <input
              ref="fileInput"
              type="file"
              class="d-none"
              accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"
              :disabled="drawerBusy"
              @change="onFileChange"
            />
          </div>
          <p v-if="labelFile" class="small text-body mb-0 mt-2">
            Selected: {{ labelFile.name }}
          </p>
          <p v-else-if="hasShippingLabelFile && shippingLabelOriginalName" class="small text-body mb-0 mt-2">
            Current file: {{ shippingLabelOriginalName }}
          </p>
          <p class="form-text mb-0">Upload a PDF or image shipping label.</p>
        </div>
      </template>

      <template v-if="isSaveRackProvides">
        <h3 class="h6 fw-semibold text-body mb-0">Shipping Address</h3>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-first">First Name</label>
            <input id="wsl-first" v-model="addressDraft.first_name" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-last">Last Name</label>
            <input id="wsl-last" v-model="addressDraft.last_name" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-12">
            <label class="form-label small text-secondary" for="wsl-company">Company</label>
            <input id="wsl-company" v-model="addressDraft.company" type="text" class="form-control" :disabled="drawerBusy" />
          </div>
          <div class="col-12">
            <label class="form-label small text-secondary" for="wsl-a1">Address</label>
            <input id="wsl-a1" v-model="addressDraft.address1" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-12">
            <label class="form-label small text-secondary" for="wsl-a2">Address 2</label>
            <input id="wsl-a2" v-model="addressDraft.address2" type="text" class="form-control" :disabled="drawerBusy" />
          </div>
          <div class="col-md-4">
            <label class="form-label small text-secondary" for="wsl-city">City</label>
            <input id="wsl-city" v-model="addressDraft.city" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-md-4">
            <label class="form-label small text-secondary" for="wsl-state">State</label>
            <input id="wsl-state" v-model="addressDraft.state" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-md-4">
            <label class="form-label small text-secondary" for="wsl-zip">Zip</label>
            <input id="wsl-zip" v-model="addressDraft.zip" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-country">Country</label>
            <input id="wsl-country" v-model="addressDraft.country" type="text" class="form-control" required :disabled="drawerBusy" />
          </div>
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-email">Email (Optional)</label>
            <input id="wsl-email" v-model="addressDraft.email" type="email" class="form-control" :disabled="drawerBusy" />
          </div>
          <div class="col-12">
            <label class="form-label small text-secondary" for="wsl-phone">Phone (Optional)</label>
            <input id="wsl-phone" v-model="addressDraft.phone" type="text" class="form-control" :disabled="drawerBusy" />
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-carrier">Shipping Carrier</label>
            <select id="wsl-carrier" v-model="carrierDraft" class="form-select" required :disabled="drawerBusy">
              <option v-for="c in carrierSelectOptions" :key="'c-' + (c || 'empty')" :value="c">
                {{ c === "" ? "—" : c }}
              </option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small text-secondary" for="wsl-method">Method</label>
            <select id="wsl-method" v-model="methodDraft" class="form-select" required :disabled="drawerBusy">
              <option v-for="m in methodSelectOptions" :key="'m-' + (m || 'empty')" :value="m">
                {{ m === "" ? "—" : m }}
              </option>
            </select>
          </div>
        </div>
      </template>
    </div>

    <template #footer>
      <div :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="drawerBusy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="wholesale-shipping-labels-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="drawerBusy || !canSubmit()"
        >
          {{ drawerBusy ? "Saving…" : "Save" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>

<style scoped>
.wholesale-shipping-label-upload {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 1.5rem 1rem;
  border: 1px dashed var(--bs-border-color);
  border-radius: 0.5rem;
  background: var(--bs-body-bg);
  text-align: center;
}
</style>
