<script setup>
const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  locationName: { type: String, default: "" },
  currentQty: { type: Number, default: 0 },
  quantity: { type: [String, Number], default: "" },
  reason: { type: String, default: "" },
  reasons: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "submit", "update:quantity", "update:reason"]);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm sid-edit-qty-modal" @click.stop>
        <button
          type="button"
          class="crm-vx-modal__close"
          aria-label="Close"
          :disabled="busy"
          @click="emit('close')"
        >
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <header class="crm-vx-modal__head sid-edit-qty-modal__head">
          <h2 class="crm-vx-modal__title">Edit QTY</h2>
          <p class="sid-edit-qty-modal__sub">Update the inventory quantity at this location.</p>
        </header>

        <div class="crm-vx-modal__body">
          <div class="sid-edit-qty-modal__info">
            <div>
              <div class="sid-edit-qty-modal__info-label">Location</div>
              <div class="sid-edit-qty-modal__info-loc">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
                <span>{{ locationName || "—" }}</span>
              </div>
            </div>
            <div class="sid-edit-qty-modal__info-right">
              <div class="sid-edit-qty-modal__info-label">Current QTY</div>
              <div class="sid-edit-qty-modal__info-qty">{{ Number(currentQty || 0).toLocaleString("en-US") }}</div>
            </div>
          </div>

          <label class="form-label" for="sid-edit-new-qty">New QTY</label>
          <input
            id="sid-edit-new-qty"
            class="form-control mb-3"
            type="number"
            min="0"
            placeholder="Enter new quantity"
            :value="quantity"
            :disabled="busy"
            @input="emit('update:quantity', $event.target.value)"
          >

          <label class="form-label" for="sid-edit-reason">Reason</label>
          <select
            id="sid-edit-reason"
            class="form-select"
            :value="reason"
            :disabled="busy"
            @change="emit('update:reason', $event.target.value)"
          >
            <option value="">Select reason</option>
            <option v-for="r in reasons" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <footer class="crm-vx-modal__footer justify-content-end">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="busy" @click="emit('close')">
            Cancel
          </button>
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="busy" @click="emit('submit')">
            {{ busy ? "Please Wait…" : "Save Changes" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sid-edit-qty-modal__head {
  text-align: left;
  padding-bottom: 0.35rem;
}
.sid-edit-qty-modal__sub {
  margin: 0.2rem 0 0;
  font-size: 0.875rem;
  color: #64748b;
}
.sid-edit-qty-modal__info {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.9rem 1rem;
  margin-bottom: 1.1rem;
  border-radius: 0.65rem;
  background: #eff6ff;
  border: 1px solid #dbeafe;
}
.sid-edit-qty-modal__info-label {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}
.sid-edit-qty-modal__info-loc {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 700;
  color: #0f172a;
}
.sid-edit-qty-modal__info-loc svg {
  color: #2563eb;
  flex-shrink: 0;
}
.sid-edit-qty-modal__info-right {
  text-align: right;
}
.sid-edit-qty-modal__info-qty {
  font-size: 1.45rem;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.1;
}
</style>
