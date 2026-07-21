<script setup>
import { computed, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const CATEGORIES = [
  { value: "fulfillment", label: "Fulfillment" },
  { value: "returns", label: "Returns" },
  { value: "storage", label: "Storage" },
  { value: "receiving", label: "Receiving" },
  { value: "custom_work", label: "Custom Work" },
  { value: "wholesale", label: "Wholesale" },
  { value: "packaging", label: "Packaging" },
  { value: "amazon", label: "Amazon" },
  { value: "postage", label: "Postage" },
];

const FORM_ID = "pricing-fee-form";

const props = defineProps({
  open: { type: Boolean, default: false },
  fee: { type: Object, default: null },
  saving: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save", "delete", "update:open"]);

const name = ref("");
const description = ref("");
const category = ref("fulfillment");
const amount = ref("");
const cost = ref("");
const iconFile = ref(null);
const iconPreview = ref(null);
const removeIcon = ref(false);

const isEdit = computed(() => props.fee != null && props.fee.id != null);
const title = computed(() => (isEdit.value ? "Edit Fee" : "Create Fee"));
const isStorageCategory = computed(
  () => String(category.value || "").toLowerCase() === "storage",
);
const amountStep = computed(() => (isStorageCategory.value ? "0.001" : "0.01"));

const amountValid = computed(() => {
  const raw = String(amount.value ?? "").trim();
  if (raw === "") return false;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0;
});

const costValid = computed(() => {
  const raw = String(cost.value ?? "").trim();
  if (raw === "") return true;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0;
});

const canSubmit = computed(
  () => name.value.trim() !== "" && amountValid.value && costValid.value,
);

function formatAmountForInput(value, cat) {
  if (value == null || value === "") return "";
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  if (String(cat || "").toLowerCase() === "storage") {
    return n.toFixed(3);
  }
  const fixed = n.toFixed(4).replace(/\.?0+$/, "");
  return fixed === "-0" ? "0" : fixed;
}

function resetForm() {
  const f = props.fee;
  name.value = f?.name != null ? String(f.name) : "";
  description.value = f?.description != null ? String(f.description) : "";
  category.value = f?.category != null ? String(f.category) : "fulfillment";
  amount.value =
    f?.amount != null && f.amount !== ""
      ? formatAmountForInput(f.amount, category.value)
      : "";
  cost.value =
    f?.cost != null && f.cost !== ""
      ? formatAmountForInput(f.cost, category.value)
      : "";
  iconFile.value = null;
  iconPreview.value = f?.icon_url != null ? resolvePublicUrl(String(f.icon_url)) : null;
  removeIcon.value = false;
}

watch(
  () => [props.open, props.fee],
  () => {
    if (props.open) {
      resetForm();
    }
  },
  { immediate: true },
);

function onIconChange(event) {
  const file = event.target?.files?.[0];
  iconFile.value = file ?? null;
  removeIcon.value = false;
  if (file) {
    iconPreview.value = URL.createObjectURL(file);
  } else if (props.fee?.icon_url) {
    iconPreview.value = resolvePublicUrl(props.fee.icon_url);
  } else {
    iconPreview.value = null;
  }
}

function clearIcon() {
  iconFile.value = null;
  iconPreview.value = null;
  removeIcon.value = true;
}

function submit() {
  if (!canSubmit.value) return;
  const costRaw = String(cost.value ?? "").trim();
  emit("save", {
    name: name.value.trim(),
    description: description.value.trim(),
    category: category.value,
    amount: amount.value,
    cost: costRaw === "" ? null : costRaw,
    icon: iconFile.value,
    remove_icon: removeIcon.value,
  });
}

function close() {
  if (props.saving) return;
  emit("update:open", false);
  emit("close");
}
</script>

<template>
  <CrmRightDrawer
    v-if="!isEdit"
    :open="open"
    :title="title"
    :busy="saving"
    :form-id="FORM_ID"
    @update:open="(v) => { if (!v) close(); else emit('update:open', v); }"
    @submit="submit"
  >
    <div class="mb-3">
      <label class="form-label" for="pricing-fee-name">Name</label>
      <input
        id="pricing-fee-name"
        v-model="name"
        type="text"
        class="form-control"
        required
        maxlength="255"
        :disabled="saving"
      />
    </div>
    <div class="mb-3">
      <label class="form-label" for="pricing-fee-description">Description</label>
      <textarea
        id="pricing-fee-description"
        v-model="description"
        class="form-control"
        rows="3"
        :disabled="saving"
      />
    </div>
    <div class="mb-3">
      <label class="form-label" for="pricing-fee-category">Category</label>
      <select
        id="pricing-fee-category"
        v-model="category"
        class="form-select"
        :disabled="saving"
      >
        <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">
          {{ c.label }}
        </option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label" for="pricing-fee-amount">Price</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input
          id="pricing-fee-amount"
          v-model="amount"
          type="number"
          :step="amountStep"
          min="0"
          class="form-control"
          required
          :disabled="saving"
        />
      </div>
      <p v-if="isStorageCategory" class="small text-secondary mt-1 mb-0">
        Storage prices use 3 decimal places (e.g. 0.023).
      </p>
    </div>
    <div class="mb-3">
      <label class="form-label" for="pricing-fee-cost">Cost</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input
          id="pricing-fee-cost"
          v-model="cost"
          type="number"
          :step="amountStep"
          min="0"
          class="form-control"
          :disabled="saving"
        />
      </div>
      <p class="small text-secondary mt-1 mb-0">
        Admin-only. Applied as the default cost for all accounts (overrides preserved).
      </p>
    </div>
    <div class="mb-0">
      <label class="form-label" for="pricing-fee-icon">Icon</label>
      <input
        id="pricing-fee-icon"
        type="file"
        class="form-control"
        accept="image/jpeg,image/png,image/webp"
        :disabled="saving"
        @change="onIconChange"
      />
      <p class="small text-secondary mt-1 mb-2">Optional. JPG, PNG, or WebP.</p>
      <div v-if="iconPreview" class="d-flex align-items-center gap-3">
        <img
          :src="iconPreview"
          alt=""
          class="rounded border"
          style="width: 48px; height: 48px; object-fit: contain"
        />
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :disabled="saving"
          @click="clearIcon"
        >
          Remove Icon
        </button>
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="saving" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          :form="FORM_ID"
          :class="CRM_BTN_PRIMARY"
          :disabled="saving || !canSubmit"
        >
          {{ saving ? "Saving…" : "Create Fee" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>

  <Teleport v-else to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pricing-fee-modal-title"
        @click.self="close"
      >
        <div class="crm-vx-modal crm-vx-modal--pricing-fee" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="saving"
            @click="close"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 id="pricing-fee-modal-title" class="crm-vx-modal__title">
              {{ title }}
            </h2>
          </header>

          <div class="crm-vx-modal__body text-start">
            <form :id="FORM_ID" @submit.prevent="submit">
              <div class="mb-3">
                <label class="form-label" for="pricing-fee-name-edit">Name</label>
                <input
                  id="pricing-fee-name-edit"
                  v-model="name"
                  type="text"
                  class="form-control"
                  required
                  maxlength="255"
                  :disabled="saving"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="pricing-fee-description-edit">Description</label>
                <textarea
                  id="pricing-fee-description-edit"
                  v-model="description"
                  class="form-control"
                  rows="3"
                  :disabled="saving"
                />
              </div>
              <div class="mb-3">
                <label class="form-label" for="pricing-fee-category-edit">Category</label>
                <select
                  id="pricing-fee-category-edit"
                  v-model="category"
                  class="form-select"
                  :disabled="saving"
                >
                  <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">
                    {{ c.label }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="pricing-fee-amount-edit">Price</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input
                    id="pricing-fee-amount-edit"
                    v-model="amount"
                    type="number"
                    :step="amountStep"
                    min="0"
                    class="form-control"
                    required
                    :disabled="saving"
                  />
                </div>
                <p v-if="isStorageCategory" class="small text-secondary mt-1 mb-0">
                  Storage prices use 3 decimal places (e.g. 0.023).
                </p>
              </div>
              <div class="mb-3">
                <label class="form-label" for="pricing-fee-cost-edit">Cost</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input
                    id="pricing-fee-cost-edit"
                    v-model="cost"
                    type="number"
                    :step="amountStep"
                    min="0"
                    class="form-control"
                    :disabled="saving"
                  />
                </div>
                <p class="small text-secondary mt-1 mb-0">
                  Admin-only. Applied as the default cost for all accounts (overrides preserved).
                </p>
              </div>
              <div class="mb-0">
                <label class="form-label" for="pricing-fee-icon-edit">Icon</label>
                <input
                  id="pricing-fee-icon-edit"
                  type="file"
                  class="form-control"
                  accept="image/jpeg,image/png,image/webp"
                  :disabled="saving"
                  @change="onIconChange"
                />
                <p class="small text-secondary mt-1 mb-2">Optional. JPG, PNG, or WebP.</p>
                <div v-if="iconPreview" class="d-flex align-items-center gap-3">
                  <img
                    :src="iconPreview"
                    alt=""
                    class="rounded border"
                    style="width: 48px; height: 48px; object-fit: contain"
                  />
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="saving"
                    @click="clearIcon"
                  >
                    Remove Icon
                  </button>
                </div>
              </div>
            </form>
          </div>

          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-between align-items-center">
            <button
              v-if="canDelete"
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary text-danger border-danger-subtle"
              :disabled="saving"
              @click="emit('delete')"
            >
              Delete
            </button>
            <span v-else />
            <div class="d-flex gap-2 ms-auto">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                :form="FORM_ID"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saving || !canSubmit"
              >
                {{ saving ? "Saving…" : "Save Fee" }}
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
