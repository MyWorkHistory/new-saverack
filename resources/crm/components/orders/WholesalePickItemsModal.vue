<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String], default: null },
  line: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "saved"]);

const toast = useToast();
const saving = ref(false);
const qtyPicked = ref(0);
const selectedPickLocation = ref("");

const qtyNeeded = computed(() => Math.max(0, Number(props.line?.quantity || 0)));

const pickLocationOptions = computed(() => {
  const fromArray = Array.isArray(props.line?.pick_locations)
    ? props.line.pick_locations.map((value) => String(value || "").trim()).filter(Boolean)
    : [];
  if (fromArray.length) return fromArray;

  const fallback = String(props.line?.pick_location || "").trim();
  return fallback ? [fallback] : [];
});

const canSelectPickLocation = computed(() => pickLocationOptions.value.length > 0);

const canSubmit = computed(() => {
  const picked = Number(qtyPicked.value);
  return (
    !saving.value &&
    Number.isFinite(picked) &&
    picked >= 0 &&
    picked <= qtyNeeded.value &&
    props.orderId &&
    props.line?.id &&
    (!canSelectPickLocation.value || Boolean(selectedPickLocation.value))
  );
});

function resetForm() {
  qtyPicked.value = Math.max(0, Number(props.line?.quantity_picked || 0));
  const options = pickLocationOptions.value;
  selectedPickLocation.value = options[0] || "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    resetForm();
  },
);

watch(
  () => props.line,
  () => {
    if (!props.open) return;
    resetForm();
  },
);

function close() {
  if (saving.value) return;
  emit("update:open", false);
}

function clampQty() {
  let n = Number(qtyPicked.value);
  if (!Number.isFinite(n) || n < 0) n = 0;
  if (n > qtyNeeded.value) n = qtyNeeded.value;
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
  if (n > qtyNeeded.value) n = qtyNeeded.value;
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
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay wholesale-pick-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="wholesale-pick-modal-title"
        @click.self="close"
      >
        <div class="crm-vx-modal wholesale-pick-modal" @click.stop>
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
            <h2 id="wholesale-pick-modal-title" class="crm-vx-modal__title">Pick Items</h2>
          </header>

          <div v-if="line" class="crm-vx-modal__body">
            <div class="wholesale-pick-modal__product">
              <img
                v-if="line.image_url"
                :src="line.image_url"
                alt=""
                class="wholesale-pick-modal__thumb"
                loading="lazy"
              />
              <div v-else class="wholesale-pick-modal__thumb wholesale-pick-modal__thumb--empty" aria-hidden="true" />
              <div class="min-w-0">
                <div class="wholesale-pick-modal__sku">{{ line.sku || "—" }}</div>
                <div class="wholesale-pick-modal__name">{{ line.name || "—" }}</div>
                <div v-if="line.variant_description" class="wholesale-pick-modal__variant">
                  {{ line.variant_description }}
                </div>
              </div>
            </div>

            <div class="wholesale-pick-modal__field">
              <p class="wholesale-pick-modal__field-label">Qty Needed</p>
              <p class="wholesale-pick-modal__field-value">{{ qtyNeeded.toLocaleString() }}</p>
            </div>

            <div class="wholesale-pick-modal__field">
              <p class="wholesale-pick-modal__field-label">Pick Location</p>
              <select
                v-model="selectedPickLocation"
                class="form-select form-select-sm wholesale-pick-modal__field-select"
                :disabled="saving || !canSelectPickLocation"
              >
                <option v-if="!pickLocationOptions.length" value="">—</option>
                <option v-for="location in pickLocationOptions" :key="location" :value="location">
                  {{ location }}
                </option>
              </select>
            </div>

            <div class="wholesale-pick-modal__field">
              <p class="wholesale-pick-modal__field-label">Qty Picked</p>
              <input
                id="wholesale-pick-qty-input"
                type="number"
                min="0"
                :max="qtyNeeded"
                class="form-control form-control-sm wholesale-pick-modal__field-input"
                :value="qtyPicked"
                :disabled="saving"
                @input="onQtyInput"
                @blur="clampQty"
              />
            </div>

            <button
              type="button"
              class="btn btn-primary crm-vx-modal-btn--primary wholesale-pick-modal__submit"
              :disabled="!canSubmit"
              @click="submit"
            >
              {{ saving ? "Saving…" : "Submit" }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
