<script setup>
import { reactive, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  recipient: { type: Object, default: null },
});

const emit = defineEmits(["close", "confirm"]);

const form = reactive({
  full_name: "",
  address1: "",
  address2: "",
  city: "",
  province: "",
  zip: "",
  country: "United States",
  email: "",
  phone: "",
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    const r = props.recipient || {};
    form.full_name = r.name || "";
    form.address1 = r.address1 || "";
    form.address2 = r.address2 || "";
    form.city = r.city || "";
    form.province = r.province || "";
    form.zip = r.zip || "";
    form.country = r.country || "United States";
    form.email = r.email || "";
    form.phone = r.phone || "";
  },
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onConfirm() {
  if (props.busy) return;
  emit("confirm", { ...form });
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="so-modal-overlay" role="dialog" aria-modal="true" @click.self="onClose">
      <div class="so-modal so-modal--form" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>
        <h2 class="so-modal__title mb-3">Edit Address</h2>

        <label class="form-label">Full Name</label>
        <input v-model="form.full_name" type="text" class="form-control mb-3" :disabled="busy">

        <label class="form-label">Address Line 1</label>
        <input v-model="form.address1" type="text" class="form-control mb-3" :disabled="busy">

        <label class="form-label">Address Line 2 (Optional)</label>
        <input v-model="form.address2" type="text" class="form-control mb-3" placeholder="Apartment, suite, unit, building, etc." :disabled="busy">

        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">City</label>
            <input v-model="form.city" type="text" class="form-control" :disabled="busy">
          </div>
          <div class="col-6">
            <label class="form-label">State / Province</label>
            <input v-model="form.province" type="text" class="form-control" :disabled="busy">
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">Postal / Zip Code</label>
            <input v-model="form.zip" type="text" class="form-control" :disabled="busy">
          </div>
          <div class="col-6">
            <label class="form-label">Country</label>
            <input v-model="form.country" type="text" class="form-control" :disabled="busy">
          </div>
        </div>

        <label class="form-label">Email</label>
        <input v-model="form.email" type="email" class="form-control mb-3" :disabled="busy">

        <label class="form-label">Phone Number</label>
        <input v-model="form.phone" type="text" class="form-control mb-3" :disabled="busy">

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-primary staff-page-primary fw-semibold" :disabled="busy" @click="onConfirm">
            {{ busy ? "Updating…" : "Update Address" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.so-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.so-modal {
  position: relative;
  width: 100%;
  max-width: 28rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal__close {
  position: absolute;
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 1.4rem;
}
.so-modal__title { margin: 0; font-size: 1.2rem; font-weight: 700; }
.so-modal__foot { display: flex; justify-content: flex-end; gap: 0.55rem; }
</style>
