<script setup>
import { computed, ref, watch } from "vue";
import Modal from "../Modal.vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast.js";
import { CRM_BTN_PRIMARY } from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String], default: null },
  line: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const qtyPicked = ref(0);

const qtyToPick = computed(() => Math.max(0, Number(props.line?.quantity || 0)));
const canSubmit = computed(() => {
  const picked = Number(qtyPicked.value);
  return (
    !saving.value &&
    Number.isFinite(picked) &&
    picked >= 0 &&
    picked <= qtyToPick.value &&
    props.orderId &&
    props.line?.id
  );
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    qtyPicked.value = Math.max(0, Number(props.line?.quantity_picked || 0));
  },
);

function close() {
  if (saving.value) return;
  emit("update:open", false);
}

function clampQty() {
  let n = Number(qtyPicked.value);
  if (!Number.isFinite(n) || n < 0) n = 0;
  if (n > qtyToPick.value) n = qtyToPick.value;
  qtyPicked.value = n;
}

function onQtyInput(e) {
  const raw = e?.target?.value;
  if (raw === "" || raw == null) {
    qtyPicked.value = 0;
    return;
  }
  let n = Number(raw);
  if (!Number.isFinite(n) || n < 0) n = 0;
  if (n > qtyToPick.value) n = qtyToPick.value;
  qtyPicked.value = n;
}

async function submit() {
  if (!canSubmit.value) return;
  clampQty();
  saving.value = true;
  try {
    await api.patch(
      `/admin/wholesale-orders/${props.orderId}/lines/${props.line.id}/pick`,
      { quantity_picked: qtyPicked.value },
    );
    emit("update:open", false);
    emit("saved");
  } catch (e) {
    toast.errorFrom(e, "Could not save picked quantity.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Modal :open="open" title="Pick Items" @close="close">
    <div v-if="line" class="wholesale-pick-modal">
      <div class="wholesale-pick-modal__product d-flex align-items-start gap-3 mb-4">
        <img
          v-if="line.image_url"
          :src="line.image_url"
          alt=""
          class="wholesale-pick-modal__thumb"
          loading="lazy"
        />
        <div v-else class="wholesale-pick-modal__thumb wholesale-pick-modal__thumb--empty" aria-hidden="true" />
        <div class="min-w-0">
          <div class="fw-semibold text-truncate">{{ line.name || "—" }}</div>
          <div class="small text-secondary">{{ line.sku || "—" }}</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label text-secondary small mb-1">QTY to Pick</label>
        <div class="wholesale-pick-qty wholesale-pick-qty--target fw-semibold">
          {{ qtyToPick.toLocaleString() }}
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label" for="wholesale-pick-qty-input">QTY Picked</label>
        <input
          id="wholesale-pick-qty-input"
          type="number"
          min="0"
          :max="qtyToPick"
          class="form-control"
          :value="qtyPicked"
          :disabled="saving"
          @input="onQtyInput"
          @blur="clampQty"
        />
      </div>

      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="close">
          Cancel
        </button>
        <button
          type="button"
          class="btn"
          :class="CRM_BTN_PRIMARY"
          :disabled="!canSubmit"
          @click="submit"
        >
          {{ saving ? "Saving…" : "Submit" }}
        </button>
      </div>
    </div>
  </Modal>
</template>
